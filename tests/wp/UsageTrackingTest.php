<?php
/**
 * Release gate 4: rendering writes nothing. Counting how often a tag is used used to run an UPDATE on every render (40
 * renders = 40 writes in the baseline). Counting is now OFF by default; when it is switched on (option
 * wp_dynamic_tags_track_usage, or the filter dt_track_usage) the counts are kept in memory and written once, at shutdown, as
 * one UPDATE per distinct tag, never on admin, AJAX, cron or REST requests, and a failing write never reaches rendering.
 */
class DT_Usage_Tracking_Test extends WP_UnitTestCase
{
    /** @var int */
    private $writes = 0;
    /** @var int[] */
    private $used = array();

    public function set_up(): void
    {
        parent::set_up();
        $this->used = array();
        add_action('wp_dynamic_tags_tag_used', function ($id) {
            $this->used[] = (int) $id;
        });
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_track_usage');
        remove_all_filters('wp_doing_ajax');
        remove_all_filters('wp_doing_cron');
        remove_all_actions('wp_dynamic_tags_tag_used');
        delete_option('wp_dynamic_tags_track_usage');
        set_current_screen('front');
        parent::tear_down();
    }

    private function seed(string $code): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array(
            'tag_name' => $code, 'shortcode' => $code, 'content' => 'x ' . $code, 'description' => 'usage test', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => '2026-01-01 00:00:00', 'modified_date' => '2026-01-01 00:00:00',
        ));
        $id = (int) $wpdb->insert_id;
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
        return $id;
    }

    private function row(int $id): object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT usage_count, last_used_date, modified_date FROM {$wpdb->prefix}dynamic_tags WHERE id = %d", $id));
    }

    private function writes_during(callable $work): int
    {
        $this->writes = 0;
        $counter = function ($sql) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $sql)) {
                $this->writes++;
            }
            return $sql;
        };
        add_filter('query', $counter);
        $work();
        remove_filter('query', $counter);
        return $this->writes;
    }

    private function render_three_times(string $code): void
    {
        for ($i = 0; $i < 3; $i++) {
            do_shortcode('[' . $code . ' cache="false"]');
        }
    }

    public function test_by_default_rendering_and_shutdown_write_nothing(): void
    {
        $id = $this->seed('use_off');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();

        $writes = $this->writes_during(function () use ($processor) {
            $this->render_three_times('use_off');
            $processor->flush_usage();
        });

        $this->assertSame(0, $writes);
        $this->assertSame('0', (string) $this->row($id)->usage_count);
        $this->assertSame(array($id, $id, $id), $this->used, 'the in-memory action still fires on every render');
    }

    public function test_when_enabled_rendering_writes_nothing_and_shutdown_writes_one_batched_update(): void
    {
        $id = $this->seed('use_on');
        $other = $this->seed('use_on_two');
        add_filter('dt_track_usage', '__return_true');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();

        $during = $this->writes_during(function () {
            $this->render_three_times('use_on');
            do_shortcode('[use_on_two cache="false"]');
        });
        $this->assertSame(0, $during, 'a render must not write');
        $this->assertSame('0', (string) $this->row($id)->usage_count);

        $flush = $this->writes_during(function () use ($processor) {
            $processor->flush_usage();
        });
        $this->assertSame(2, $flush, 'one UPDATE per distinct tag, not per render');
        $this->assertSame('3', (string) $this->row($id)->usage_count);
        $this->assertSame('1', (string) $this->row($other)->usage_count);
        $this->assertNotEmpty($this->row($id)->last_used_date);
        $this->assertSame('2026-01-01 00:00:00', $this->row($id)->modified_date, 'a view is not a modification of the tag');

        $this->assertSame(0, $this->writes_during(function () use ($processor) {
            $processor->flush_usage();
        }), 'the counts are gone after the flush');
    }

    public function test_the_option_switches_counting_on(): void
    {
        $id = $this->seed('use_option');
        update_option('wp_dynamic_tags_track_usage', 1);
        $this->render_three_times('use_option');
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->flush_usage();

        $this->assertSame('3', (string) $this->row($id)->usage_count);
    }

    public function test_admin_ajax_and_cron_requests_are_never_counted(): void
    {
        $id = $this->seed('use_ctx');
        add_filter('dt_track_usage', '__return_true');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();

        set_current_screen('dashboard'); // is_admin() is true
        $this->render_three_times('use_ctx');
        set_current_screen('front');
        add_filter('wp_doing_ajax', '__return_true');
        $this->render_three_times('use_ctx');
        remove_all_filters('wp_doing_ajax');
        add_filter('wp_doing_cron', '__return_true');
        $this->render_three_times('use_ctx');

        $this->assertSame(0, $this->writes_during(function () use ($processor) {
            $processor->flush_usage();
        }));
        $this->assertSame('0', (string) $this->row($id)->usage_count);
    }

    public function test_a_failing_write_never_reaches_rendering(): void
    {
        $this->seed('use_fail');
        add_filter('dt_track_usage', '__return_true');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $this->render_three_times('use_fail');

        add_filter('query', static fn($sql) => stripos((string) $sql, 'UPDATE') === 0 ? 'UPDATE dt_no_such_table SET x = 1' : $sql);
        $processor->flush_usage(); // must neither throw nor print a database error

        $this->assertSame('x use_fail', do_shortcode('[use_fail cache="false"]'));
    }
}
