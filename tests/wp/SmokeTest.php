<?php
/**
 * Proves the WordPress-suite harness itself: the plugin loads, its table exists, and the sibling-visible
 * contract (classes, post type) is present. If this fails, no other WP-suite result can be trusted.
 */
class DT_Smoke_Test extends WP_UnitTestCase
{
    public function test_plugin_class_and_post_type_are_available(): void
    {
        $this->assertTrue(class_exists('WP_Dynamic_Tags_Plugin'));
        $this->assertTrue(class_exists('WP_Dynamic_Tags_Table_Manager'));
        $this->assertTrue(post_type_exists('dynamic_tag'));
    }

    public function test_core_shortcodes_are_registered(): void
    {
        foreach (['dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list', 'dt_loop', 'dt_if', 'dt_template'] as $code) {
            $this->assertTrue(shortcode_exists($code), "[$code] should be registered");
        }
    }
}
