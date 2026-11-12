<?php
/**
 * The debug log: off by default, and when on it records a fixed whitelist of fields (tag id, shortcode, error type, context,
 * time) and nothing else: never the content of a tag, a meta value, a credential or a URL. It keeps the last 50 events.
 */
class DT_Debug_Log_Test extends WP_UnitTestCase
{
    /** @var string */
    private $previous_log;

    public function set_up(): void
    {
        parent::set_up();
        WP_Dynamic_Tags_Debug::clear();
        $this->previous_log = (string) ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'dtdbg'));
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_debug_mode');
        WP_Dynamic_Tags_Debug::clear();
        ini_set('error_log', $this->previous_log);
        parent::tear_down();
    }

    public function test_it_records_nothing_unless_debug_mode_is_on(): void
    {
        WP_Dynamic_Tags_Debug::log('render_guard', array('tag_id' => 1, 'shortcode' => 'x'));
        $this->assertSame(array(), WP_Dynamic_Tags_Debug::entries());
        $this->assertFalse(WP_Dynamic_Tags_Debug::enabled());
    }

    public function test_it_keeps_only_whitelisted_fields(): void
    {
        add_filter('dt_debug_mode', '__return_true');

        WP_Dynamic_Tags_Debug::log('render_guard', array(
            'tag_id' => 7, 'shortcode' => 'probe', 'error_type' => 'cycle',
            'content' => 'SECRET tag content', 'meta_value' => 'SECRET meta', 'api_key' => 'SECRET key', 'url' => 'https://x.test/?token=SECRET',
        ));

        $entries = WP_Dynamic_Tags_Debug::entries();
        $this->assertCount(1, $entries);
        $this->assertSame(array('time', 'event', 'tag_id', 'shortcode', 'error_type', 'context'), array_keys($entries[0]));
        $this->assertStringNotContainsString('SECRET', wp_json_encode($entries));
        $this->assertSame('front', $entries[0]['context']);
    }

    public function test_it_keeps_the_last_fifty_events_and_can_be_cleared(): void
    {
        add_filter('dt_debug_mode', '__return_true');
        for ($i = 1; $i <= 60; $i++) {
            WP_Dynamic_Tags_Debug::log('event', array('tag_id' => $i));
        }
        $entries = WP_Dynamic_Tags_Debug::entries();
        $this->assertCount(50, $entries);
        $this->assertSame('11', $entries[0]['tag_id']);
        $this->assertSame('60', $entries[49]['tag_id']);

        WP_Dynamic_Tags_Debug::clear();
        $this->assertSame(array(), WP_Dynamic_Tags_Debug::entries());
    }

    public function test_a_render_guard_trip_is_logged_without_the_content(): void
    {
        global $wpdb;
        add_filter('dt_debug_mode', '__return_true');
        $now = current_time('mysql');
        // A tag that renders itself, written straight into the table (the Tag Service would refuse it).
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array(
            'tag_name' => 'dbg_loop', 'shortcode' => 'dbg_loop', 'content' => 'SECRET before [dbg_loop] after', 'description' => '', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ));
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        do_shortcode('[dbg_loop cache="false"]');

        $entries = WP_Dynamic_Tags_Debug::entries();
        $this->assertNotEmpty($entries);
        $this->assertSame('render_guard', $entries[0]['event']);
        $this->assertSame('cycle', $entries[0]['error_type']);
        $this->assertSame('dbg_loop', $entries[0]['shortcode']);
        $this->assertStringNotContainsString('SECRET', wp_json_encode($entries));
    }
}
