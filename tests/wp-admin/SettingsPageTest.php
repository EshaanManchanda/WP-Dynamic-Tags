<?php
/**
 * The Settings page explains how tags work, why a lot of them slow a site down, and where this site stands (live counts,
 * PHP memory limit, page cache), with recommended tag counts by kind of hosting. It also stopped counting legacy tag posts:
 * tags only live in the table.
 */
class DT_Settings_Page_Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('dashboard');
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_page_cache_active');
        set_current_screen('front');
        parent::tear_down();
    }

    private function render(): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (new WP_Dynamic_Tags_Settings_Page())->settings_page();
            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
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
            $rows[] = $wpdb->prepare('(%s,%s,%s,%s,%d,%s,%d,%d,%s,%s,%s)', "st_$i", "st_$i", "content $i", 'seed', 0, $i <= $active ? 'active' : 'inactive', 0, 0, '', $now, $now);
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $wpdb->query("INSERT INTO {$table} (tag_name, shortcode, content, description, category_id, status, usage_count, post_id, meta_data, created_date, modified_date) VALUES " . implode(',', $chunk)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table's own name; every value went through prepare()
        }
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
    }

    private function assertBadge(string $html, string $class, string $text): void
    {
        $this->assertMatchesRegularExpression('/<span class="dt-badge ' . preg_quote($class, '/') . '">' . preg_quote($text, '/') . '<\/span>/', $html);
    }

    // ------------------------------------------------------------------ what the page explains

    public function test_the_page_explains_how_tags_work_and_why_many_tags_cost(): void
    {
        $html = $this->render();
        foreach (array('How Dynamic Tags works', 'Why a lot of tags can slow your site down', 'Your site now', 'Recommended tag counts by hosting type') as $heading) {
            $this->assertStringContainsString('<h3>' . $heading . '</h3>', $html);
        }
        $this->assertStringContainsString('Inactive tags are not loaded', $html);
        $this->assertStringContainsString('never writes to the database', $html);
    }

    public function test_the_measured_cost_table_lists_every_size(): void
    {
        $html = $this->render();
        foreach (array('1,000', '5,000', '10,000', '20,000', '50,000') as $size) {
            $this->assertMatchesRegularExpression('/<td>' . preg_quote($size, '/') . '<\/td>/', $html, "a row for $size tags");
        }
        $this->assertStringContainsString('Shared hosting can be about twice as slow', $html);
    }

    public function test_the_hosting_table_names_every_kind_and_says_which_row_is_measured(): void
    {
        $html = $this->render();
        foreach (array('Entry shared hosting', 'Mid-range shared or cloud hosting', 'VPS or managed WordPress hosting', 'Large dedicated server') as $kind) {
            $this->assertStringContainsString($kind, $html);
        }
        $this->assertStringContainsString('the other rows are estimates', $html);
    }

    // ------------------------------------------------------------------ the live part

    public function test_it_shows_the_live_counts_of_the_table(): void
    {
        $this->seed_rows(3, 1);
        $html = $this->render();
        $this->assertStringContainsString('4 (3 active, 1 inactive)', $html);
        foreach (array('Tag groups', 'Shortcodes registered in this request', 'Time to load and register the tags in this request', 'PHP version', 'PHP memory limit', 'Page cache') as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_legacy_tag_posts_are_no_longer_counted(): void
    {
        self::factory()->post->create(array('post_type' => 'dynamic_tag', 'post_status' => 'publish', 'post_title' => 'Legacy one'));
        $html = $this->render();
        $this->assertStringNotContainsString('Published Tags (Posts)', $html);
        $this->assertStringNotContainsString('Tags in Database Table', $html);
    }

    public function test_a_normal_site_is_told_it_is_fine(): void
    {
        $this->seed_rows(20);
        $html = $this->render();
        $this->assertBadge($html, 'dt-badge-on', 'Fine');
        $this->assertStringContainsString('recommended maximum of 5,000', $html);
        $this->assertStringContainsString('Next to nothing (under 0.1 MB and 1 ms per uncached page)', $html, '20 tags must not read as "0.0 MB and 0 ms"');
    }

    public function test_the_estimate_row_gives_numbers_once_the_tags_add_something(): void
    {
        $this->seed_rows(2000);
        $html = $this->render();
        $this->assertStringContainsString('about 4.1 MB and 22 ms per uncached page', $html);
        $this->assertStringNotContainsString('Next to nothing', $html);
    }

    public function test_just_over_the_safe_maximum_is_a_watch_and_a_page_cache_lifts_it(): void
    {
        $this->seed_rows(5001);
        $this->assertBadge($this->render(), 'dt-badge-warn', 'Watch');

        add_filter('dt_page_cache_active', '__return_true');
        $html = $this->render();
        $this->assertBadge($html, 'dt-badge-on', 'Fine');
        $this->assertStringContainsString('recommended maximum of 10,000', $html);
        $this->assertStringContainsString('<td>Detected</td>', $html);
        $this->assertStringNotContainsString('Not detected', $html);
    }

    public function test_a_very_large_site_is_told_it_has_too_many(): void
    {
        $this->seed_rows(20001);
        add_filter('dt_page_cache_active', '__return_true');
        $this->assertBadge($this->render(), 'dt-badge-bad', 'Too many');
    }

    public function test_inactive_tags_do_not_count_towards_the_level(): void
    {
        $this->seed_rows(100, 6000);
        $html = $this->render();
        $this->assertStringContainsString('6,100 (100 active, 6,000 inactive)', $html);
        $this->assertBadge($html, 'dt-badge-on', 'Fine');
    }

    // ------------------------------------------------------------------ the License box

    public function test_a_free_site_sees_its_use_of_the_limit_and_what_a_license_check_sends(): void
    {
        remove_all_filters('pre_option_dt_plan');
        update_option('dt_plan', 'free');
        $this->seed_rows(4);

        $html = $this->render();

        $this->assertStringContainsString('Tags you have created yourself: 4 of 15', $html);
        $this->assertStringContainsString('send the key and this site&#039;s address to https://tlvapi.techlovev.in, and nothing else', $html);
        $this->assertStringContainsString('Without a key nothing is sent', $html);
        $this->assertStringNotContainsString('Get a Paid key', $html, 'no pricing page is known: no link is invented');

        add_filter('dt_upgrade_url', static fn() => 'https://example.test/plans');
        $this->assertStringContainsString('href="https://example.test/plans"', $this->render());
        remove_all_filters('dt_upgrade_url');
    }

    public function test_a_paid_site_sees_its_key_masked_and_no_use_of_the_limit(): void
    {
        update_option('dt_license_key', 'PAID-ABCDEFGH-1234');

        $html = $this->render();

        $this->assertStringNotContainsString('PAID-ABCDEFGH-1234', $html, 'the page never prints the whole key');
        $this->assertStringContainsString('PAID-' . str_repeat("\u{2022}", 9) . '1234', $html);
        $this->assertStringNotContainsString('Tags you have created yourself', $html);
    }

    public function test_the_license_server_address_must_be_https_and_the_old_one_is_kept_otherwise(): void
    {
        $page = new WP_Dynamic_Tags_Settings_Page();

        $this->assertSame('', $page->sanitize_license_server_url('  '));
        $this->assertSame('https://lic.example.test/base', $page->sanitize_license_server_url(' https://lic.example.test/base '));

        update_option('dt_license_server_url', 'https://keep.example.test');
        $this->assertSame('https://keep.example.test', $page->sanitize_license_server_url('http://insecure.example.test'));
        $this->assertSame('https://keep.example.test', $page->sanitize_license_server_url('javascript:alert(1)'));
        $codes = wp_list_pluck(get_settings_errors('wp_dynamic_tags_settings'), 'code');
        $this->assertContains('dt_license_server_url', $codes);
    }

    public function test_the_mask_hides_the_middle_of_a_key(): void
    {
        $this->assertSame('', DT_License_Manager::mask_key(''));
        $this->assertSame("\u{2022}\u{2022}\u{2022}", DT_License_Manager::mask_key('abc'), 'a short key is hidden entirely');
        $this->assertSame('PAID-' . str_repeat("\u{2022}", 9) . '1234', DT_License_Manager::mask_key('PAID-ABCDEFGH-1234'));
    }

    // ------------------------------------------------------------------ who may open it

    public function test_a_user_without_manage_options_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $this->expectException(WPDieException::class);
        $this->render();
    }
}
