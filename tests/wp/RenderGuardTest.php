<?php
/**
 * L8: table tags may reference other tags (and themselves) through nested shortcodes. Before the guard a cycle
 * rendered until PHP ran out of memory and WordPress answered with its "critical error" page for every visitor
 * (reproduced with tests/live/recursion-probe.php: exit 255 for self, pair and chain). The renderer now keeps a
 * per-request stack of the tags being rendered, a maximum depth and a maximum output size.
 *
 * These tests would take the whole PHPUnit process down on the unguarded code, which is why the red evidence was
 * taken with the probe script in a child process instead.
 */
class DT_Render_Guard_Test extends WP_UnitTestCase
{
    private function seed(array $rows): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dynamic_tags';
        $now = current_time('mysql');
        foreach ($rows as $code => $content) {
            $wpdb->insert($table, [
                'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'guard test', 'category_id' => 0,
                'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
            ]);
        }
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        delete_transient('dt_table_registration_lock');
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
    }

    public function test_a_tag_that_references_itself_renders_once(): void
    {
        $this->seed(['g_self' => 'A[g_self]']);
        $this->assertSame('A', do_shortcode('[g_self]'));
    }

    public function test_two_tags_that_reference_each_other_stop_at_the_cycle(): void
    {
        $this->seed(['g_pa' => 'A[g_pb]', 'g_pb' => 'B[g_pa]']);
        $this->assertSame('AB', do_shortcode('[g_pa]'));
    }

    public function test_a_three_tag_cycle_stops_at_the_cycle(): void
    {
        $this->seed(['g_ca' => 'A[g_cb]', 'g_cb' => 'B[g_cc]', 'g_cc' => 'C[g_ca]']);
        $this->assertSame('ABC', do_shortcode('[g_ca]'));
    }

    public function test_using_the_same_tag_twice_in_a_row_is_not_a_cycle(): void
    {
        $this->seed(['g_x' => 'X', 'g_twice' => '[g_x]-[g_x]']);
        $this->assertSame('X-X', do_shortcode('[g_twice]'));
    }

    public function test_a_diamond_is_not_a_cycle(): void
    {
        $this->seed(['g_leaf' => 'L', 'g_l' => '(l:[g_leaf])', 'g_r' => '(r:[g_leaf])', 'g_top' => '[g_l][g_r]']);
        $this->assertSame('(l:L)(r:L)', do_shortcode('[g_top]'));
    }

    public function test_nesting_is_limited_to_ten_levels_by_default(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows['g_d' . $i] = $i < 25 ? 'L' . $i . '[g_d' . ($i + 1) . ']' : 'END';
        }
        $this->seed($rows);
        $this->assertSame('L1L2L3L4L5L6L7L8L9L10', do_shortcode('[g_d1]'));
    }

    public function test_the_depth_limit_can_be_changed_with_a_filter(): void
    {
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows['g_f' . $i] = $i < 6 ? 'F' . $i . '[g_f' . ($i + 1) . ']' : 'END';
        }
        $this->seed($rows);
        add_filter('dt_max_render_depth', static fn() => 3);
        $this->assertSame('F1F2F3', do_shortcode('[g_f1]'));
        remove_all_filters('dt_max_render_depth');
        $this->assertSame('F1F2F3F4F5END', do_shortcode('[g_f1 cache="false"]'));
    }

    public function test_output_larger_than_the_size_limit_is_dropped(): void
    {
        $this->seed(['g_big' => str_repeat('y', 700000), 'g_parent' => '[g_big][g_big][g_big]']);
        $out = do_shortcode('[g_parent]');
        $this->assertLessThanOrEqual(1048576, strlen($out));
        $this->assertSame('', $out);
    }

    public function test_the_default_attribute_is_used_when_the_guard_trips(): void
    {
        $this->seed(['g_dflt' => 'A[g_dflt default="STOP"]']);
        $this->assertSame('ASTOP', do_shortcode('[g_dflt]'));
    }

    // ------------------------------------------------------------------ L4

    public function test_shortcodes_are_registered_on_rest_requests_by_default(): void
    {
        $this->assertTrue(WP_Dynamic_Tags_Plugin::should_register_shortcodes(true), 'REST-rendered content needs the shortcodes');
        $this->assertTrue(WP_Dynamic_Tags_Plugin::should_register_shortcodes(false));
    }

    public function test_a_site_can_opt_out_of_registering_on_rest_requests(): void
    {
        add_filter('dt_register_shortcodes_on_rest', '__return_false');
        $this->assertFalse(WP_Dynamic_Tags_Plugin::should_register_shortcodes(true));
        $this->assertTrue(WP_Dynamic_Tags_Plugin::should_register_shortcodes(false), 'normal requests are not affected by the opt-out');
        remove_filter('dt_register_shortcodes_on_rest', '__return_false');
    }
}
