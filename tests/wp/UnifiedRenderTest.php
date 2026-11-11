<?php
/**
 * One renderer for every tag. A tag now always goes: table-only tokens -> the placeholder engine (tokens, formatters,
 * {if}{else}{/if} blocks) -> nested shortcodes -> the format attribute. Tags that came from dynamic_tag posts always went
 * through the engine; table-native tags never did, so {meta:}, {acf:}, {wc:}, formatters such as {site_name|upper} and
 * conditions such as !negation and time_between stayed raw in them.
 *
 * S10: the output of a tag is not run through wp_kses_post by default (an administrator writes tags, and filtering would
 * strip scripts and iframes they added on purpose); the filter dt_kses_tag_output turns it on, per tag.
 */
class DT_Unified_Render_Test extends WP_UnitTestCase
{
    private function seed(string $code, string $content, int $usage = 0): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array(
            'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'unified test', 'category_id' => 0,
            'status' => 'active', 'usage_count' => $usage, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
        return $id;
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_kses_tag_output');
        parent::tear_down();
    }

    // ------------------------------------------------------------------ the engine now runs for table-native tags

    public function test_a_table_native_tag_gets_formatters(): void
    {
        $this->seed('u_fmt', '{site_name|upper}');
        $this->assertSame(strtoupper(get_bloginfo('name')), do_shortcode('[u_fmt cache="false"]'));
    }

    public function test_a_table_native_tag_can_read_post_meta(): void
    {
        $post = self::factory()->post->create(array('post_title' => 'Meta host'));
        update_post_meta($post, 'u_price', '29.99');
        $this->go_to(get_permalink($post));
        $GLOBALS['post'] = get_post($post);
        $this->seed('u_meta', '{meta:u_price}|{meta:u_missing??none}');

        $this->assertSame('29.99|none', do_shortcode('[u_meta cache="false"]'));
    }

    public function test_the_tokens_only_a_table_row_can_answer_still_work(): void
    {
        $id = $this->seed('u_tok', '{tag_name}|{tag_id}|{usage_count}|{user_id}', 7);
        wp_set_current_user(0);
        $this->assertSame("u_tok|$id|7|0", do_shortcode('[u_tok cache="false"]'));

        $user = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($user);
        $this->assertSame("u_tok|$id|7|$user", do_shortcode('[u_tok cache="false"]'));
    }

    public function test_nested_shortcodes_are_expanded_after_the_tokens(): void
    {
        $this->seed('u_inner', 'inner-{site_name|lower}');
        $this->seed('u_outer', 'see [u_inner cache="false"]');
        $this->assertSame('see inner-' . strtolower(get_bloginfo('name')), do_shortcode('[u_outer cache="false"]'));
    }

    // ------------------------------------------------------------------ one condition set, in the engine

    public function test_the_engine_understands_negation(): void
    {
        $engine = WP_Dynamic_Tags_Placeholders::get_instance();
        wp_set_current_user(0);
        $this->assertSame('OUT', $engine->process_placeholders('{if:!user_logged_in}OUT{else}IN{/if}'));
        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));
        $this->assertSame('IN', $engine->process_placeholders('{if:!user_logged_in}OUT{else}IN{/if}'));
        $this->assertSame('OUT', $engine->process_placeholders('{if:!is_admin}OUT{else}IN{/if}'), 'a subscriber is not an administrator');
    }

    public function test_the_engine_understands_time_between_including_across_midnight(): void
    {
        $engine = WP_Dynamic_Tags_Placeholders::get_instance();
        // Minute resolution, both ends inclusive: this window always contains "now", and so does its midnight-crossing twin.
        $this->assertSame('IN', $engine->process_placeholders('{if:time_between(00:00,23:59)}IN{else}OUT{/if}'));
        $this->assertSame('IN', $engine->process_placeholders('{if:time_between(23:59,23:58)}IN{else}OUT{/if}'));
        $this->assertSame('OUT', $engine->process_placeholders('{if:!time_between(00:00,23:59)}IN{else}OUT{/if}'));
    }

    // ------------------------------------------------------------------ S10

    public function test_tag_output_is_not_filtered_by_default_but_can_be_per_tag(): void
    {
        $this->seed('u_kses', '<b>bold</b><script>alert(1)</script>');
        $this->assertSame('<b>bold</b><script>alert(1)</script>', do_shortcode('[u_kses cache="false"]'), 'an administrator\'s markup is kept');

        add_filter('dt_kses_tag_output', static fn($filter, $tag) => $tag->shortcode === 'u_kses', 10, 2);
        $out = do_shortcode('[u_kses cache="false"]');
        $this->assertStringContainsString('<b>bold</b>', $out);
        $this->assertStringNotContainsString('<script', $out, 'the filter turns wp_kses_post on for that tag');
    }
}
