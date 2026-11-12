<?php
/**
 * Admin surface of the posts -> table migration: the notice with a batched progress runner, the AJAX
 * endpoints, the private backup download, the WP-Cron hook and the panel shown on the Table Manager page.
 *
 * Nothing here runs for a visitor: every endpoint checks a nonce and the manage_options capability, and
 * the notice and cron scheduling only act for administrators.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Migration_Admin
{
    public const NONCE = 'dt_migration';

    private static bool $script_printed = false;

    public function register(): void
    {
        // WP-Cron runs outside wp-admin, so this hook is registered unconditionally.
        add_action(WP_Dynamic_Tags_Migration_Engine::CRON_HOOK, array($this->engine(), 'cron_run'));

        if (!is_admin()) {
            return;
        }

        add_action('admin_init', array($this, 'maybe_schedule_cron'));
        add_action('admin_notices', array($this, 'render_notice'));

        foreach (array('step', 'status', 'dry_run', 'verify', 'cleanup', 'resolve', 'restore', 'rescan') as $action) {
            add_action('wp_ajax_dt_migration_' . $action, array($this, 'ajax_' . $action));
        }
        add_action('admin_post_dt_migration_backup', array($this, 'download_backup'));
    }

    private function engine(): WP_Dynamic_Tags_Migration_Engine
    {
        return WP_Dynamic_Tags_Migration_Engine::get_instance();
    }

    // ------------------------------------------------------------------ cron

    public function maybe_schedule_cron(): void
    {
        if (!current_user_can('manage_options') || wp_next_scheduled(WP_Dynamic_Tags_Migration_Engine::CRON_HOOK)) {
            return;
        }
        if ($this->engine()->status()['has_work']) {
            wp_schedule_single_event(time() + 30, WP_Dynamic_Tags_Migration_Engine::CRON_HOOK);
        }
    }

    public static function unschedule_cron(): void
    {
        wp_clear_scheduled_hook(WP_Dynamic_Tags_Migration_Engine::CRON_HOOK);
    }

    // ------------------------------------------------------------------ AJAX

    private function guard(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Not allowed.', 'wp-dynamic-tags'), 'code' => 'forbidden'), 403);
        }
    }

    /** @param mixed $result */
    private function reply($result): void
    {
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()));
        }
        wp_send_json_success($result);
    }

    public function ajax_step(): void
    {
        $this->guard();
        $this->reply($this->engine()->run_batch(100));
    }

    public function ajax_status(): void
    {
        $this->guard();
        $this->reply($this->engine()->status());
    }

    public function ajax_dry_run(): void
    {
        $this->guard();
        $this->reply($this->engine()->dry_run());
    }

    public function ajax_verify(): void
    {
        $this->guard();
        $this->reply($this->engine()->verify());
    }

    public function ajax_cleanup(): void
    {
        $this->guard();
        $this->reply($this->engine()->cleanup_batch(50));
    }

    public function ajax_rescan(): void
    {
        $this->guard();
        $this->engine()->rescan();
        $this->reply($this->engine()->status());
    }

    public function ajax_resolve(): void
    {
        $this->guard();
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $action  = isset($_POST['resolution']) ? sanitize_key(wp_unslash($_POST['resolution'])) : '';
        $result  = $this->engine()->resolve_conflict($post_id, $action);
        $this->reply(is_wp_error($result) ? $result : $this->engine()->status());
    }

    public function ajax_restore(): void
    {
        $this->guard();
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        $this->reply($this->engine()->restore($file));
    }

    // ------------------------------------------------------------------ backup download

    public function download_backup(): void
    {
        check_admin_referer('dt_migration_backup');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'wp-dynamic-tags'), '', array('response' => 403));
        }

        $file = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        $path = $this->engine()->backup_path($file);
        if ($path === null || !is_file($path)) {
            wp_die(esc_html__('Backup not found.', 'wp-dynamic-tags'), '', array('response' => 404));
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function backup_url(string $file): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=dt_migration_backup&file=' . rawurlencode($file)), 'dt_migration_backup');
    }

    // ------------------------------------------------------------------ notice

    public function render_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->engine()->status();

        $verified_pending = $s['phase'] === 'completed' && $s['legacy_posts'] > 0 && !$s['can_cleanup'];
        if (!$s['needs_migration'] && !$verified_pending && $s['phase'] !== 'failed') {
            return;
        }

        $page = admin_url('admin.php?page=dt-tools');
        echo '<div class="notice notice-warning" id="dt-mig-notice"><p><strong>' . esc_html__('Dynamic Tags is moving your tags into its new tag table.', 'wp-dynamic-tags') . '</strong> ';

        if ($s['has_work']) {
            echo '<span id="dt-mig-line">' . esc_html(sprintf(
                /* translators: 1: migrated so far, 2: total legacy posts */
                __('Migrating… %1$d of %2$d done. Keep this page open; it also continues in the background.', 'wp-dynamic-tags'),
                max(0, $s['legacy_posts'] - $s['unlinked']),
                $s['legacy_posts']
            )) . '</span>';
        } elseif ($s['phase'] === 'failed') {
            echo esc_html__('The last step failed and was rolled back. Nothing was lost.', 'wp-dynamic-tags') . ' ' . esc_html((string) $s['last_error']);
        } elseif ($s['open_conflicts'] > 0) {
            echo esc_html(sprintf(
                /* translators: %d: number of conflicts */
                _n('%d tag needs your decision.', '%d tags need your decision.', $s['open_conflicts'], 'wp-dynamic-tags'),
                $s['open_conflicts']
            ));
        } else {
            echo esc_html__('Migration is complete. Verify it, then remove the old posts.', 'wp-dynamic-tags');
        }
        echo ' <a href="' . esc_url($page) . '">' . esc_html__('Open the migration panel', 'wp-dynamic-tags') . '</a></p></div>';

        $auto = $s['has_work'];
        $this->print_script($auto);
    }

    // ------------------------------------------------------------------ panel

    /** The migration controls for the Table Manager page. */
    public function render_panel(): void
    {
        $s = $this->engine()->status();
        $log = array_slice(array_reverse($this->engine()->get_log()), 0, 15);

        echo '<div id="dt-mig-panel">';
        echo '<p>' . esc_html__('Older versions stored tags as posts. The table is now the only storage. This moves existing posts into it safely: a backup first, in small resumable steps, and the old posts are removed only after you confirm.', 'wp-dynamic-tags') . '</p>';

        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        $this->row(__('Status', 'wp-dynamic-tags'), esc_html($this->phase_label($s['phase'])));
        $this->row(__('Legacy posts', 'wp-dynamic-tags'), esc_html((string) $s['legacy_posts']) . ' ' . esc_html(sprintf(__('(%d not yet migrated)', 'wp-dynamic-tags'), $s['unlinked'])));
        $c = $s['counters'];
        $this->row(__('This run', 'wp-dynamic-tags'), esc_html(sprintf(
            __('%1$d inserted, %2$d updated, %3$d linked, %4$d skipped, %5$d conflicts', 'wp-dynamic-tags'),
            $c['inserted'], $c['updated'], $c['linked'], $c['skipped'], $s['open_conflicts']
        )));
        if (is_array($s['backup'])) {
            $this->row(__('Backup', 'wp-dynamic-tags'), '<a href="' . esc_url($this->backup_url((string) $s['backup']['file'])) . '">' . esc_html((string) $s['backup']['file']) . '</a> '
                . esc_html(sprintf(__('(%1$d posts, %2$d rows, %3$s)', 'wp-dynamic-tags'), $s['backup']['posts'], $s['backup']['rows'], size_format((int) $s['backup']['bytes']))));
        }
        if (is_array($s['verification'])) {
            $v = $s['verification'];
            $this->row(__('Verification', 'wp-dynamic-tags'), esc_html(($v['passed'] ? __('Passed', 'wp-dynamic-tags') : __('Failed', 'wp-dynamic-tags')) . ' — ' . $v['at'] . ($s['verification_fresh'] ? '' : ' ' . __('(out of date: data changed since)', 'wp-dynamic-tags'))));
        }
        if ($s['last_error']) {
            $this->row(__('Last error', 'wp-dynamic-tags'), esc_html((string) $s['last_error']));
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:12px">';
        $buttons = array(
            'dry_run' => array(__('Dry run', 'wp-dynamic-tags'), false),
            'run'     => array($s['phase'] === 'running' || $s['phase'] === 'failed' ? __('Resume migration', 'wp-dynamic-tags') : __('Run migration', 'wp-dynamic-tags'), true),
            'rescan'  => array(__('Rescan', 'wp-dynamic-tags'), false),
            'verify'  => array(__('Verify', 'wp-dynamic-tags'), false),
        );
        foreach ($buttons as $key => $b) {
            printf('<button type="button" class="button%s" data-dt-mig="%s">%s</button> ', $b[1] ? ' button-primary' : '', esc_attr($key), esc_html($b[0]));
        }
        printf('<button type="button" class="button" data-dt-mig="cleanup"%s>%s</button>', $s['can_cleanup'] ? '' : ' disabled', esc_html__('Remove old posts', 'wp-dynamic-tags'));
        echo '</p><div id="dt-mig-out" aria-live="polite"></div>';

        if ($s['conflicts']) {
            echo '<h3>' . esc_html__('Needs your decision', 'wp-dynamic-tags') . '</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>'
                . esc_html__('Post', 'wp-dynamic-tags') . '</th><th>' . esc_html__('Why', 'wp-dynamic-tags') . '</th><th>' . esc_html__('State', 'wp-dynamic-tags') . '</th><th></th></tr></thead><tbody>';
            foreach ($s['conflicts'] as $post_id => $conflict) {
                $open = ($conflict['status'] ?? '') === 'open';
                echo '<tr><td>#' . (int) $post_id . ' ' . esc_html((string) $conflict['title']) . '</td><td>' . esc_html($this->reason_label((string) $conflict['reason'])) . '</td><td>' . esc_html((string) $conflict['status']) . '</td><td>';
                if ($open) {
                    printf('<button type="button" class="button" data-dt-resolve="rename" data-post="%1$d">%2$s</button> <button type="button" class="button" data-dt-resolve="accept" data-post="%1$d">%3$s</button>',
                        (int) $post_id, esc_html__('Migrate under a new name', 'wp-dynamic-tags'), esc_html__('Keep the post, skip it', 'wp-dynamic-tags'));
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if ($log) {
            echo '<h3>' . esc_html__('Recent activity', 'wp-dynamic-tags') . '</h3><ul style="max-width:900px">';
            foreach ($log as $entry) {
                echo '<li><code>' . esc_html((string) $entry['timestamp']) . '</code> ' . esc_html((string) $entry['message']) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        $this->print_script(false);
    }

    private function row(string $label, string $html): void
    {
        echo '<tr><th style="width:160px">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape
    }

    private function phase_label(string $phase): string
    {
        $labels = array(
            'idle'      => __('Nothing to migrate', 'wp-dynamic-tags'),
            'pending'   => __('Not started', 'wp-dynamic-tags'),
            'running'   => __('In progress', 'wp-dynamic-tags'),
            'completed' => __('Migrated', 'wp-dynamic-tags'),
            'failed'    => __('Failed (rolled back, safe to resume)', 'wp-dynamic-tags'),
        );
        return $labels[$phase] ?? $phase;
    }

    private function reason_label(string $reason): string
    {
        $labels = array(
            'shortcode_taken'                => __('Another tag already uses this shortcode', 'wp-dynamic-tags'),
            'tag_name_taken'                 => __('Another tag already uses this name', 'wp-dynamic-tags'),
            'shortcode_linked_to_other_post' => __('Its table record belongs to a different post', 'wp-dynamic-tags'),
            'name_too_long'                  => __('The title is longer than 255 characters', 'wp-dynamic-tags'),
        );
        return $labels[$reason] ?? $reason;
    }

    // ------------------------------------------------------------------ script

    private function print_script(bool $auto_run): void
    {
        if (self::$script_printed) {
            return;
        }
        self::$script_printed = true;

        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'auto'    => $auto_run,
            'i18n'    => array(
                'working'      => __('Working…', 'wp-dynamic-tags'),
                'confirmClean' => __('Permanently delete the old tag posts? A backup of exactly what will be deleted is written first.', 'wp-dynamic-tags'),
                'confirmDry'   => '',
                'progress'     => __('Migrating… %1$d of %2$d done.', 'wp-dynamic-tags'),
                'done'         => __('Done.', 'wp-dynamic-tags'),
            ),
        );
        ?>
        <script>
        (function () {
            var cfg = <?php echo wp_json_encode($cfg); ?>;
            var out = function () { return document.getElementById('dt-mig-out'); };
            var line = function () { return document.getElementById('dt-mig-line'); };
            function say(text, cls) {
                var o = out(); if (!o) { return; }
                o.textContent = '';
                var p = document.createElement('p'); p.className = cls || ''; p.textContent = text; o.appendChild(p);
            }
            function call(action, extra) {
                var fd = new FormData(); fd.append('action', 'dt_migration_' + action); fd.append('nonce', cfg.nonce);
                Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
                return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
            }
            function fail(r) { say((r && r.data && r.data.message) || 'Request failed.', 'notice-error'); }
            function loop(action, onData) {
                return call(action).then(function (r) {
                    if (!r.success) {
                        if (r.data && r.data.code === 'locked') { return new Promise(function (res) { setTimeout(res, 3000); }).then(function () { return loop(action, onData); }); }
                        fail(r); return null;
                    }
                    onData(r.data);
                    if (r.data.done === false) { return loop(action, onData); }
                    return r.data;
                });
            }
            function progress(d) {
                var text = cfg.i18n.progress.replace('%1$d', Math.max(0, d.legacy_posts - d.unlinked)).replace('%2$d', d.legacy_posts);
                if (line()) { line().textContent = text; }
                say(text);
            }
            function run() { say(cfg.i18n.working); return loop('step', progress).then(function (d) { if (d) { say(cfg.i18n.done); location.reload(); } }); }

            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-dt-mig],[data-dt-resolve]'); if (!b || b.disabled) { return; }
                var a = b.getAttribute('data-dt-mig');
                if (a === 'run') { run(); }
                else if (a === 'dry_run') { say(cfg.i18n.working); call('dry_run').then(function (r) { if (!r.success) { return fail(r); } say(JSON.stringify(r.data.actions) + ' — ' + r.data.posts + ' posts, 0 writes.'); }); }
                else if (a === 'rescan') { call('rescan').then(function (r) { r.success ? location.reload() : fail(r); }); }
                else if (a === 'verify') { say(cfg.i18n.working); call('verify').then(function (r) { if (!r.success) { return fail(r); } say(r.data.checks.map(function (c) { return (c.passed ? '✔ ' : '✘ ') + c.label + ' (' + c.detail + ')'; }).join('\n')); setTimeout(function () { location.reload(); }, 2500); }); }
                else if (a === 'cleanup') { if (window.confirm(cfg.i18n.confirmClean)) { say(cfg.i18n.working); loop('cleanup', function (d) { say(d.cleanup.deleted + ' deleted…'); }).then(function (d) { if (d) { location.reload(); } }); } }
                else if (b.getAttribute('data-dt-resolve')) { call('resolve', { post_id: b.getAttribute('data-post'), resolution: b.getAttribute('data-dt-resolve') }).then(function (r) { r.success ? location.reload() : fail(r); }); }
            });

            if (cfg.auto) { run(); }
        })();
        </script>
        <?php
    }
}
