<?php
/**
 * The notice on All Tags and the tag form when a site has more active tags than its server is advised to carry: the same
 * verdict as Settings > Tags and your server (WP_Dynamic_Tags_Capacity_Advisor::assess()), and nothing below the line.
 * Inactive tags are not loaded on a request, so they do not count.
 */
class DT_Capacity_Notice_Test extends WP_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Admin */
    private $admin;

    public function set_up(): void
    {
        parent::set_up();
        $this->admin = new WP_Dynamic_Tags_Admin();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('dashboard');
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_page_cache_active');
        $_GET = $_REQUEST = array();
        set_current_screen('front');
        parent::tear_down();
    }

    /** Replaces the table's rows with $active active and $inactive inactive tags (the test transaction rolls it back). */
    private function seed_rows(int $active, int $inactive = 0): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dynamic_tags';
        $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table's own name
        $now = current_time('mysql');
        $rows = array();
        for ($i = 1; $i <= $active + $inactive; $i++) {
            $rows[] = $wpdb->prepare('(%s,%s,%s,%s,%d,%s,%d,%d,%s,%s,%s)', "cn_$i", "cn_$i", "content $i", 'seed', 0, $i <= $active ? 'active' : 'inactive', 0, 0, '', $now, $now);
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $wpdb->query("INSERT INTO {$table} (tag_name, shortcode, content, description, category_id, status, usage_count, post_id, meta_data, created_date, modified_date) VALUES " . implode(',', $chunk)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table's own name; every value went through prepare()
        }
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
    }

    private function page(string $method): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $this->admin->$method();
            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function test_a_site_at_the_recommended_maximum_gets_no_notice(): void
    {
        $this->seed_rows(5000);

        $this->assertStringNotContainsString('dt-capacity-notice', $this->page('page_list'));
        $this->assertStringNotContainsString('dt-capacity-notice', $this->page('page_edit'));
    }

    public function test_one_tag_over_the_maximum_gets_a_warning_that_names_both_numbers_and_links_to_the_advice(): void
    {
        $this->seed_rows(5001);

        foreach (array('page_list', 'page_edit') as $screen) {
            $html = $this->page($screen);
            $this->assertStringContainsString('notice-warning dt-capacity-notice', $html, $screen);
            $this->assertStringContainsString('5,001 active tags', $html);
            $this->assertStringContainsString('recommended maximum of 5,000', $html);
            $this->assertStringContainsString('page=dt-settings#dt-capacity', $html, 'it links to the section that explains the cost');
        }
    }

    public function test_a_page_cache_raises_the_line(): void
    {
        $this->seed_rows(5001);
        add_filter('dt_page_cache_active', '__return_true');

        $this->assertStringNotContainsString('dt-capacity-notice', $this->page('page_list'));
    }

    public function test_a_very_large_site_gets_an_error_level_notice(): void
    {
        $this->seed_rows(20001);

        $this->assertStringContainsString('notice-error dt-capacity-notice', $this->page('page_list'));
    }

    public function test_inactive_tags_do_not_count(): void
    {
        $this->seed_rows(100, 6000);

        $this->assertStringNotContainsString('dt-capacity-notice', $this->page('page_list'));
    }

    public function test_a_delegate_gets_the_warning_without_a_link_to_a_screen_they_cannot_open(): void
    {
        add_filter('dt_manage_capability', static fn() => 'edit_pages');
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $this->seed_rows(5001);

        $html = $this->page('page_list');
        remove_all_filters('dt_manage_capability');

        $this->assertStringContainsString('dt-capacity-notice', $html);
        $this->assertStringNotContainsString('page=dt-settings', $html);
    }

    public function test_the_settings_section_the_notice_links_to_exists(): void
    {
        ob_start();
        (new WP_Dynamic_Tags_Settings_Page())->settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="dt-capacity"', $html);
    }
}
