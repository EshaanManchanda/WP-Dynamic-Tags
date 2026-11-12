<?php
/**
 * L1 and L2 end to end, through the two real renderers: the placeholder engine used by post-origin tags
 * (dt_process_value) and the table renderer used by table-native tags.
 */
class DT_Conditional_Render_Test extends WP_UnitTestCase
{
    private function seed(array $rows): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dynamic_tags';
        $now = current_time('mysql');
        foreach ($rows as $code => $content) {
            $wpdb->insert($table, [
                'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'cond test', 'category_id' => 0,
                'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
            ]);
        }
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        delete_transient('dt_table_registration_lock');
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
    }

    private function login(string $role): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }

    // ------------------------------------------------------------------ L1, placeholder engine (post-origin tags)

    public function test_placeholder_engine_keeps_sequential_blocks_apart(): void
    {
        $this->login('subscriber');
        $engine = WP_Dynamic_Tags_Placeholders::get_instance();
        $out = $engine->process_placeholders('A{if:user_logged_in}x{/if} m {if:is_admin}y{else}n{/if} e');
        $this->assertSame('Ax m n e', $out);
    }

    public function test_placeholder_engine_supports_nested_blocks(): void
    {
        $this->login('administrator');
        $engine = WP_Dynamic_Tags_Placeholders::get_instance();
        $this->assertSame('1-ADMIN-3', $engine->process_placeholders('1-{if:user_logged_in}{if:is_admin}ADMIN{else}USER{/if}{/if}-3'));
    }

    // ------------------------------------------------------------------ L1 + L2, table renderer (table-native tags)

    public function test_table_renderer_keeps_sequential_blocks_apart_and_supports_else(): void
    {
        $this->seed(['c_seq' => 'A{if:user_logged_in}x{/if} m {if:is_admin}y{else}n{/if} e']);
        $this->login('subscriber');
        $this->assertSame('Ax m n e', do_shortcode('[c_seq cache="false"]'));
    }

    public function test_table_renderer_negation(): void
    {
        $this->seed(['c_neg' => '{if:!user_logged_in}OUT{else}IN{/if}']);
        wp_set_current_user(0);
        $this->assertSame('OUT', do_shortcode('[c_neg cache="false"]'));
        $this->login('subscriber');
        $this->assertSame('IN', do_shortcode('[c_neg cache="false"]'));
    }

    public function test_table_renderer_user_not_logged_in(): void
    {
        $this->seed(['c_unl' => '{if:user_not_logged_in}OUT{/if}']);
        wp_set_current_user(0);
        $this->assertSame('OUT', do_shortcode('[c_unl cache="false"]'));
        $this->login('subscriber');
        $this->assertSame('', do_shortcode('[c_unl cache="false"]'));
    }

    public function test_table_renderer_matches_condition_names_exactly(): void
    {
        $this->seed(['c_exact' => '{if:not_really_user_logged_in}Z{/if}']);
        $this->login('subscriber');
        $this->assertSame('', do_shortcode('[c_exact cache="false"]'), 'a condition that merely contains "user_logged_in" is unknown, not true');
    }

    public function test_table_renderer_nested_blocks(): void
    {
        $this->seed(['c_nest' => '{if:user_logged_in}in:{if:is_admin}admin{else}user{/if}{else}out{/if}']);
        $this->login('administrator');
        $this->assertSame('in:admin', do_shortcode('[c_nest cache="false"]'));
        $this->login('subscriber');
        $this->assertSame('in:user', do_shortcode('[c_nest cache="false"]'));
        wp_set_current_user(0);
        $this->assertSame('out', do_shortcode('[c_nest cache="false"]'));
    }
}
