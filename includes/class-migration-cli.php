<?php
/**
 * WP-CLI commands for the posts -> table migration. Loaded only when WP-CLI is running.
 *
 *   wp dynamic-tags status
 *   wp dynamic-tags migrate [--dry-run] [--batch=<n>]
 *   wp dynamic-tags verify
 *   wp dynamic-tags cleanup [--yes]
 *   wp dynamic-tags conflicts
 *   wp dynamic-tags resolve <post_id> <rename|accept>
 *   wp dynamic-tags restore <backup-file> [--yes]
 *   wp dynamic-tags rescan
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Migration_CLI
{
    private function engine(): WP_Dynamic_Tags_Migration_Engine
    {
        return WP_Dynamic_Tags_Migration_Engine::get_instance();
    }

    /** @param mixed $result */
    private function check($result)
    {
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        return $result;
    }

    /**
     * Show migration status.
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $s = $this->engine()->status();
        WP_CLI::log(sprintf('Phase: %s', $s['phase']));
        WP_CLI::log(sprintf('Legacy posts: %d (%d not yet migrated, %d after the cursor)', $s['legacy_posts'], $s['unlinked'], $s['remaining']));
        WP_CLI::log(sprintf('Counters: %s', wp_json_encode($s['counters'])));
        WP_CLI::log(sprintf('Open conflicts: %d, accepted: %d', $s['open_conflicts'], $s['accepted_conflicts']));
        if (is_array($s['backup'])) {
            WP_CLI::log(sprintf('Backup: %s (%d posts, %d rows)', $s['backup']['file'], $s['backup']['posts'], $s['backup']['rows']));
        }
        if (is_array($s['verification'])) {
            WP_CLI::log(sprintf('Verification: %s at %s%s', $s['verification']['passed'] ? 'passed' : 'FAILED', $s['verification']['at'], $s['verification_fresh'] ? '' : ' (out of date)'));
        }
        WP_CLI::log(sprintf('Cleanup possible: %s', $s['can_cleanup'] ? 'yes' : 'no'));
    }

    /**
     * Migrate legacy posts into the table (resumable; safe to run repeatedly).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would happen. Writes nothing and creates no backup.
     *
     * [--batch=<n>]
     * : Posts per batch.
     * ---
     * default: 100
     * ---
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function migrate($args, $assoc_args): void
    {
        $batch = max(1, (int) ($assoc_args['batch'] ?? 100));

        if (!empty($assoc_args['dry-run'])) {
            $plan = $this->engine()->dry_run(1000);
            WP_CLI::log(sprintf('%d legacy posts. Actions: %s', $plan['posts'], wp_json_encode($plan['actions'])));
            WP_CLI::log(sprintf('Reasons: %s', wp_json_encode($plan['reasons'])));
            foreach ($plan['sample'] as $item) {
                WP_CLI::log(sprintf('  #%d %-9s %-30s [%s] %s', $item['post_id'], $item['action'], $item['reason'], $item['shortcode'], $item['title']));
            }
            WP_CLI::success('Dry run only: nothing was written.');
            return;
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        do {
            $result = $this->check($this->engine()->run_batch($batch));
            WP_CLI::log(sprintf('Processed %d; %d legacy posts not yet migrated.', $result['processed'] ?? 0, $result['unlinked']));
        } while (empty($result['done']));

        $c = $result['counters'];
        WP_CLI::success(sprintf('Migration finished: %d inserted, %d updated, %d linked, %d skipped, %d open conflicts.', $c['inserted'], $c['updated'], $c['linked'], $c['skipped'], $result['open_conflicts']));
        if ($result['open_conflicts'] > 0) {
            WP_CLI::warning('Conflicts need a decision: run `wp dynamic-tags conflicts`.');
        }
    }

    /**
     * Run the five verification checks. Exits non-zero if any fails.
     *
     * @when after_wp_load
     */
    public function verify(): void
    {
        $result = $this->engine()->verify();
        foreach ($result['checks'] as $check) {
            WP_CLI::log(sprintf('%s %s (%s)', $check['passed'] ? '[ok]  ' : '[FAIL]', $check['label'], $check['detail']));
        }
        if (!$result['passed']) {
            WP_CLI::error('Verification failed. Nothing may be deleted.');
        }
        WP_CLI::success('Verification passed.');
    }

    /**
     * Delete the legacy posts that were verified. Writes a fresh backup first.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function cleanup($args, $assoc_args): void
    {
        WP_CLI::confirm('Permanently delete the old tag posts that were migrated and verified?', $assoc_args);
        do {
            $result = $this->check($this->engine()->cleanup_batch(50));
            WP_CLI::log(sprintf('Deleted so far: %d, kept: %d', $result['cleanup']['deleted'], $result['cleanup']['kept']));
        } while (empty($result['done']));
        WP_CLI::success(sprintf('Cleanup finished: %d deleted, %d kept.', $result['cleanup']['deleted'], $result['cleanup']['kept']));
    }

    /**
     * List migration conflicts.
     *
     * @when after_wp_load
     */
    public function conflicts(): void
    {
        $s = $this->engine()->status();
        if (!$s['conflicts']) {
            WP_CLI::success('No conflicts.');
            return;
        }
        foreach ($s['conflicts'] as $post_id => $c) {
            WP_CLI::log(sprintf('#%d [%s] %s — %s (%s)', $post_id, $c['status'], $c['title'], $c['reason'], $c['shortcode']));
        }
    }

    /**
     * Resolve a conflict.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The legacy post.
     *
     * <resolution>
     * : rename (migrate under a new unique name) or accept (keep the post, skip it).
     *
     * @when after_wp_load
     *
     * @param array<int,string> $args
     */
    public function resolve($args): void
    {
        $this->check($this->engine()->resolve_conflict((int) $args[0], (string) $args[1]));
        WP_CLI::success('Conflict resolved.');
    }

    /**
     * Restore the tag table from a backup written by this plugin. A safety backup of the current state is written first.
     *
     * ## OPTIONS
     *
     * <backup-file>
     * : File name inside uploads/wp-dynamic-tags-private/.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function restore($args, $assoc_args): void
    {
        WP_CLI::confirm('This REPLACES every row in the tags table with the backup. Tags created since the backup will be lost (a safety backup is written first). Continue?', $assoc_args);
        $result = $this->check($this->engine()->restore((string) $args[0]));
        WP_CLI::success(sprintf('Restored %d rows. Safety backup of the previous state: %s', $result['restored_rows'], $result['safety_backup']));
    }

    /**
     * Look at every legacy post again.
     *
     * @when after_wp_load
     */
    public function rescan(): void
    {
        $this->engine()->rescan();
        WP_CLI::success('Rescan queued: run `wp dynamic-tags migrate`.');
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('dynamic-tags', 'WP_Dynamic_Tags_Migration_CLI');
}
