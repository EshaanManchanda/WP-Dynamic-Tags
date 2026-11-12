<?php
/**
 * Migration engine for WP Dynamic Tags: legacy `dynamic_tag` posts -> the custom table.
 *
 * Properties the rest of the plugin (and the release gates) rely on:
 *  - Resumable: work is done in batches of posts ordered by ID, with a cursor persisted after each batch.
 *  - Idempotent: every decision comes from WP_Dynamic_Tags_Migration_Planner, so running twice never
 *    duplicates a tag and never overwrites a row that was edited after its post.
 *  - Recoverable: a private, checksummed backup is written and validated before the first write, and
 *    the table snapshot can be restored (after an automatic safety backup of the current state).
 *  - Safe: legacy posts are deleted only by cleanup(), only after verify() passed on the current data,
 *    and each post is re-checked immediately before deletion.
 *  - Never on the frontend: nothing here is triggered by a visitor request (admin, WP-CLI or WP-Cron).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Migration_Engine
{
    public const OPTION_STATE   = 'wp_dynamic_tags_migration';
    public const OPTION_LOG     = 'wp_dynamic_tags_migration_log';
    public const OPTION_LOCK    = 'wp_dynamic_tags_migration_lock';
    public const OPTION_CLEANED = 'wp_dynamic_tags_posts_cleaned';
    public const CRON_HOOK      = 'dt_migration_cron';
    public const POST_TYPE      = 'dynamic_tag';
    public const TAXONOMY       = 'tag_groups';
    public const BACKUP_DIR     = 'wp-dynamic-tags-private';
    public const BACKUP_FORMAT  = 'wp-dynamic-tags-migration-backup';

    private const MAX_CONFLICTS = 1000;
    private const MAX_LOG       = 200;

    private static ?self $instance = null;

    private WP_Dynamic_Tags_Database_Manager $db;

    /** @var array<int,array{timestamp:string,type:string,message:string}> */
    private array $log_buffer = [];

    private ?bool $transactional = null;

    public function __construct()
    {
        $this->db = WP_Dynamic_Tags_Database_Manager::get_instance();
    }

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ================================================================== legacy-write capture (plan Phase 3)

    /** Count, last time and last post of the writes to legacy tag posts that were carried into the table (diagnostics). */
    public const OPTION_CAPTURE = 'wp_dynamic_tags_legacy_writes';
    /** '1' while a published legacy tag post has no row yet (the read-only loader runs), '0' otherwise. Autoloaded. */
    public const OPTION_PENDING = 'wp_dynamic_tags_legacy_pending';
    public const CAPTURE_HOOK   = 'dt_capture_post';

    /**
     * Hooks that carry writes to legacy tag posts into the table. They replace the post -> table half of the old Bridge, go
     * through the migration planner (idempotent; a post only overwrites a row it is newer than) and stay for all of 3.x.
     */
    public static function register_capture_hooks(): void
    {
        add_action('save_post_' . self::POST_TYPE, [self::class, 'handle_save'], 20, 2);
        add_action('wp_trash_post', [self::class, 'handle_trash'], 10, 1);
        add_action('before_delete_post', [self::class, 'handle_delete'], 10, 1);
        add_action('set_object_terms', [self::class, 'handle_terms'], 20, 4);
        add_action(self::CAPTURE_HOOK, [self::class, 'handle_scheduled'], 10, 1);
    }

    /** @param mixed $post */
    public static function handle_save($post_id, $post = null): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision((int) $post_id)) {
            return;
        }
        self::get_instance()->capture_post((int) $post_id);
    }

    public static function handle_scheduled($post_id): void
    {
        self::get_instance()->capture_post((int) $post_id);
    }

    public static function handle_trash($post_id): void
    {
        if (get_post_type((int) $post_id) === self::POST_TYPE) {
            self::get_instance()->capture_removal((int) $post_id, false);
        }
    }

    public static function handle_delete($post_id): void
    {
        if (get_post_type((int) $post_id) === self::POST_TYPE) {
            self::get_instance()->capture_removal((int) $post_id, true);
        }
    }

    public static function handle_terms($object_id, $terms = null, $tt_ids = null, $taxonomy = ''): void
    {
        if ($taxonomy === self::TAXONOMY && get_post_type((int) $object_id) === self::POST_TYPE) {
            self::get_instance()->capture_group((int) $object_id);
        }
    }

    /**
     * Carries one saved legacy post into the table, the way a migration step would (same planner, same rules). No
     * transaction: one post is a handful of statements, each of them idempotent.
     *
     * @return bool|WP_Error true when the post was looked at, false when postponed (a migration step holds the lock)
     */
    public function capture_post(int $post_id)
    {
        $token = $this->acquire_lock();
        if ($token === null) {
            if (!wp_next_scheduled(self::CAPTURE_HOOK, [$post_id])) {
                wp_schedule_single_event(time() + 30, self::CAPTURE_HOOK, [$post_id]);
            }
            return false;
        }
        try {
            $posts = $this->load_posts($post_id - 1, 1);
            if (!isset($posts[$post_id])) {
                return true; // not a legacy post (an auto-draft, the trash): nothing to carry over
            }
            $state = $this->get_state();
            $index = $this->load_row_index();
            try {
                $this->process_post($posts[$post_id], $index, $state);
            } catch (Throwable $e) {
                $this->log('Capture of post ' . $post_id . ' failed: ' . $e->getMessage(), 'error');
                return new WP_Error('capture_failed', $e->getMessage());
            }
            $this->save_state($state); // bumps the revision: a write after the last verification invalidates it
            $this->note_capture($post_id);
            $this->flush_table_caches();
            $this->refresh_pending_flag();
            return true;
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    /** The post went to the trash or was deleted: its row is switched off, never deleted (the row is the record). */
    public function capture_removal(int $post_id, bool $permanent): void
    {
        global $wpdb;
        $table  = $this->db->get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $row_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", $post_id));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (!$row_id) {
            return;
        }
        $data = ['status' => 'inactive'];
        if ($permanent) {
            $data['post_id'] = 0;
        }
        $wpdb->update($table, $data, ['id' => (int) $row_id]);
        $this->note_capture($post_id);
        $this->flush_table_caches();
        $this->refresh_pending_flag();
    }

    /** The group of the post changed: the row follows (a row has one group, the first one). */
    public function capture_group(int $post_id): void
    {
        global $wpdb;
        $table  = $this->db->get_table_name();
        $groups = WP_Dynamic_Tags_Plugin::post_terms($post_id, self::TAXONOMY, ['fields' => 'ids']);
        $wpdb->update($table, ['category_id' => $groups ? (int) $groups[0] : 0], ['post_id' => $post_id]);
        $this->flush_table_caches();
    }

    private function note_capture(int $post_id): void
    {
        $record = get_option(self::OPTION_CAPTURE, []);
        $record = is_array($record) ? $record : [];
        $record['count']        = (int) ($record['count'] ?? 0) + 1;
        $record['last_at']      = current_time('mysql');
        $record['last_post_id'] = $post_id;
        update_option(self::OPTION_CAPTURE, $record, false);
    }

    /**
     * Works out whether a legacy post still lacks a row and stores the answer (an autoloaded option, read on every request
     * by the legacy loader so that normal requests need no query). Called whenever that can change.
     */
    public function refresh_pending_flag(): bool
    {
        $pending = $this->count_unlinked($this->conflict_ids($this->get_state(), 'accepted')) > 0;
        update_option(self::OPTION_PENDING, $pending ? '1' : '0');
        return $pending;
    }

    // ================================================================== state

    /** @return array<string,mixed> */
    private function default_state(): array
    {
        return [
            'version'      => 1,
            'status'       => 'pending',
            'revision'     => 0,
            'started_at'   => null,
            'finished_at'  => null,
            'updated_at'   => null,
            'last_post_id' => 0,
            'total_posts'  => 0,
            'counters'     => ['inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => 0, 'orphans_fixed' => 0],
            'conflicts'    => [],
            'backup'       => null,
            'cleanup_backup' => null,
            'verification' => null,
            'cleanup'      => ['status' => 'idle', 'cursor' => 0, 'deleted' => 0, 'kept' => 0],
            'last_error'   => null,
        ];
    }

    /** @return array<string,mixed> */
    public function get_state(): array
    {
        $stored = get_option(self::OPTION_STATE, []);
        $stored = is_array($stored) ? $stored : [];
        $default = $this->default_state();

        $state             = array_merge($default, $stored);
        $state['counters'] = array_merge($default['counters'], is_array($state['counters']) ? $state['counters'] : []);
        $state['cleanup']  = array_merge($default['cleanup'], is_array($state['cleanup']) ? $state['cleanup'] : []);
        $state['conflicts'] = is_array($state['conflicts']) ? $state['conflicts'] : [];

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function save_state(array $state, bool $bump_revision = true): array
    {
        $state['updated_at'] = current_time('mysql');
        if ($bump_revision) {
            $state['revision'] = (int) $state['revision'] + 1;
        }
        $state['counters']['conflicts'] = $this->count_conflicts($state, 'open');
        update_option(self::OPTION_STATE, $state, false);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function count_conflicts(array $state, string $status): int
    {
        $n = 0;
        foreach ($state['conflicts'] as $conflict) {
            if (is_array($conflict) && ($conflict['status'] ?? '') === $status) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $state
     * @return int[]
     */
    private function conflict_ids(array $state, string $status): array
    {
        $ids = [];
        foreach ($state['conflicts'] as $post_id => $conflict) {
            if (is_array($conflict) && ($conflict['status'] ?? '') === $status) {
                $ids[] = (int) $post_id;
            }
        }
        return $ids;
    }

    /**
     * Look at every legacy post again (posts that appeared after the last run, for instance). Already
     * migrated posts are skipped by the planner, so this is cheap and changes nothing that is current.
     */
    public function rescan(): void
    {
        $state = $this->get_state();
        $state['status']       = 'pending';
        $state['last_post_id'] = 0;
        $state['finished_at']  = null;
        $this->save_state($state);
        $this->refresh_pending_flag();
        $this->log('Rescan requested.');
        $this->flush_log();
    }

    /** Forget all migration progress. Data (rows, posts, backups) is never touched. */
    public function reset_state(): void
    {
        delete_option(self::OPTION_STATE);
        delete_option(self::OPTION_CLEANED);
        $this->log('Migration state reset (no data was changed).');
        $this->flush_log();
    }

    // ================================================================== counting

    private function legacy_where(): string
    {
        return "p.post_type = '" . self::POST_TYPE . "' AND p.post_status NOT IN ('trash','auto-draft','inherit')";
    }

    public function count_legacy_posts(): int
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . $this->legacy_where());
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    private function count_after_cursor(int $cursor): int
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . $this->legacy_where() . ' AND p.ID > %d', $cursor));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /** @param int[] $exclude post IDs that need no row (conflicts the admin accepted) */
    private function count_unlinked(array $exclude): int
    {
        global $wpdb;
        $table = $this->db->get_table_name();
        $sql   = "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$table} t ON t.post_id = p.ID WHERE " . $this->legacy_where() . ' AND t.id IS NULL';
        if ($exclude) {
            $sql .= ' AND p.ID NOT IN (' . implode(',', array_map('intval', $exclude)) . ')';
        }
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        return (int) $wpdb->get_var($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    public function needs_migration(): bool
    {
        return $this->count_unlinked($this->conflict_ids($this->get_state(), 'accepted')) > 0;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state    = $this->get_state();
        $legacy   = $this->count_legacy_posts();
        $accepted = $this->conflict_ids($state, 'accepted');
        $unlinked = $legacy > 0 ? $this->count_unlinked($accepted) : 0;
        $after    = $legacy > 0 ? $this->count_after_cursor((int) $state['last_post_id']) : 0;
        $open     = $this->count_conflicts($state, 'open');

        $phase = (string) $state['status'];
        if ($legacy === 0 && $phase === 'pending') {
            $phase = 'idle';
        }

        $verification = is_array($state['verification']) ? $state['verification'] : null;
        $fresh        = $verification !== null && (int) ($verification['revision'] ?? -1) === (int) $state['revision'];

        $has_work = $unlinked > 0 && (in_array($state['status'], ['pending', 'running'], true) || $after > 0);

        return [
            'phase'               => $phase,
            'legacy_posts'        => $legacy,
            'unlinked'            => $unlinked,
            'remaining'           => $after,
            'has_work'            => $has_work,
            'needs_migration'     => $unlinked > 0,
            'open_conflicts'      => $open,
            'accepted_conflicts'  => count($accepted),
            'counters'            => $state['counters'],
            'last_post_id'        => (int) $state['last_post_id'],
            'total_posts'         => (int) $state['total_posts'],
            'started_at'          => $state['started_at'],
            'finished_at'         => $state['finished_at'],
            'last_error'          => $state['last_error'],
            'backup'              => $state['backup'],
            'verification'        => $verification,
            'verification_fresh'  => $fresh,
            // A legacy post that is not migrated yet (written after the verification) switches the offer off (M1): cleanup
            // would keep it anyway, but reporting "possible" while unmigrated posts exist is misleading.
            'can_cleanup'         => $legacy > 0 && $unlinked === 0 && $fresh && !empty($verification['passed']) && $open === 0,
            'cleanup'             => $state['cleanup'],
            'conflicts'           => $state['conflicts'],
        ];
    }

    // ================================================================== locking

    private function acquire_lock(): ?string
    {
        global $wpdb;

        $token = bin2hex(random_bytes(8));
        $value = ['token' => $token, 'at' => time()];

        if ($this->insert_lock($value)) {
            return $token;
        }

        // Someone holds it. Take over only if the holder is gone, and only by deleting the exact stale
        // value we observed, so we can never remove a lock that was taken after we looked.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK));
        if (!is_string($raw)) {
            return $this->insert_lock($value) ? $token : null;
        }
        $held = WP_Dynamic_Tags_Database_Manager::decode_meta($raw);
        if (is_array($held) && !WP_Dynamic_Tags_Migration_Planner::lock_is_stale((int) ($held['at'] ?? 0), time())) {
            return null;
        }

        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_LOCK, $raw));
        wp_cache_delete(self::OPTION_LOCK, 'options');
        if ($deleted === 1 && $this->insert_lock($value)) {
            $this->log('Took over an abandoned migration lock.', 'warning');
            return $token;
        }
        return null;
    }

    /**
     * add_option() only inserts when the option does not exist, which makes it an atomic test-and-set.
     * Two calls with the same argument can return different results, so this is not a pure function.
     *
     * @phpstan-impure
     * @param array{token:string,at:int} $value
     */
    private function insert_lock(array $value): bool
    {
        return add_option(self::OPTION_LOCK, $value, '', false);
    }

    private function renew_lock(string $token): void
    {
        $held = get_option(self::OPTION_LOCK);
        if (is_array($held) && ($held['token'] ?? '') === $token) {
            update_option(self::OPTION_LOCK, ['token' => $token, 'at' => time()], false);
        }
    }

    private function release_lock(string $token): void
    {
        $held = get_option(self::OPTION_LOCK);
        if (is_array($held) && ($held['token'] ?? '') === $token) {
            delete_option(self::OPTION_LOCK);
        }
    }

    // ================================================================== transactions

    private function is_transactional(): bool
    {
        global $wpdb;
        if ($this->transactional === null) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $this->db->get_table_name()
            ));
            $this->transactional = is_string($engine) && strcasecmp($engine, 'InnoDB') === 0;
        }
        return $this->transactional;
    }

    private function begin(): void
    {
        global $wpdb;
        if ($this->is_transactional()) {
            $wpdb->query('START TRANSACTION');
        }
    }

    private function commit(): void
    {
        global $wpdb;
        if ($this->is_transactional()) {
            $wpdb->query('COMMIT');
        }
    }

    private function rollback(): void
    {
        global $wpdb;
        if ($this->is_transactional()) {
            $wpdb->query('ROLLBACK');
        }
    }

    // ================================================================== loading

    /**
     * A batch of legacy posts after $after_id, in ID order, in the planner's shape.
     *
     * @return array<int,array<string,mixed>> keyed by post ID
     */
    private function load_posts(int $after_id, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_status, p.post_date, p.post_modified FROM {$wpdb->posts} p WHERE " . $this->legacy_where() . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
                $after_id,
                $limit
            ),
            ARRAY_A
        );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows) || !$rows) {
            return [];
        }

        $posts = [];
        foreach ($rows as $row) {
            $id         = (int) $row['ID'];
            $posts[$id] = [
                'ID'        => $id,
                'title'     => (string) $row['post_title'],
                'content'   => (string) $row['post_content'],
                'excerpt'   => (string) $row['post_excerpt'],
                'status'    => (string) $row['post_status'],
                'date'      => (string) $row['post_date'],
                'modified'  => (string) $row['post_modified'],
                'meta'      => [],
                'group_ids' => [],
            ];
        }

        $ids = implode(',', array_map('intval', array_keys($posts)));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $meta = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY meta_id ASC", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ((array) $meta as $m) {
            $posts[(int) $m['post_id']]['meta'][(string) $m['meta_key']] = (string) $m['meta_value'];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ({$ids}) ORDER BY tt.term_id ASC",
            self::TAXONOMY
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ((array) $terms as $t) {
            $posts[(int) $t['object_id']]['group_ids'][] = (int) $t['term_id'];
        }

        return $posts;
    }

    /** @return array<string,mixed> the planner's index of every existing row */
    private function load_row_index(): array
    {
        global $wpdb;
        $table = $this->db->get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $rows  = $wpdb->get_results("SELECT id, shortcode, tag_name, post_id, status, modified_date, category_id, MD5(content) AS content_md5 FROM {$table}", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return WP_Dynamic_Tags_Migration_Planner::build_index(is_array($rows) ? $rows : []);
    }

    // ================================================================== dry run

    /**
     * What a run would do, without writing anything (no backup, no rows, no state).
     *
     * @return array<string,mixed>
     */
    public function dry_run(int $sample = 200): array
    {
        $index   = $this->load_row_index();
        $by      = [];
        $reasons = [];
        $items   = [];
        $total   = 0;
        $after   = 0;
        $fake_id = -1;

        while (true) {
            $posts = $this->load_posts($after, 200);
            if (!$posts) {
                break;
            }
            foreach ($posts as $post) {
                $after = (int) $post['ID'];
                $plan  = WP_Dynamic_Tags_Migration_Planner::plan_post($post, $index);
                $total++;
                $by[$plan['action']]    = ($by[$plan['action']] ?? 0) + 1;
                $reasons[$plan['reason']] = ($reasons[$plan['reason']] ?? 0) + 1;

                if ($plan['action'] !== WP_Dynamic_Tags_Migration_Planner::ACTION_SKIP && count($items) < $sample) {
                    $items[] = ['post_id' => $post['ID'], 'title' => $post['title'], 'action' => $plan['action'], 'reason' => $plan['reason'], 'shortcode' => $plan['shortcode']];
                }
                if ($plan['action'] === WP_Dynamic_Tags_Migration_Planner::ACTION_INSERT) {
                    WP_Dynamic_Tags_Migration_Planner::remember($index, [
                        'id' => $fake_id--, 'shortcode' => $plan['shortcode'], 'tag_name' => $plan['tag_name'], 'post_id' => $post['ID'],
                        'status' => $plan['data']['status'], 'modified_date' => $plan['data']['modified_date'], 'category_id' => $plan['data']['category_id'],
                        'content_md5' => md5($post['content']),
                    ]);
                }
            }
        }

        return ['posts' => $total, 'actions' => $by, 'reasons' => $reasons, 'sample' => $items, 'writes' => 0];
    }

    // ================================================================== running

    /**
     * Process one batch under the lock (a single AJAX step).
     *
     * @return array<string,mixed>|WP_Error status() plus 'done' and 'processed'
     */
    public function run_batch(int $size = 100)
    {
        $token = $this->acquire_lock();
        if ($token === null) {
            return new WP_Error('locked', __('Another migration step is already running.', 'wp-dynamic-tags'));
        }
        try {
            return $this->run_batch_locked($size);
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    /**
     * Keep processing batches until finished or the time budget is spent.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function run(int $budget_seconds = 20, int $size = 100)
    {
        $token = $this->acquire_lock();
        if ($token === null) {
            return new WP_Error('locked', __('Another migration step is already running.', 'wp-dynamic-tags'));
        }
        $start = microtime(true);
        try {
            do {
                $result = $this->run_batch_locked($size);
                if (is_wp_error($result)) {
                    return $result;
                }
                $this->renew_lock($token);
            } while (empty($result['done']) && (microtime(true) - $start) < $budget_seconds);
            return $result;
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function run_batch_locked(int $size)
    {
        global $wpdb;

        $state = $this->get_state();

        if (!$this->db->table_exists()) {
            $created = $this->db->create_table();
            if (is_wp_error($created)) {
                return $created;
            }
            $this->transactional = null;
        }

        if (in_array($state['status'], ['pending', 'failed'], true) || !is_array($state['backup'])) {
            if (!is_array($state['backup'])) {
                $backup = $this->create_backup('migration');
                if (is_wp_error($backup)) {
                    $this->log('Backup failed, migration not started: ' . $backup->get_error_message(), 'error');
                    return $backup;
                }
                $state['backup'] = $backup;
                $this->log(sprintf('Backup written (%d posts, %d rows).', $backup['posts'], $backup['rows']), 'success');
            }
            $state['status']      = 'running';
            $state['started_at']  = $state['started_at'] ?? current_time('mysql');
            $state['last_error']  = null;
            $state['total_posts'] = $this->count_legacy_posts();
            $state = $this->save_state($state);
            $this->log('Migration started.');
        }

        $posts = $this->load_posts((int) $state['last_post_id'], max(1, $size));

        if (!$posts) {
            return $this->finish($state);
        }

        $index = $this->load_row_index();
        $this->begin();
        try {
            foreach ($posts as $post) {
                $this->process_post($post, $index, $state);
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            $state['status']     = 'failed';
            $state['last_error'] = $e->getMessage();
            $state['counters']['errors']++;
            $this->save_state($state);
            $this->log('Batch failed and was rolled back: ' . $e->getMessage(), 'error');
            return new WP_Error('batch_failed', $e->getMessage());
        }

        $state['last_post_id'] = (int) array_key_last($posts);
        $state = $this->save_state($state);
        $this->flush_table_caches();

        // If that was the last post, finish now. Waiting for one more empty call would leave the migration
        // "running" forever whenever the caller (a browser tab, a cron tick) goes away in between.
        if ($this->count_after_cursor((int) $state['last_post_id']) === 0) {
            return $this->finish($state, count($posts));
        }

        $status               = $this->status();
        $status['processed']  = count($posts);
        $status['done']       = false;
        return $status;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function finish(array $state, int $processed = 0): array
    {
        global $wpdb;
        $table = $this->db->get_table_name();
        $already_completed = $state['status'] === 'completed';

        // Rows pointing at posts that no longer exist are table-native now.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $fixed = $wpdb->query("UPDATE {$table} t LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id SET t.post_id = 0, t.modified_date = t.modified_date WHERE t.post_id > 0 AND (p.ID IS NULL OR p.post_type <> '" . self::POST_TYPE . "')");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        if (is_int($fixed) && $fixed > 0) {
            $state['counters']['orphans_fixed'] += $fixed;
            $this->log(sprintf('%d rows no longer pointed at a post and were detached.', $fixed));
        } elseif ($already_completed) {
            // Nothing to do and nothing changed: do not rewrite the state, which would invalidate a verification.
            $status              = $this->status();
            $status['processed'] = $processed;
            $status['done']      = true;
            return $status;
        }

        $state['status']      = 'completed';
        $state['finished_at'] = current_time('mysql');
        $state['last_error']  = null;
        $state = $this->save_state($state);
        $this->flush_table_caches();
        $this->refresh_pending_flag();
        $this->log(sprintf(
            'Migration finished: %d inserted, %d updated, %d linked, %d skipped, %d conflicts.',
            $state['counters']['inserted'], $state['counters']['updated'], $state['counters']['linked'], $state['counters']['skipped'], $state['counters']['conflicts']
        ), 'success');

        $status              = $this->status();
        $status['processed'] = $processed;
        $status['done']      = true;
        return $status;
    }

    /**
     * Apply the planner's decision for one post. Writes go through the repository / $wpdb, never through
     * the Table Manager facade, so no hooks fire (no bridge, no mirror posts) and partial writes cannot
     * trip the facade's validation.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $index
     * @param array<string,mixed> $state
     */
    private function process_post(array $post, array &$index, array &$state): void
    {
        global $wpdb;

        $table = $this->db->get_table_name();
        $plan  = WP_Dynamic_Tags_Migration_Planner::plan_post($post, $index);
        $id    = (int) $post['ID'];

        switch ($plan['action']) {
            case WP_Dynamic_Tags_Migration_Planner::ACTION_INSERT:
                $row_id = $this->db->insert_tag($plan['data']);
                if (is_wp_error($row_id)) {
                    // The database says no (collation-level duplicate, etc.): report it, do not abort the batch.
                    $this->record_conflict($state, $post, 'db_' . $row_id->get_error_code(), null, $plan);
                    break;
                }
                $state['counters']['inserted']++;
                $this->forget_conflict($state, $id);
                WP_Dynamic_Tags_Migration_Planner::remember($index, [
                    'id' => (int) $row_id, 'shortcode' => $plan['shortcode'], 'tag_name' => $plan['tag_name'], 'post_id' => $id,
                    'status' => $plan['data']['status'], 'modified_date' => $plan['data']['modified_date'],
                    'category_id' => $plan['data']['category_id'], 'content_md5' => md5((string) $post['content']),
                ]);
                break;

            case WP_Dynamic_Tags_Migration_Planner::ACTION_UPDATE:
            case WP_Dynamic_Tags_Migration_Planner::ACTION_LINK:
                $data = $plan['data'];
                $data['meta_data'] = $this->merged_meta((int) $plan['row_id'], $post, $plan['aliases']);
                $result = $wpdb->update($table, $data, ['id' => (int) $plan['row_id']]);
                if ($result === false) {
                    throw new RuntimeException('Could not update row ' . (int) $plan['row_id'] . ': ' . $wpdb->last_error); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- recorded in the migration log, never printed as HTML
                }
                $state['counters'][$plan['action'] === WP_Dynamic_Tags_Migration_Planner::ACTION_UPDATE ? 'updated' : 'linked']++;
                $this->forget_conflict($state, $id);
                $row = $index['by_shortcode'][WP_Dynamic_Tags_Migration_Planner::norm($plan['shortcode'])] ?? null;
                if (is_array($row)) {
                    $row['post_id'] = $id;
                    foreach (['status', 'modified_date', 'category_id'] as $col) {
                        if (isset($data[$col])) {
                            $row[$col] = $data[$col];
                        }
                    }
                    if (isset($data['content'])) {
                        $row['content_md5'] = md5((string) $data['content']);
                    }
                    WP_Dynamic_Tags_Migration_Planner::remember($index, $row);
                }
                break;

            case WP_Dynamic_Tags_Migration_Planner::ACTION_CONFLICT:
                $this->record_conflict($state, $post, $plan['reason'], $plan['row_id'], $plan);
                break;

            default: // skip
                $state['counters']['skipped']++;
                $this->forget_conflict($state, $id);
                if ($plan['aliases'] && $plan['row_id']) {
                    $this->backfill_aliases((int) $plan['row_id'], $post, $plan, $index);
                }
        }
    }

    /**
     * The row's existing meta_data with this post's provenance (and legacy aliases) recorded.
     *
     * @param string[] $aliases
     * @param array<string,mixed> $post
     */
    private function merged_meta(int $row_id, array $post, array $aliases): string
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $raw  = $wpdb->get_var($wpdb->prepare("SELECT meta_data FROM {$this->db->get_table_name()} WHERE id = %d", $row_id));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $meta = is_string($raw) && $raw !== '' ? WP_Dynamic_Tags_Database_Manager::decode_meta($raw) : [];
        $meta = is_array($meta) ? $meta : [];
        $meta['legacy'] = WP_Dynamic_Tags_Migration_Planner::legacy_record($post, $aliases);
        return maybe_serialize($meta);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $index
     */
    private function backfill_aliases(int $row_id, array $post, array $plan, array $index): void
    {
        global $wpdb;
        $table = $this->db->get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $raw   = $wpdb->get_var($wpdb->prepare("SELECT meta_data FROM {$table} WHERE id = %d", $row_id));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $meta  = is_string($raw) && $raw !== '' ? WP_Dynamic_Tags_Database_Manager::decode_meta($raw) : [];
        $meta  = is_array($meta) ? $meta : [];
        if (isset($meta['legacy']['aliases']) && $meta['legacy']['aliases'] === $plan['aliases']) {
            return;
        }
        $row = $index['by_shortcode'][WP_Dynamic_Tags_Migration_Planner::norm($plan['shortcode'])] ?? null;
        $meta['legacy'] = WP_Dynamic_Tags_Migration_Planner::legacy_record($post, $plan['aliases']);
        $wpdb->update($table, ['meta_data' => maybe_serialize($meta), 'modified_date' => is_array($row) ? $row['modified_date'] : current_time('mysql')], ['id' => $row_id]);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $post
     * @param array<string,mixed> $plan
     */
    private function record_conflict(array &$state, array $post, string $reason, ?int $row_id, array $plan): void
    {
        $id       = (int) $post['ID'];
        $existing = $state['conflicts'][$id] ?? null;
        if (is_array($existing) && in_array($existing['status'] ?? '', ['accepted', 'resolved'], true)) {
            $state['counters']['skipped']++;
            return;
        }
        if (count($state['conflicts']) >= self::MAX_CONFLICTS && !isset($state['conflicts'][$id])) {
            return;
        }
        $state['conflicts'][$id] = [
            'status'    => 'open',
            'reason'    => $reason,
            'row_id'    => $row_id,
            'title'     => (string) $post['title'],
            'shortcode' => $plan['shortcode'],
            'tag_name'  => $plan['tag_name'],
            'at'        => current_time('mysql'),
        ];
        $this->log(sprintf('Conflict for post %d "%s": %s.', $id, $post['title'], $reason), 'warning');
    }

    /** @param array<string,mixed> $state */
    private function forget_conflict(array &$state, int $post_id): void
    {
        if (isset($state['conflicts'][$post_id]) && ($state['conflicts'][$post_id]['status'] ?? '') === 'open') {
            unset($state['conflicts'][$post_id]);
        }
    }

    // ================================================================== conflicts

    /**
     * Resolve an open conflict: 'rename' migrates the post under a new, unique shortcode and name;
     * 'accept' leaves the post as it is and excludes it from migration and cleanup.
     *
     * @return true|WP_Error
     */
    public function resolve_conflict(int $post_id, string $action)
    {
        global $wpdb;

        $token = $this->acquire_lock();
        if ($token === null) {
            return new WP_Error('locked', __('Another migration step is already running.', 'wp-dynamic-tags'));
        }
        try {
            $state    = $this->get_state();
            $conflict = $state['conflicts'][$post_id] ?? null;
            if (!is_array($conflict) || ($conflict['status'] ?? '') !== 'open') {
                return new WP_Error('no_conflict', __('There is no open conflict for that post.', 'wp-dynamic-tags'));
            }

            if ($action === 'accept') {
                $state['conflicts'][$post_id]['status'] = 'accepted';
                $this->save_state($state);
                $this->log(sprintf('Conflict for post %d accepted: the post is kept and not migrated.', $post_id), 'warning');
                return true;
            }
            if ($action !== 'rename') {
                return new WP_Error('bad_action', __('Unknown resolution.', 'wp-dynamic-tags'));
            }

            $posts = $this->load_posts($post_id - 1, 1);
            $post  = $posts[$post_id] ?? null;
            if (!is_array($post)) {
                return new WP_Error('post_missing', __('The post no longer exists.', 'wp-dynamic-tags'));
            }

            $index = $this->load_row_index();
            [$shortcode, $name] = WP_Dynamic_Tags_Migration_Planner::rename_for_conflict(
                WP_Dynamic_Tags_Migration_Planner::derive_shortcode((string) $post['title']),
                trim((string) $post['title']) === '' ? 'Untitled tag ' . $post_id : (string) $post['title'],
                $post_id
            );
            if (isset($index['by_shortcode'][WP_Dynamic_Tags_Migration_Planner::norm($shortcode)]) || isset($index['by_tag_name'][WP_Dynamic_Tags_Migration_Planner::norm($name)])) {
                return new WP_Error('still_taken', __('The renamed tag would also collide.', 'wp-dynamic-tags'));
            }

            $row_id = $this->db->insert_tag(WP_Dynamic_Tags_Migration_Planner::build_row($post, $shortcode, $name));
            if (is_wp_error($row_id)) {
                return $row_id;
            }
            $state['conflicts'][$post_id]['status']   = 'resolved';
            $state['conflicts'][$post_id]['resolved'] = ['row_id' => (int) $row_id, 'shortcode' => $shortcode];
            $state['counters']['inserted']++;
            $this->save_state($state);
            $this->flush_table_caches();
            $this->log(sprintf('Conflict for post %d resolved: migrated as [%s].', $post_id, $shortcode), 'success');
            return true;
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    // ================================================================== backup

    /** @return string|WP_Error absolute path of the private backup directory (created if needed) */
    private function ensure_backup_dir()
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return new WP_Error('no_uploads', (string) $upload['error']);
        }
        $dir = trailingslashit((string) $upload['basedir']) . self::BACKUP_DIR;
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('backup_dir', __('Could not create the private backup directory.', 'wp-dynamic-tags'));
        }
        $guards = [
            'index.php'  => "<?php\n// Silence is golden.\n",
            '.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
        ];
        foreach ($guards as $file => $contents) {
            if (!is_file($dir . '/' . $file)) {
                file_put_contents($dir . '/' . $file, $contents);
            }
        }
        return $dir;
    }

    /** @return string|null absolute path for a backup file name, or null if the name is not one we create */
    public function backup_path(string $name): ?string
    {
        if (preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{32}\.json$/', $name) !== 1) {
            return null;
        }
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return null;
        }
        return trailingslashit((string) $upload['basedir']) . self::BACKUP_DIR . '/' . $name;
    }

    /**
     * Write and validate a backup of every legacy post, every table row and the groups.
     *
     * @return array<string,mixed>|WP_Error file, sha256, posts, rows, bytes, created_at
     */
    public function create_backup(string $reason = 'migration')
    {
        global $wpdb;

        $dir = $this->ensure_backup_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $table = $this->db->get_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, post_date, post_modified, post_author, menu_order FROM {$wpdb->posts} WHERE post_type = '" . self::POST_TYPE . "' AND post_status <> 'auto-draft' ORDER BY ID ASC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $posts = is_array($posts) ? $posts : [];
        $by_id = [];
        foreach ($posts as $i => $p) {
            $posts[$i]['meta']  = [];
            $posts[$i]['terms'] = [];
            $by_id[(int) $p['ID']] = $i;
        }
        foreach (array_chunk(array_keys($by_id), 500) as $chunk) {
            $in   = implode(',', array_map('intval', $chunk));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
            $meta = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) ORDER BY meta_id ASC", ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ((array) $meta as $m) {
                $posts[$by_id[(int) $m['post_id']]]['meta'][] = ['key' => $m['meta_key'], 'value' => $m['meta_value']];
            }
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
            $terms = $wpdb->get_results("SELECT tr.object_id, tt.taxonomy, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN ({$in})", ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ((array) $terms as $t) {
                $posts[$by_id[(int) $t['object_id']]]['terms'][] = ['taxonomy' => $t['taxonomy'], 'term_id' => (int) $t['term_id']];
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $rows   = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows   = is_array($rows) ? $rows : [];
        $groups = [];
        $terms  = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $groups[] = ['term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent];
                }
            }
        }

        $payload = [
            'format'         => self::BACKUP_FORMAT,
            'format_version' => 1,
            'reason'         => $reason,
            'created_at'     => gmdate('c'),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'counts'         => ['posts' => count($posts), 'rows' => count($rows)],
            'posts'          => $posts,
            'table'          => $rows,
            'groups'         => $groups,
        ];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new WP_Error('backup_encode_failed', 'Could not encode the backup faithfully: ' . $e->getMessage());
        }
        unset($payload);

        $name = sprintf('backup-%s-%s.json', gmdate('Ymd-His'), bin2hex(random_bytes(16)));
        $path = $dir . '/' . $name;
        $tmp  = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return new WP_Error('backup_write_failed', __('Could not write the backup file.', 'wp-dynamic-tags'));
        }
        @chmod($tmp, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!rename($tmp, $path)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return new WP_Error('backup_write_failed', __('Could not finalize the backup file.', 'wp-dynamic-tags'));
        }
        $sha = (string) hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $sha . '  ' . $name . "\n");

        $meta = [
            'file'       => $name,
            'sha256'     => $sha,
            'posts'      => count($posts),
            'rows'       => count($rows),
            'bytes'      => (int) filesize($path),
            'created_at' => current_time('mysql'),
            'reason'     => $reason,
        ];

        $check = $this->validate_backup($meta);
        if (!$check['exists'] || !$check['sha_ok'] || !$check['counts_ok']) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @unlink($path . '.sha256'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return new WP_Error('backup_invalid', __('The backup failed validation after writing and was discarded.', 'wp-dynamic-tags'));
        }

        return $meta;
    }

    /**
     * Re-read a backup and compare it with what was recorded when it was written.
     *
     * @param array<string,mixed> $meta file, sha256, posts, rows
     * @return array{exists:bool,sha_ok:bool,counts_ok:bool}
     */
    public function validate_backup(array $meta): array
    {
        $out  = ['exists' => false, 'sha_ok' => false, 'counts_ok' => false];
        $path = isset($meta['file']) ? $this->backup_path((string) $meta['file']) : null;
        if ($path === null || !is_file($path)) {
            return $out;
        }
        $out['exists'] = true;

        $sha = (string) hash_file('sha256', $path);
        $out['sha_ok'] = isset($meta['sha256']) && hash_equals((string) $meta['sha256'], $sha);

        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data) && ($data['format'] ?? '') === self::BACKUP_FORMAT && isset($data['posts'], $data['table']) && is_array($data['posts']) && is_array($data['table'])) {
            $out['counts_ok'] = count($data['posts']) === (int) ($meta['posts'] ?? -1) && count($data['table']) === (int) ($meta['rows'] ?? -1);
        }
        return $out;
    }

    /**
     * Restore the table rows from a backup this plugin wrote. The current table is first saved to a
     * fresh safety backup, so the restore itself can be undone. Legacy posts are not touched.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function restore(string $file)
    {
        $path = $this->backup_path($file);
        if ($path === null || !is_file($path)) {
            return new WP_Error('no_backup', __('That backup file was not found.', 'wp-dynamic-tags'));
        }
        if (!$this->is_transactional()) {
            return new WP_Error('non_transactional', __('The tags table is not InnoDB, so a restore could not be made atomic. Nothing was changed.', 'wp-dynamic-tags'));
        }

        $sidecar = is_file($path . '.sha256') ? trim((string) strtok((string) file_get_contents($path . '.sha256'), ' ')) : '';
        $sha     = (string) hash_file('sha256', $path);
        if ($sidecar === '' || !hash_equals($sidecar, $sha)) {
            return new WP_Error('checksum', __('The backup does not match its recorded checksum (missing or modified). Nothing was changed.', 'wp-dynamic-tags'));
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['format'] ?? '') !== self::BACKUP_FORMAT || !isset($data['table']) || !is_array($data['table'])) {
            return new WP_Error('format', __('That file is not a Dynamic Tags backup.', 'wp-dynamic-tags'));
        }

        $token = $this->acquire_lock();
        if ($token === null) {
            return new WP_Error('locked', __('Another migration step is already running.', 'wp-dynamic-tags'));
        }
        try {
            $safety = $this->create_backup('pre-restore');
            if (is_wp_error($safety)) {
                return $safety;
            }
            $result = $this->db->restore_from_backup(['tags' => $data['table']]);
            if (is_wp_error($result)) {
                $this->log('Restore failed and was rolled back: ' . $result->get_error_message(), 'error');
                return $result;
            }

            // Links between rows and posts may now differ: let the next run re-evaluate everything.
            $state = $this->get_state();
            $state['status']       = 'pending';
            $state['last_post_id'] = 0;
            $state['verification'] = null;
            $state['finished_at']  = null;
            $this->save_state($state);
            $this->flush_table_caches();
            $this->log(sprintf('Restored %d rows from %s. Safety backup of the previous state: %s.', count($data['table']), $file, $safety['file']), 'success');

            return ['restored_rows' => count($data['table']), 'safety_backup' => $safety['file']];
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    // ================================================================== verification

    /**
     * Run the five independent checks. Read-only apart from recording the result.
     *
     * @return array{passed:bool,checks:array<int,array{id:string,label:string,passed:bool,detail:string}>}
     */
    public function verify(): array
    {
        global $wpdb;

        $state = $this->get_state();
        $table = $this->db->get_table_name();
        $legacy_total = $this->count_legacy_posts();

        $mismatches = 0;
        $examples   = [];
        $after      = 0;
        while (true) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
            $pairs = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_content, p.post_status, p.post_modified, t.status AS row_status, t.modified_date, t.category_id, MD5(t.content) AS content_md5
                 FROM {$wpdb->posts} p INNER JOIN {$table} t ON t.post_id = p.ID
                 WHERE " . $this->legacy_where() . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT 200',
                $after
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            if (!$pairs) {
                break;
            }
            $ids    = array_map(static fn($r) => (int) $r['ID'], $pairs);
            $groups = [];
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
            $terms  = $wpdb->get_results($wpdb->prepare(
                "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN (" . implode(',', $ids) . ')',
                self::TAXONOMY
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            foreach ((array) $terms as $t) {
                $groups[(int) $t['object_id']][] = (int) $t['term_id'];
            }
            foreach ($pairs as $pair) {
                $after = (int) $pair['ID'];
                $diff  = WP_Dynamic_Tags_Migration_Planner::pair_mismatches(
                    ['content' => (string) $pair['post_content'], 'status' => (string) $pair['post_status'], 'modified' => (string) $pair['post_modified']],
                    ['status' => (string) $pair['row_status'], 'modified_date' => (string) $pair['modified_date'], 'category_id' => (int) $pair['category_id'], 'content_md5' => (string) $pair['content_md5']],
                    $groups[$after] ?? []
                );
                if ($diff) {
                    $mismatches++;
                    if (count($examples) < 10) {
                        $examples[] = $after . ': ' . implode('+', $diff);
                    }
                }
            }
        }

        $registration = $this->registration_report();
        $backup       = is_array($state['backup']) ? $this->validate_backup($state['backup']) : ['exists' => false, 'sha_ok' => false, 'counts_ok' => false];

        $facts = [
            'status'           => (string) $state['status'],
            'unprocessed'      => $legacy_total > 0 ? $this->count_after_cursor((int) $state['last_post_id']) : 0,
            'unlinked'         => $legacy_total > 0 ? $this->count_unlinked($this->conflict_ids($state, 'accepted')) : 0,
            'mismatches'       => $mismatches,
            'unregistered'     => $registration['unregistered'],
            'open_conflicts'   => $this->count_conflicts($state, 'open'),
            'backup_exists'    => $backup['exists'],
            'backup_sha_ok'    => $backup['sha_ok'],
            'backup_counts_ok' => $backup['counts_ok'],
        ];
        $result = WP_Dynamic_Tags_Migration_Planner::evaluate_verification($facts);

        $state['verification'] = [
            'at'        => current_time('mysql'),
            'revision'  => (int) $state['revision'],   // recorded without bumping: any later migration write invalidates it
            'passed'    => $result['passed'],
            'checks'    => $result['checks'],
            'examples'  => $examples,
            'offenders' => $registration['offenders'],
        ];
        $this->save_state($state, false);
        $this->log($result['passed'] ? 'Verification passed.' : 'Verification FAILED: ' . implode('; ', array_map(
            static fn($c) => $c['label'] . ' (' . $c['detail'] . ')',
            array_filter($result['checks'], static fn($c) => !$c['passed'])
        )), $result['passed'] ? 'success' : 'warning');
        $this->flush_log();

        return $result;
    }

    /**
     * Register the table's shortcodes and check each migrated, active tag resolves to this plugin's
     * callback (and not to another plugin's, or to nothing).
     *
     * @return array{unregistered:int,offenders:string[]}
     */
    private function registration_report(): array
    {
        global $wpdb, $shortcode_tags;

        $this->flush_table_caches();
        if (class_exists('WP_Dynamic_Tags_Table_Shortcode_Processor')) {
            WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
        }

        $table = $this->db->get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
        $rows  = $wpdb->get_col("SELECT shortcode FROM {$table} WHERE post_id > 0 AND status = 'active'");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $bad   = [];
        foreach ((array) $rows as $shortcode) {
            $callback = $shortcode_tags[$shortcode] ?? null;
            $ours     = false;
            if ($callback instanceof Closure) {
                $this_obj = (new ReflectionFunction($callback))->getClosureThis();
                $ours     = $this_obj instanceof WP_Dynamic_Tags_Table_Shortcode_Processor;
            }
            if (!$ours) {
                $bad[] = (string) $shortcode;
            }
        }
        return ['unregistered' => count($bad), 'offenders' => array_slice($bad, 0, 20)];
    }

    // ================================================================== cleanup

    /**
     * Delete one batch of verified legacy posts.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function cleanup_batch(int $size = 50)
    {
        $token = $this->acquire_lock();
        if ($token === null) {
            return new WP_Error('locked', __('Another migration step is already running.', 'wp-dynamic-tags'));
        }
        try {
            return $this->cleanup_batch_locked($size);
        } finally {
            $this->release_lock($token);
            $this->flush_log();
        }
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function cleanup_batch_locked(int $size)
    {
        global $wpdb;

        $state        = $this->get_state();
        $verification = $state['verification'];
        if (!is_array($verification) || empty($verification['passed']) || (int) ($verification['revision'] ?? -1) !== (int) $state['revision']) {
            return new WP_Error('not_verified', __('Cleanup requires a passing verification of the current data. Run Verify first.', 'wp-dynamic-tags'));
        }
        if ($this->count_conflicts($state, 'open') > 0) {
            return new WP_Error('open_conflicts', __('Resolve or accept every conflict before cleaning up.', 'wp-dynamic-tags'));
        }

        // The migration backup predates anything that arrived since. Snapshot exactly what is about to be
        // deleted, and refuse to delete anything if that snapshot cannot be written and validated.
        if ($state['cleanup']['status'] !== 'running') {
            $backup = $this->create_backup('pre-cleanup');
            if (is_wp_error($backup)) {
                $this->log('Cleanup not started: the pre-cleanup backup failed: ' . $backup->get_error_message(), 'error');
                return $backup;
            }
            $state['cleanup_backup'] = $backup;
            $state['cleanup']        = ['status' => 'running', 'cursor' => 0, 'deleted' => 0, 'kept' => 0];
            $state = $this->save_state($state, false);
            $this->log(sprintf('Cleanup started. Pre-cleanup backup written (%d posts, %d rows).', $backup['posts'], $backup['rows']));
        }

        $table  = $this->db->get_table_name();
        $kept   = $this->conflict_ids($state, 'accepted');
        $posts  = $this->load_posts((int) $state['cleanup']['cursor'], max(1, $size));

        if (!$posts) {
            return $this->finish_cleanup($state);
        }

        $removed = $this->suspend_legacy_hooks();
        try {
            foreach ($posts as $post) {
                $id = (int) $post['ID'];
                if (in_array($id, $kept, true)) {
                    $state['cleanup']['kept']++;
                    continue;
                }

                // Re-check this very post and its row now, not at verification time.
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
                $row = $wpdb->get_row($wpdb->prepare("SELECT id, status, modified_date, category_id, MD5(content) AS content_md5 FROM {$table} WHERE post_id = %d", $id), ARRAY_A);
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if (!is_array($row)) {
                    $state['cleanup']['kept']++;
                    $this->log(sprintf('Kept post %d: it has no row.', $id), 'warning');
                    continue;
                }
                $diff = WP_Dynamic_Tags_Migration_Planner::pair_mismatches($post, [
                    'status' => (string) $row['status'], 'modified_date' => (string) $row['modified_date'],
                    'category_id' => (int) $row['category_id'], 'content_md5' => (string) $row['content_md5'],
                ], $post['group_ids']);
                if ($diff) {
                    $state['cleanup']['kept']++;
                    $this->log(sprintf('Kept post %d: it changed since verification (%s).', $id, implode(', ', $diff)), 'warning');
                    continue;
                }

                if (!wp_delete_post($id, true)) {
                    $state['cleanup']['kept']++;
                    $this->log(sprintf('Kept post %d: WordPress could not delete it.', $id), 'error');
                    continue;
                }
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb or the plugin's own prefix; the other fragments are the constant legacy_where() and integer lists (intval); values go through prepare()
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET post_id = 0, modified_date = modified_date WHERE id = %d", (int) $row['id']));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $state['cleanup']['deleted']++;
            }
        } finally {
            $this->restore_hooks($removed);
        }

        $state['cleanup']['cursor'] = (int) array_key_last($posts);
        $state = $this->save_state($state, false);

        // Last post handled: finish in this same call (see run_batch_locked), so an interrupted caller cannot
        // leave cleanup half done with the post path's stale tag cache still in place.
        if ($this->count_after_cursor((int) $state['cleanup']['cursor']) === 0) {
            return $this->finish_cleanup($state, count($posts));
        }

        $status             = $this->status();
        $status['done']     = false;
        $status['processed'] = count($posts);
        return $status;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function finish_cleanup(array $state, int $processed = 0): array
    {
        $state['cleanup']['status'] = 'done';
        update_option(self::OPTION_CLEANED, current_time('mysql'), false);
        $this->save_state($state, false);
        $this->log(sprintf('Cleanup finished: %d legacy posts deleted, %d kept.', $state['cleanup']['deleted'], $state['cleanup']['kept']), 'success');

        $this->flush_table_caches();
        $this->refresh_pending_flag();

        $status              = $this->status();
        $status['done']      = true;
        $status['processed'] = $processed;
        return $status;
    }

    /**
     * Take the legacy post/table sync hooks off while posts are deleted, so deleting a post can never
     * deactivate its row and does not rebuild every cache once per post.
     *
     * @return array<int,array{0:string,1:callable,2:int}>
     */
    private function suspend_legacy_hooks(): array
    {
        $candidates = [];
        $candidates[] = ['before_delete_post', [self::class, 'handle_delete']]; // capture: cleanup is not a user deleting a tag


        $removed = [];
        foreach ($candidates as [$hook, $callback]) {
            if (!is_callable($callback)) {
                continue;
            }
            $priority = has_action($hook, $callback);
            if ($priority !== false) {
                remove_action($hook, $callback, $priority);
                $removed[] = [$hook, $callback, (int) $priority];
            }
        }
        return $removed;
    }

    /** @param array<int,array{0:string,1:callable,2:int}> $removed */
    private function restore_hooks(array $removed): void
    {
        foreach ($removed as [$hook, $callback, $priority]) {
            add_action($hook, $callback, $priority, 1);
        }
    }

    /**
     * Keep deleting verified posts until done or the budget is spent.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function cleanup(int $budget_seconds = 20, int $size = 50)
    {
        $start = microtime(true);
        do {
            $result = $this->cleanup_batch($size);
            if (is_wp_error($result)) {
                return $result;
            }
        } while (empty($result['done']) && (microtime(true) - $start) < $budget_seconds);
        return $result;
    }

    // ================================================================== cron

    /** WP-Cron entry: continue the migration in the background, rescheduling until done. */
    public function cron_run(): void
    {
        if (!$this->status()['has_work']) {
            return;
        }
        $result = $this->run(20, 100);
        $again  = is_wp_error($result) || empty($result['done']);
        if ($again && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK);
        }
    }

    // ================================================================== misc

    private function flush_table_caches(): void
    {
        if (class_exists('WP_Dynamic_Tags_Table_Manager')) {
            $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
            if (method_exists($manager, 'flush_cache')) {
                $manager->flush_cache();
            }
        }
    }

    public function log(string $message, string $type = 'info'): void
    {
        $this->log_buffer[] = ['timestamp' => current_time('mysql'), 'type' => $type, 'message' => $message];
    }

    private function flush_log(): void
    {
        if (!$this->log_buffer) {
            return;
        }
        $stored = get_option(self::OPTION_LOG, []);
        $stored = is_array($stored) ? $stored : [];
        $merged = array_slice(array_merge($stored, $this->log_buffer), -self::MAX_LOG);
        update_option(self::OPTION_LOG, $merged, false);
        $this->log_buffer = [];
    }

    /** @return array<int,array{timestamp:string,type:string,message:string}> */
    public function get_log(): array
    {
        $stored = get_option(self::OPTION_LOG, []);
        return is_array($stored) ? $stored : [];
    }
}
