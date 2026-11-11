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

    public function tear_down(): void
    {
        // uninstall.php dropped the table (DDL commits the open transaction, so this test's rows survive the usual
        // rollback): bring the table back and remove what the test created so repeated runs start clean.
        WP_Dynamic_Tags_Database_Manager::get_instance()->create_table();
        if ($this->user) {
            wp_delete_user($this->user);
        }
        foreach (['_transient_dtxtagxgroupsx_foreign', '_transient_dt_tag_groups_demo', 'wp_dynamic_tags_delete_on_uninstall', 'wp_dynamic_tags_last_uninstall', 'wp_dynamic_tags_data_preserved'] as $option) {
            delete_option($option);
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

        update_option('wp_dynamic_tags_delete_on_uninstall', true);
        update_option('wp_dynamic_tags_table_version', '1.0.0');
        define('WP_UNINSTALL_PLUGIN', true);
        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertSame('', get_user_meta($user, 'dt_menu_consolidation_dismissed', true), 'our user meta is removed');
        $this->assertSame('keep', get_user_meta($user, 'dt_other_plugin_setting', true), 'another plugin\'s dt_ user meta survives');
        $this->assertSame('keep', get_user_meta($user, 'adtracker_last_seen', true), 'the "_dt_" pattern must not match through its wildcard');
        $this->assertSame('keep', get_user_meta($user, 'xdt_foo', true));
        $this->assertSame('keep', get_option('_transient_dtxtagxgroupsx_foreign'), 'a foreign transient survives');
        $this->assertFalse(get_option('_transient_dt_tag_groups_demo'), 'our transient is removed');
        $this->assertFalse(get_option('wp_dynamic_tags_table_version'), 'the stored schema version must not outlive the dropped table');
    }
}
