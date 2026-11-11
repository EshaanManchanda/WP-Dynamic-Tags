<?php
/**
 * The compatibility window for the legacy dynamic_tag posts (all of 3.x):
 *
 *  - capture: a write to a tag post (save, trash, delete, group change) is carried into the table through the migration
 *    planner, idempotently, and only when the post is newer than its row (a stale mirror post never overwrites a newer edit
 *    made in the Table Manager). The post <-> table Bridge that used to do this, in both directions, is no longer started.
 *  - loader: while a published tag post has no row yet, its shortcode still works (read-only, rendered through the one
 *    pipeline), so tags do not vanish between the update and the migration. The flag option wp_dynamic_tags_legacy_pending
 *    keeps the extra query off normal requests.
 */
class DT_Legacy_Compat_Test extends WP_UnitTestCase
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'dynamic_tags';
    }

    private function row_for_post(int $post_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE post_id = %d", $post_id));
    }

    private function row_by_shortcode(string $code): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE shortcode = %s", $code));
    }

    private function tag_post(string $title, string $content = 'legacy content', string $status = 'publish'): int
    {
        return (int) wp_insert_post(array('post_type' => 'dynamic_tag', 'post_status' => $status, 'post_title' => $title, 'post_content' => $content));
    }

    /** A published tag post that no hook saw: it exists only in wp_posts, as after a 3.0 install. */
    private function silent_tag_post(string $title, string $content): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->posts, array(
            'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => current_time('mysql', true), 'post_content' => $content,
            'post_title' => $title, 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => sanitize_title($title),
            'post_type' => 'dynamic_tag', 'post_modified' => $now, 'post_modified_gmt' => current_time('mysql', true),
            'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '',
        ));
        return (int) $wpdb->insert_id;
    }

    // ------------------------------------------------------------------ capture

    public function test_saving_a_tag_post_creates_its_row_and_records_the_capture(): void
    {
        $id = $this->tag_post('Capture Probe', 'captured {site_name|upper}');

        $row = $this->row_for_post($id);
        $this->assertNotNull($row, 'the post must have a row');
        $this->assertSame('capture_probe', $row->shortcode);
        $this->assertSame('active', $row->status);
        $this->assertSame('captured {site_name|upper}', $row->content);

        $record = get_option('wp_dynamic_tags_legacy_writes');
        $this->assertIsArray($record);
        $this->assertGreaterThanOrEqual(1, (int) $record['count']);
        $this->assertSame($id, (int) $record['last_post_id']);
    }

    public function test_a_newer_post_updates_the_row_but_an_older_one_never_does(): void
    {
        global $wpdb;
        $id = $this->tag_post('Newer Probe', 'v1');
        $wpdb->update($this->table(), array('modified_date' => '2020-01-01 00:00:00'), array('post_id' => $id));

        wp_update_post(array('ID' => $id, 'post_content' => 'v2 from the post'));
        $this->assertSame('v2 from the post', $this->row_for_post($id)->content, 'a post newer than its row wins');

        // A later edit made in the Table Manager must survive a stale post being saved again.
        $wpdb->update($this->table(), array('content' => 'edited in the table', 'modified_date' => '2999-01-01 00:00:00'), array('post_id' => $id));
        wp_update_post(array('ID' => $id, 'post_content' => 'v3 stale post'));
        $this->assertSame('edited in the table', $this->row_for_post($id)->content, 'a post older than its row never overwrites it');
    }

    public function test_trashing_deactivates_the_row_and_deleting_unlinks_it(): void
    {
        $id = $this->tag_post('Trash Probe');
        $this->assertSame('active', $this->row_for_post($id)->status);

        wp_trash_post($id);
        $this->assertSame('inactive', $this->row_for_post($id)->status);

        wp_delete_post($id, true);
        $row = $this->row_by_shortcode('trash_probe');
        $this->assertNotNull($row, 'the row outlives its post');
        $this->assertSame('inactive', $row->status);
        $this->assertSame('0', (string) $row->post_id);
    }

    public function test_assigning_a_group_to_the_post_sets_the_rows_group(): void
    {
        $id = $this->tag_post('Group Probe');
        $term = wp_insert_term('Capture Group', 'tag_groups');

        wp_set_object_terms($id, array((int) $term['term_id']), 'tag_groups');

        $this->assertSame((string) $term['term_id'], (string) $this->row_for_post($id)->category_id);
    }

    public function test_the_migrations_own_cleanup_does_not_deactivate_migrated_rows(): void
    {
        $id = $this->tag_post('Cleanup Probe');
        $engine = WP_Dynamic_Tags_Migration_Engine::get_instance();
        $suspend = new ReflectionMethod($engine, 'suspend_legacy_hooks');
        $suspend->setAccessible(true);
        $restore = new ReflectionMethod($engine, 'restore_hooks');
        $restore->setAccessible(true);

        $removed = $suspend->invoke($engine);
        wp_delete_post($id, true);
        $restore->invoke($engine, $removed);

        $row = $this->row_by_shortcode('cleanup_probe');
        $this->assertSame('active', $row->status, 'cleanup deletes verified legacy posts; their rows must stay active');
    }

    // ------------------------------------------------------------------ read-only loader

    public function test_a_post_without_a_row_still_has_its_shortcode_while_the_flag_is_set(): void
    {
        $this->silent_tag_post('Loader Probe', 'loaded {site_name|upper}');
        update_option('wp_dynamic_tags_legacy_pending', '1');

        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $expected = 'loaded ' . strtoupper(get_bloginfo('name'));
        $this->assertSame($expected, do_shortcode('[loader_probe cache="false"]'));
        $this->assertSame($expected, do_shortcode('[dt tag="loader_probe"]'), 'the core [dt] finds it too');
        $this->assertNull($this->row_by_shortcode('loader_probe'), 'the loader is read-only: it creates no row');
    }

    public function test_the_loader_stays_off_when_the_flag_says_nothing_is_pending(): void
    {
        $this->silent_tag_post('Quiet Probe', 'quiet');
        update_option('wp_dynamic_tags_legacy_pending', '0');
        remove_shortcode('quiet_probe');

        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $this->assertFalse(shortcode_exists('quiet_probe'));
    }

    public function test_a_missing_flag_is_worked_out_once_and_stored(): void
    {
        $this->silent_tag_post('Flag Probe', 'flag');
        delete_option('wp_dynamic_tags_legacy_pending');

        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $this->assertSame('1', (string) get_option('wp_dynamic_tags_legacy_pending'), 'an unmigrated post exists');
        $this->assertTrue(shortcode_exists('flag_probe'));
    }

    public function test_the_loader_never_replaces_a_name_that_belongs_to_someone_else(): void
    {
        add_shortcode('loader_foreign', static fn() => 'FOREIGN');
        $this->silent_tag_post('Loader Foreign', 'ours');
        update_option('wp_dynamic_tags_legacy_pending', '1');

        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $this->assertSame('FOREIGN', do_shortcode('[loader_foreign]'));
        remove_shortcode('loader_foreign');
    }

    public function test_a_migrated_post_is_not_loaded_a_second_time(): void
    {
        $id = $this->tag_post('Migrated Probe', 'from the row');
        global $wpdb;
        $wpdb->update($this->table(), array('content' => 'from the row (edited)'), array('post_id' => $id));
        update_option('wp_dynamic_tags_legacy_pending', '1');

        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $this->assertSame('from the row (edited)', do_shortcode('[migrated_probe cache="false"]'));
    }
}
