<?php
/**
 * F1 / L10: the plugin registers one shortcode per tag. It must never remove or replace a shortcode that another plugin
 * (or WordPress core) registered, and its cleanup must only ever touch shortcodes it owns. Before the fix both registrars
 * called remove_shortcode() on any name that already existed, and the post-path cleanup also removed hardcoded
 * math_olympiad_* / science_olympiad_* / general_* names whoever owned them.
 */
class DT_Foreign_Shortcode_Test extends WP_UnitTestCase
{
    private function seed_row(string $code, string $content): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', [
            'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'foreign test', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ]);
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        delete_transient('dt_table_registration_lock');
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_allow_shortcode_override');
        foreach (['foreign_code', 'math_olympiad_foo', 'general_foo', 'foreign_two'] as $name) {
            remove_shortcode($name);
        }
        parent::tear_down();
    }

    public function test_ownership_is_decided_by_who_registered_the_callback(): void
    {
        add_shortcode('foreign_code', static fn() => 'FOREIGN');
        $this->assertFalse(WP_Dynamic_Tags_Plugin::shortcode_is_ours('foreign_code'));
        $this->assertFalse(WP_Dynamic_Tags_Plugin::shortcode_is_ours('gallery'), 'WordPress core shortcodes are not ours');
        $this->assertFalse(WP_Dynamic_Tags_Plugin::shortcode_is_ours('not_registered_at_all'));
        $this->assertTrue(WP_Dynamic_Tags_Plugin::shortcode_is_ours('dt_loop'), 'the core [dt_*] shortcodes are ours');
    }

    public function test_a_table_tag_does_not_replace_another_plugins_shortcode(): void
    {
        add_shortcode('foreign_code', static fn() => 'FOREIGN');
        $this->seed_row('foreign_code', 'OURS');
        $this->assertSame('FOREIGN', do_shortcode('[foreign_code]'));
        $this->assertArrayHasKey('foreign_code', WP_Dynamic_Tags_Plugin::get_shortcode_conflicts());
    }

    public function test_a_site_can_allow_the_override_on_purpose(): void
    {
        add_shortcode('foreign_two', static fn() => 'FOREIGN');
        add_filter('dt_allow_shortcode_override', '__return_true');
        $this->seed_row('foreign_two', 'OURS');
        $this->assertSame('OURS', do_shortcode('[foreign_two]'));
    }

    public function test_the_post_path_cleanup_does_not_remove_hardcoded_names_owned_by_others(): void
    {
        add_shortcode('math_olympiad_foo', static fn() => 'FOREIGN-MO');
        add_shortcode('general_foo', static fn() => 'FOREIGN-GEN');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wp_insert_post(['post_title' => 'foo', 'post_content' => 'x', 'post_status' => 'publish', 'post_type' => 'dynamic_tag']);
        delete_transient('dt_registration_lock');
        WP_Dynamic_Tags_Plugin::get_instance()->force_refresh_shortcodes();

        $this->assertSame('FOREIGN-MO', do_shortcode('[math_olympiad_foo]'));
        $this->assertSame('FOREIGN-GEN', do_shortcode('[general_foo]'));
    }
}
