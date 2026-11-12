<?php
/**
 * Release gate 4 (no writes on a normal request) and the single registrar.
 *
 * Registering the shortcodes used to set two 3-second transients (dt_registration_lock, dt_table_registration_lock) to stop
 * the two registrars from running twice, i.e. at least two database writes on every request, and the post path added a
 * third (its tag-list transient). The table processor is now the only registrar of per-tag shortcodes, registration is
 * idempotent (calling it twice is harmless) and writes nothing.
 *
 * Until the read-only legacy loader exists (plan Phase 3d) a tag that exists only as a dynamic_tag post, with no row in
 * the table, has no shortcode: the migration (or the legacy-write capture) turns it into a row.
 */
class DT_Registration_Test extends WP_UnitTestCase
{
    /** @var int */
    private $writes = 0;

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

    private function seed_row(string $code): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array(
            'tag_name' => $code, 'shortcode' => $code, 'content' => 'registered ' . $code, 'description' => '', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ));
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
    }

    public function test_registering_shortcodes_writes_nothing_to_the_database(): void
    {
        $this->seed_row('reg_probe');
        // Leftovers of older versions are cleaned before measuring, so a lock that is still honoured cannot hide a write.
        delete_transient('dt_registration_lock');
        delete_transient('dt_table_registration_lock');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();

        $writes = $this->writes_during(function () use ($processor, $plugin) {
            $processor->register_table_shortcodes();
            $processor->register_table_shortcodes();
            $plugin->register_shortcodes();
            $plugin->register_shortcodes();
        });

        $this->assertSame(0, $writes, 'registration must not write (it set two lock transients)');
        $this->assertTrue(shortcode_exists('reg_probe'));
        $this->assertTrue(shortcode_exists('dt'), 'the core [dt_*] shortcodes are registered as well');
        $this->assertSame('registered reg_probe', do_shortcode('[reg_probe]'));
    }

    public function test_registration_is_idempotent_without_a_lock(): void
    {
        delete_transient('dt_table_registration_lock');
        $this->seed_row('reg_twice');
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();

        $processor->register_table_shortcodes();
        $processor->register_table_shortcodes();

        $this->assertSame('registered reg_twice', do_shortcode('[reg_twice]'));
        $this->assertSame(1, count(array_keys($processor->get_registered_shortcodes(), $processor->get_registered_shortcodes()['reg_twice'] ?? -1, true)));
    }

    public function test_a_tag_post_without_a_table_row_is_not_registered_by_the_main_plugin(): void
    {
        global $wpdb;
        // A published tag post inserted without any save_post hook, so the bridge cannot give it a row.
        $wpdb->insert($wpdb->posts, array(
            'post_author' => 1, 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true),
            'post_content' => 'POST ONLY', 'post_title' => 'post_only_probe', 'post_excerpt' => '', 'post_status' => 'publish',
            'post_name' => 'post-only-probe', 'post_type' => 'dynamic_tag', 'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true), 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '',
        ));

        update_option('wp_dynamic_tags_legacy_pending', '0'); // the read-only loader is off
        WP_Dynamic_Tags_Plugin::get_instance()->register_shortcodes();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

        $this->assertFalse(shortcode_exists('post_only_probe'), 'the table processor is the only registrar of per-tag shortcodes');
    }
}
