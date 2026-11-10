<?php
/**
 * Migration Manager for WP Dynamic Tags
 *
 * Compatibility facade. The actual work (resumable batches, backup, verification, cleanup) lives in
 * WP_Dynamic_Tags_Migration_Engine; this class keeps the method names the older admin screens call.
 *
 * Removed on purpose: the reverse table -> posts migration and the bidirectional sync (the table is the
 * single source of truth now), and the "reset" that ran TRUNCATE TABLE (it would have wiped every
 * table-native tag, including the ones Certificate Generator creates). reset_migration() now only
 * forgets migration progress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Migration_Manager {

    private static $instance = null;

    public function __construct() {
    }

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function engine(): WP_Dynamic_Tags_Migration_Engine {
        return WP_Dynamic_Tags_Migration_Engine::get_instance();
    }

    /**
     * Are there legacy posts that have not been migrated?
     */
    public function is_migration_needed() {
        return $this->engine()->needs_migration();
    }

    /**
     * Migration status in the vocabulary the older screens expect.
     */
    public function get_migration_status() {
        $status = $this->engine()->status();

        switch ($status['phase']) {
            case 'running':
                return 'in_progress';
            case 'completed':
                return $status['open_conflicts'] > 0 ? 'completed_with_errors' : 'completed';
            case 'failed':
                return 'failed';
            default:
                return 'not_started';
        }
    }

    /**
     * Get migration log
     */
    public function get_migration_log() {
        return $this->engine()->get_log();
    }

    /**
     * Clear migration log
     */
    public function clear_migration_log() {
        delete_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOG);
    }

    /**
     * Migrate legacy posts into the table, within a time budget (call again to continue).
     *
     * @return array{success:bool,migrated?:int,errors?:int,done?:bool,error?:string}
     */
    public function migrate_posts_to_table($batch_size = 50) {
        $result = $this->engine()->run(20, max(1, (int) $batch_size));

        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message());
        }

        $counters = $result['counters'];

        return array(
            'success'  => true,
            'migrated' => (int) $counters['inserted'] + (int) $counters['updated'] + (int) $counters['linked'],
            'errors'   => (int) $counters['errors'] + (int) $result['open_conflicts'],
            'done'     => !empty($result['done']),
        );
    }

    /**
     * Migration statistics for the status screens.
     */
    public function get_migration_statistics() {
        global $wpdb;

        $db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();
        $status     = $this->engine()->status();

        $by_status = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status",
                WP_Dynamic_Tags_Migration_Engine::POST_TYPE
            ),
            OBJECT_K
        );
        $count = static function ($key) use ($by_status) {
            return isset($by_status[$key]) ? (int) $by_status[$key]->n : 0;
        };

        $stats = array();
        $stats['total_posts']      = (int) $status['legacy_posts'];
        $stats['published_posts']  = $count('publish');
        $stats['draft_posts']      = $count('draft');

        $stats['total_table_records']    = $db_manager->get_tags_count(array('status' => ''));
        $stats['active_table_records']   = $db_manager->get_tags_count(array('status' => 'active'));
        $stats['inactive_table_records'] = $db_manager->get_tags_count(array('status' => 'inactive'));

        $stats['migration_status'] = $this->get_migration_status();
        $stats['migration_needed'] = $status['needs_migration'];

        $issues = array();
        if ($status['unlinked'] > 0) {
            $issues[] = sprintf('%d legacy posts have no table record yet', $status['unlinked']);
        }
        if ($status['open_conflicts'] > 0) {
            $issues[] = sprintf('%d migration conflicts need a decision', $status['open_conflicts']);
        }
        $stats['integrity_issues'] = $issues;

        return $stats;
    }

    /**
     * Forget migration progress so the next run starts from the beginning. Never touches tags, posts or backups.
     */
    public function reset_migration() {
        $this->engine()->reset_state();
    }
}
