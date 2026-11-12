<?php
/**
 * Unit tests for WP_Dynamic_Tags_Migration_Planner: the WordPress-free decision logic of the
 * posts -> table migration (what to do with each legacy post, how to name it, when it is safe).
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-migration-planner.php';

class MigrationPlannerTest extends TestCase {

    private const P = 'WP_Dynamic_Tags_Migration_Planner';

    // ------------------------------------------------------------------ helpers

    private function post(array $over = []): array {
        return $over + [
            'ID'       => 10,
            'title'    => 'Hello World',
            'content'  => 'Body',
            'excerpt'  => '',
            'status'   => 'publish',
            'date'     => '2026-01-01 10:00:00',
            'modified' => '2026-01-02 10:00:00',
            'meta'     => [],
            'group_ids' => [],
        ];
    }

    private function row(array $over = []): array {
        return $over + [
            'id'           => 1,
            'shortcode'    => 'other',
            'tag_name'     => 'Other',
            'post_id'      => 0,
            'status'       => 'active',
            'modified_date' => '2026-01-01 00:00:00',
            'category_id'  => 0,
            'content_md5'  => md5('different'),
        ];
    }

    private function index(array ...$rows): array {
        return ($this::P)::build_index($rows);
    }

    private function plan(array $post, array $rows = []): array {
        return ($this::P)::plan_post($post, ($this::P)::build_index($rows));
    }

    // ------------------------------------------------------------------ derive_shortcode

    public function test_derive_shortcode_matches_the_legacy_post_path(): void {
        $p = $this::P;
        $this->assertSame('hello_world', $p::derive_shortcode('Hello World'));
        $this->assertSame('teachers_name_123', $p::derive_shortcode('  Teachers Name 123  '));
        $this->assertSame('a_b', $p::derive_shortcode('a-b'));
        $this->assertSame('a_b', $p::derive_shortcode('a   b'));
        $this->assertSame('a_b_c', $p::derive_shortcode('__a__b__c__'));
    }

    public function test_derive_shortcode_never_returns_empty(): void {
        $p = $this::P;
        $this->assertSame('unnamed_tag', $p::derive_shortcode(''));
        $this->assertSame('unnamed_tag', $p::derive_shortcode('!!!'));
        $this->assertSame('unnamed_tag', $p::derive_shortcode('日本語'));
    }

    public function test_derive_shortcode_only_yields_characters_wordpress_accepts(): void {
        $p = $this::P;
        foreach (['<b>x</b>', 'a&b', 'a[b]c', "tab\there", 'a/b'] as $title) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $p::derive_shortcode($title), $title);
        }
    }

    // ------------------------------------------------------------------ status mapping

    public function test_status_mapping(): void {
        $p = $this::P;
        $this->assertSame('active', $p::status_for_post('publish'));
        foreach (['draft', 'pending', 'private', 'future'] as $s) {
            $this->assertSame('inactive', $p::status_for_post($s), $s);
        }
        foreach (['trash', 'auto-draft', 'inherit'] as $s) {
            $this->assertNull($p::status_for_post($s), $s);
        }
    }

    // ------------------------------------------------------------------ plan_post: already migrated

    public function test_post_already_linked_to_a_row_is_skipped(): void {
        $post = $this->post(['ID' => 10, 'modified' => '2026-01-02 10:00:00']);
        $row  = $this->row(['id' => 5, 'post_id' => 10, 'shortcode' => 'hello_world', 'tag_name' => 'Hello World',
            'modified_date' => '2026-01-03 00:00:00', 'content_md5' => md5('Body')]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('skip', $plan['action']);
        $this->assertSame('already_migrated', $plan['reason']);
        $this->assertSame(5, $plan['row_id']);
    }

    public function test_linked_row_is_updated_only_when_the_post_is_newer_and_differs(): void {
        $post = $this->post(['ID' => 10, 'content' => 'New body', 'modified' => '2026-02-01 00:00:00']);
        $row  = $this->row(['id' => 5, 'post_id' => 10, 'modified_date' => '2026-01-01 00:00:00', 'content_md5' => md5('Old body')]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('update', $plan['action']);
        $this->assertSame(5, $plan['row_id']);
        $this->assertSame('New body', $plan['data']['content']);
        $this->assertSame('2026-02-01 00:00:00', $plan['data']['modified_date'], 'modified_date must be set explicitly: the column auto-updates to NOW()');
    }

    public function test_a_newer_table_row_is_never_overwritten(): void {
        $post = $this->post(['ID' => 10, 'content' => 'Post body', 'modified' => '2026-01-02 00:00:00']);
        $row  = $this->row(['id' => 5, 'post_id' => 10, 'modified_date' => '2026-03-01 00:00:00', 'content_md5' => md5('Edited in table')]);
        $this->assertSame('skip', $this->plan($post, [$row])['action']);
    }

    public function test_a_tie_keeps_the_table_row(): void {
        $post = $this->post(['ID' => 10, 'content' => 'A', 'modified' => '2026-01-02 00:00:00']);
        $row  = $this->row(['id' => 5, 'post_id' => 10, 'modified_date' => '2026-01-02 00:00:00', 'content_md5' => md5('B')]);
        $this->assertSame('skip', $this->plan($post, [$row])['action']);
    }

    public function test_a_newer_post_with_identical_content_and_status_is_just_skipped(): void {
        $post = $this->post(['ID' => 10, 'content' => 'Same', 'modified' => '2026-05-01 00:00:00']);
        $row  = $this->row(['id' => 5, 'post_id' => 10, 'modified_date' => '2026-01-01 00:00:00', 'content_md5' => md5('Same'), 'status' => 'active']);
        $this->assertSame('skip', $this->plan($post, [$row])['action']);
    }

    // ------------------------------------------------------------------ plan_post: mirror rows created by the table path

    public function test_post_whose_dt_shortcode_meta_names_an_unlinked_row_is_linked(): void {
        $post = $this->post(['ID' => 10, 'title' => 'Teachers Name', 'meta' => ['_dt_shortcode' => 'cg_teachers_name_9'], 'modified' => '2026-01-01 00:00:00']);
        $row  = $this->row(['id' => 7, 'shortcode' => 'cg_teachers_name_9', 'tag_name' => 'Teachers Name 9', 'post_id' => 0,
            'modified_date' => '2026-02-01 00:00:00', 'content_md5' => md5('Body')]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('link', $plan['action']);
        $this->assertSame(7, $plan['row_id']);
        $this->assertSame('cg_teachers_name_9', $plan['shortcode']);
    }

    public function test_a_mirror_post_keeps_its_title_derived_name_as_a_legacy_alias(): void {
        $post = $this->post(['ID' => 10, 'title' => 'Teachers Name', 'meta' => ['_dt_shortcode' => 'cg_teachers_name_9']]);
        $row  = $this->row(['id' => 7, 'shortcode' => 'cg_teachers_name_9', 'tag_name' => 'x', 'post_id' => 10]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame(['teachers_name'], $plan['aliases'], 'the post path publicly registered [teachers_name]; content may use it');
    }

    public function test_no_alias_when_the_derived_name_equals_the_row_shortcode(): void {
        $post = $this->post(['ID' => 10, 'title' => 'Hello World']);
        $row  = $this->row(['id' => 7, 'shortcode' => 'hello_world', 'tag_name' => 'Hello World', 'post_id' => 10, 'modified_date' => '2030-01-01 00:00:00', 'content_md5' => md5('Body')]);
        $this->assertSame([], $this->plan($post, [$row])['aliases']);
    }

    public function test_dt_shortcode_row_already_linked_to_another_post_is_a_conflict(): void {
        $post = $this->post(['ID' => 10, 'meta' => ['_dt_shortcode' => 'dup']]);
        $row  = $this->row(['id' => 7, 'shortcode' => 'dup', 'post_id' => 99]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('conflict', $plan['action']);
        $this->assertSame('shortcode_linked_to_other_post', $plan['reason']);
    }

    // ------------------------------------------------------------------ plan_post: post-native tags

    public function test_post_native_tag_is_inserted_under_its_title_derived_shortcode(): void {
        $post = $this->post(['ID' => 10, 'title' => 'My New Tag', 'content' => 'X', 'excerpt' => 'desc', 'status' => 'publish',
            'meta' => ['_dt_priority' => '40', '_dt_usage_count' => '7', '_dt_last_used' => '2026-01-05 12:00:00', 'foo' => 'bar'],
            'group_ids' => [3, 4]]);
        $plan = $this->plan($post, [$this->row()]);
        $this->assertSame('insert', $plan['action']);
        $this->assertSame('my_new_tag', $plan['shortcode']);
        $d = $plan['data'];
        $this->assertSame('My New Tag', $d['tag_name']);
        $this->assertSame('my_new_tag', $d['shortcode']);
        $this->assertSame('X', $d['content']);
        $this->assertSame('desc', $d['description']);
        $this->assertSame('active', $d['status']);
        $this->assertSame(3, $d['category_id'], 'the table holds one group: the first');
        $this->assertSame(40, $d['priority']);
        $this->assertSame(7, $d['usage_count']);
        $this->assertSame('2026-01-05 12:00:00', $d['last_used_date']);
        $this->assertSame(10, $d['post_id']);
        $this->assertSame('2026-01-01 10:00:00', $d['created_date']);
        $this->assertSame('2026-01-02 10:00:00', $d['modified_date']);
        $this->assertSame('bar', $d['meta_data']['foo']);
        $this->assertSame([3, 4], $d['meta_data']['legacy']['group_ids'], 'no group information may be lost');
        $this->assertSame(10, $d['meta_data']['legacy']['post_id']);
    }

    public function test_draft_posts_become_inactive_rows(): void {
        $plan = $this->plan($this->post(['status' => 'draft']));
        $this->assertSame('insert', $plan['action']);
        $this->assertSame('inactive', $plan['data']['status']);
    }

    public function test_internal_wordpress_meta_is_not_copied_into_the_row(): void {
        $post = $this->post(['meta' => ['_edit_lock' => '1:2', '_edit_last' => '1', '_wp_old_slug' => 'x', '_dt_tag_name' => 'n', 'keep_me' => 'y']]);
        $meta = $this->plan($post)['data']['meta_data'];
        $this->assertSame('y', $meta['keep_me']);
        foreach (['_edit_lock', '_edit_last', '_wp_old_slug', '_dt_tag_name'] as $k) {
            $this->assertArrayNotHasKey($k, $meta, $k);
        }
    }

    public function test_priority_is_clamped_and_usage_is_never_negative(): void {
        $d = $this->plan($this->post(['meta' => ['_dt_priority' => '500', '_dt_usage_count' => '-3']]))['data'];
        $this->assertSame(100, $d['priority']);
        $this->assertSame(0, $d['usage_count']);
        $d = $this->plan($this->post(['meta' => ['_dt_priority' => 'abc']]))['data'];
        $this->assertSame(0, $d['priority']);
    }

    public function test_zero_dates_fall_back_to_a_real_date(): void {
        $d = $this->plan($this->post(['date' => '0000-00-00 00:00:00', 'modified' => '2026-04-01 00:00:00']))['data'];
        $this->assertSame('2026-04-01 00:00:00', $d['created_date']);
        $d = $this->plan($this->post(['meta' => ['_dt_last_used' => '0000-00-00 00:00:00']]))['data'];
        $this->assertNull($d['last_used_date']);
    }

    public function test_empty_title_gets_a_placeholder_name(): void {
        $plan = $this->plan($this->post(['ID' => 42, 'title' => '']));
        $this->assertSame('insert', $plan['action']);
        $this->assertSame('Untitled tag 42', $plan['data']['tag_name']);
        $this->assertSame('unnamed_tag', $plan['shortcode']);
    }

    // ------------------------------------------------------------------ plan_post: conflicts

    public function test_a_different_row_owning_the_derived_shortcode_is_a_conflict(): void {
        $post = $this->post(['title' => 'Hello World']);
        $row  = $this->row(['id' => 3, 'shortcode' => 'hello_world', 'tag_name' => 'Totally different', 'post_id' => 0]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('conflict', $plan['action']);
        $this->assertSame('shortcode_taken', $plan['reason']);
        $this->assertSame(3, $plan['row_id']);
    }

    public function test_an_identical_unlinked_row_is_linked_instead_of_duplicated(): void {
        $post = $this->post(['title' => 'Hello World', 'content' => 'Body']);
        $row  = $this->row(['id' => 3, 'shortcode' => 'hello_world', 'tag_name' => 'Hello World', 'post_id' => 0, 'content_md5' => md5('Body')]);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('link', $plan['action']);
        $this->assertSame('identical_row_exists', $plan['reason']);
    }

    public function test_tag_name_owned_by_another_row_is_a_conflict(): void {
        $post = $this->post(['title' => 'Hello World']);
        $row  = $this->row(['id' => 3, 'shortcode' => 'something_else', 'tag_name' => 'Hello World']);
        $plan = $this->plan($post, [$row]);
        $this->assertSame('conflict', $plan['action']);
        $this->assertSame('tag_name_taken', $plan['reason']);
    }

    public function test_uniqueness_is_case_insensitive_like_the_database_collation(): void {
        $post = $this->post(['title' => 'Hello World']);
        $this->assertSame('shortcode_taken', $this->plan($post, [$this->row(['shortcode' => 'HELLO_WORLD'])])['reason']);
        $this->assertSame('tag_name_taken', $this->plan($post, [$this->row(['shortcode' => 'zzz', 'tag_name' => 'hello world'])])['reason']);
    }

    public function test_over_long_names_are_a_conflict_not_a_truncation(): void {
        $plan = $this->plan($this->post(['title' => str_repeat('a', 256)]));
        $this->assertSame('conflict', $plan['action']);
        $this->assertSame('name_too_long', $plan['reason']);
    }

    public function test_two_posts_deriving_the_same_shortcode_conflict_in_id_order(): void {
        $p     = $this::P;
        $index = $p::build_index([]);
        $first = $p::plan_post($this->post(['ID' => 1, 'title' => 'A b']), $index);
        $this->assertSame('insert', $first['action']);
        $p::remember($index, ['id' => 100, 'shortcode' => $first['shortcode'], 'tag_name' => $first['data']['tag_name'],
            'post_id' => 1, 'status' => 'active', 'modified_date' => '2026-01-01 00:00:00', 'category_id' => 0, 'content_md5' => md5('Body')]);
        $second = $p::plan_post($this->post(['ID' => 2, 'title' => 'A-b']), $index);
        $this->assertSame('conflict', $second['action'], 'lowest post ID wins; the later one is reported, never silently renamed');
        $this->assertSame('shortcode_taken', $second['reason']);
    }

    public function test_planning_does_not_mutate_the_index(): void {
        $p     = $this::P;
        $index = $p::build_index([$this->row()]);
        $copy  = $index;
        $p::plan_post($this->post(), $index);
        $this->assertSame($copy, $index);
    }

    public function test_excluded_post_status_is_skipped(): void {
        $plan = $this->plan($this->post(['status' => 'trash']));
        $this->assertSame('skip', $plan['action']);
        $this->assertSame('excluded_status', $plan['reason']);
    }

    // ------------------------------------------------------------------ rename resolution

    public function test_rename_resolution_appends_the_post_id(): void {
        [$shortcode, $name] = ($this::P)::rename_for_conflict('hello_world', 'Hello World', 77);
        $this->assertSame('hello_world_77', $shortcode);
        $this->assertSame('Hello World (77)', $name);
    }

    public function test_rename_resolution_shortens_an_over_long_name_so_it_fits_the_column(): void {
        [$shortcode, $name] = ($this::P)::rename_for_conflict(str_repeat('a', 300), str_repeat('é', 300), 12345);
        $this->assertLessThanOrEqual(255, strlen($shortcode));
        $this->assertLessThanOrEqual(255, mb_strlen($name, 'UTF-8'));
        $this->assertStringEndsWith('_12345', $shortcode);
        $this->assertStringEndsWith(' (12345)', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'), 'truncation must not split a multibyte character');
    }

    public function test_legacy_record_captures_where_a_row_came_from(): void {
        $post = $this->post(['ID' => 10, 'title' => 'T', 'status' => 'draft', 'group_ids' => ['3', 4]]);
        $this->assertSame(
            ['post_id' => 10, 'title' => 'T', 'status' => 'draft', 'group_ids' => [3, 4], 'aliases' => ['t_alias']],
            ($this::P)::legacy_record($post, ['t_alias'])
        );
    }

    // ------------------------------------------------------------------ pair_mismatches (verification and cleanup safety)

    public function test_identical_pair_has_no_mismatch(): void {
        $post = $this->post(['content' => 'Body', 'status' => 'publish']);
        $row  = $this->row(['content_md5' => md5('Body'), 'status' => 'active']);
        $this->assertSame([], ($this::P)::pair_mismatches($post, $row, []));
    }

    public function test_pair_reports_content_status_and_group_differences(): void {
        $post = $this->post(['content' => 'Body', 'status' => 'publish', 'modified' => '2026-05-01 00:00:00']);
        $row  = $this->row(['content_md5' => md5('Old'), 'status' => 'inactive', 'category_id' => 9, 'modified_date' => '2026-01-01 00:00:00']);
        $m    = ($this::P)::pair_mismatches($post, $row, [3, 4]);
        sort($m);
        $this->assertSame(['content', 'group', 'status'], $m);
    }

    public function test_pair_differences_are_forgiven_when_the_table_is_newer(): void {
        $post = $this->post(['content' => 'Body', 'modified' => '2026-01-01 00:00:00']);
        $row  = $this->row(['content_md5' => md5('Edited'), 'status' => 'inactive', 'modified_date' => '2026-06-01 00:00:00']);
        $this->assertSame([], ($this::P)::pair_mismatches($post, $row, [1]));
    }

    public function test_group_matches_when_the_row_holds_any_of_the_post_groups(): void {
        $post = $this->post(['modified' => '2026-05-01 00:00:00']);
        $row  = $this->row(['content_md5' => md5('Body'), 'status' => 'active', 'category_id' => 4, 'modified_date' => '2026-01-01 00:00:00']);
        $this->assertSame([], ($this::P)::pair_mismatches($post, $row, [3, 4]));
    }

    // ------------------------------------------------------------------ verification rules

    private function facts(array $over = []): array {
        return $over + [
            'status' => 'completed', 'unprocessed' => 0, 'unlinked' => 0, 'mismatches' => 0, 'unregistered' => 0,
            'open_conflicts' => 0, 'backup_exists' => true, 'backup_sha_ok' => true, 'backup_counts_ok' => true,
        ];
    }

    public function test_verification_passes_when_every_check_passes(): void {
        $v = ($this::P)::evaluate_verification($this->facts());
        $this->assertTrue($v['passed']);
        $this->assertCount(5, $v['checks']);
    }

    /** @dataProvider failingFacts */
    public function test_verification_fails_on_each_single_problem(array $over, string $failedId): void {
        $v = ($this::P)::evaluate_verification($this->facts($over));
        $this->assertFalse($v['passed']);
        $failed = array_column(array_filter($v['checks'], fn($c) => !$c['passed']), 'id');
        $this->assertSame([$failedId], array_values($failed));
    }

    public static function failingFacts(): array {
        return [
            'migration not finished'  => [['status' => 'running'], 'processed'],
            'posts after the cursor'  => [['unprocessed' => 2], 'processed'],
            'post without a row'      => [['unlinked' => 1], 'processed'],
            'content/status/group'    => [['mismatches' => 3], 'content'],
            'shortcode not ours'      => [['unregistered' => 1], 'shortcodes'],
            'open conflict'           => [['open_conflicts' => 1], 'conflicts'],
            'backup missing'          => [['backup_exists' => false, 'backup_sha_ok' => false, 'backup_counts_ok' => false], 'backup'],
            'backup tampered'         => [['backup_sha_ok' => false], 'backup'],
            'backup counts differ'    => [['backup_counts_ok' => false], 'backup'],
        ];
    }

    // ------------------------------------------------------------------ lock

    public function test_lock_staleness(): void {
        $p = $this::P;
        $this->assertFalse($p::lock_is_stale(1000, 1100));
        $this->assertFalse($p::lock_is_stale(1000, 1300), 'exactly at the ttl is still held');
        $this->assertTrue($p::lock_is_stale(1000, 1301));
        $this->assertTrue($p::lock_is_stale(0, 5000), 'a corrupt lock timestamp counts as stale');
    }
}
