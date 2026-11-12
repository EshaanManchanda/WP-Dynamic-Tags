<?php
/**
 * S8: uninstalling with "delete data" enabled must remove this plugin's data and nothing that belongs to other plugins.
 *
 * The cleanup used LIKE '_dt_%' / 'dt_%' on user meta (the underscore is a wildcard, and any plugin may use a dt_ prefix)
 * and unescaped transient prefixes. This test runs the real uninstall.php against the disposable test database. It
 * drops the plugin table, so it recreates it afterwards; the file name keeps it last in the run.
 */
class DT_Uninstall_Test extends WP_UnitTestCase
{
    private int $user = 0;

    /** Options the plugin writes (found by grepping get/update/add_option and the OPTION_* constants). */
    private const OWN_OPTIONS = array(
        'wp_dynamic_tags_debug_log', 'wp_dynamic_tags_legacy_writes', 'wp_dynamic_tags_legacy_pending',
        'wp_dynamic_tags_table_creation_error', 'wp_dynamic_tags_table_error', 'wp_dynamic_tags_activated',
        'wp_dynamic_tags_activation_time', 'wp_dynamic_tags_table_integration_enabled',
        'dt_group_colors', 'dt_categories_migrated', 'dt_total_exports', 'dt_total_imports', 'dt_last_export', 'dt_last_import',
    );

    private const OWN_TRANSIENTS = array('dt_api_0123456789abcdef', 'dt_export_0123456789abcdef', 'wp_dynamic_tags_data_restored', 'wp_dynamic_tags_deactivated');

    public function tear_down(): void
    {
        // uninstall.php dropped the table (DDL commits the open transaction, so this test's rows survive the usual
        // rollback): bring the table back and remove what the test created so repeated runs start clean.
        WP_Dynamic_Tags_Database_Manager::get_instance()->create_table();
        if ($this->user) {
            wp_delete_user($this->user);
        }
        foreach (array_merge(['_transient_dtxtagxgroupsx_foreign', '_transient_dt_tag_groups_demo', 'dt_other_plugin_option', 'wp_dynamic_tags_delete_on_uninstall', 'wp_dynamic_tags_last_uninstall', 'wp_dynamic_tags_data_preserved'], self::OWN_OPTIONS) as $option) {
            delete_option($option);
        }
        foreach (self::OWN_TRANSIENTS as $transient) {
            delete_transient($transient);
        }
        parent::tear_down();
    }

    public function test_uninstall_removes_only_this_plugins_user_meta_and_transients(): void
    {
        $user = $this->user = self::factory()->user->create(['role' => 'administrator']);

        // Ours
        update_user_meta($user, 'dt_menu_consolidation_dismissed', true);
        // Not ours, but matched by the old patterns (prefix "dt_", or "_dt_" with the underscore as a wildcard)
        update_user_meta($user, 'dt_other_plugin_setting', 'keep');
        update_user_meta($user, 'adtracker_last_seen', 'keep');
        update_user_meta($user, 'xdt_foo', 'keep');
        // Foreign transient that the unescaped prefix "dt_tag_groups_" would match through its wildcards
        update_option('_transient_dtxtagxgroupsx_foreign', 'keep');
        // Ours
        update_option('_transient_dt_tag_groups_demo', 'drop');

        // Everything else the plugin stores: its own options, the cached API answers and the one-off notices
        $ours = self::OWN_OPTIONS;
        foreach ($ours as $option) {
            update_option($option, 'x');
        }
        foreach (self::OWN_TRANSIENTS as $transient) {
            set_transient($transient, 'x', HOUR_IN_SECONDS);
        }
        update_option('dt_other_plugin_option', 'keep'); // same prefix, not ours

        update_option('wp_dynamic_tags_delete_on_uninstall', true);
        update_option('wp_dynamic_tags_table_version', '1.0.0');
        WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(['tag_name' => 'Backup probe', 'shortcode' => 'backup_probe', 'content' => 'student data']);

        // On a network WordPress runs uninstall.php once, for every site: a second site that chose to delete its data is cleaned too.
        $second = 0;
        if (is_multisite()) {
            $second = self::factory()->blog->create();
            switch_to_blog($second);
            update_option('wp_dynamic_tags_delete_on_uninstall', true);
            update_option('wp_dynamic_tags_debug_log', array('x'));
            restore_current_blog();
        }
        $private = wp_upload_dir()['basedir'] . '/wp-dynamic-tags-private';
        $backups_before = (array) glob($private . '/uninstall-backup-*.json');
        define('WP_UNINSTALL_PLUGIN', true);
        require dirname(__DIR__, 2) . '/uninstall.php';

        // A copy of every tag (certificate tags hold student data) must not stay in wp_options after the data was deleted: the
        // backup is the private file, written first, and the option copy it was made from is removed once the file exists.
        $backups = array_values(array_diff((array) glob($private . '/uninstall-backup-*.json'), $backups_before));
        $this->assertCount(1, $backups, 'the uninstall writes its backup to the private folder');
        $this->assertStringContainsString('backup_probe', (string) file_get_contents($backups[0]), 'and it holds the tags');
        @unlink($backups[0]);
        $this->assertFalse(get_option('wp_dynamic_tags_deletion_backup'), 'no copy of the tags stays in the options table');

        if ($second) {
            switch_to_blog($second);
            $this->assertFalse(get_option('wp_dynamic_tags_debug_log'), 'every site of the network is cleaned, not only the one the command ran on');
            foreach ((array) glob(wp_upload_dir()['basedir'] . '/wp-dynamic-tags-private/uninstall-backup-*.json') as $file) {
                @unlink($file);
            }
            restore_current_blog();
        }

        foreach ($ours as $option) {
            $this->assertFalse(get_option($option), $option . ' must not outlive an uninstall that deletes the data');
        }
        foreach (self::OWN_TRANSIENTS as $transient) {
            $this->assertFalse(get_transient($transient), $transient . ' must not outlive an uninstall that deletes the data');
        }
        $this->assertSame('keep', get_option('dt_other_plugin_option'), 'another plugin\'s dt_ option survives');
        delete_option('dt_other_plugin_option');

        $this->assertSame('', get_user_meta($user, 'dt_menu_consolidation_dismissed', true), 'our user meta is removed');
        $this->assertSame('keep', get_user_meta($user, 'dt_other_plugin_setting', true), 'another plugin\'s dt_ user meta survives');
        $this->assertSame('keep', get_user_meta($user, 'adtracker_last_seen', true), 'the "_dt_" pattern must not match through its wildcard');
        $this->assertSame('keep', get_user_meta($user, 'xdt_foo', true));
        $this->assertSame('keep', get_option('_transient_dtxtagxgroupsx_foreign'), 'a foreign transient survives');
        $this->assertFalse(get_option('_transient_dt_tag_groups_demo'), 'our transient is removed');
        $this->assertFalse(get_option('wp_dynamic_tags_table_version'), 'the stored schema version must not outlive the dropped table');
    }
}
