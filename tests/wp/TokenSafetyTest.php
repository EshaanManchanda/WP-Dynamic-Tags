<?php
/**
 * S2 (query args), S3 (meta keys) and S1 (loop substitution): tokens are authored by administrators but rendered for
 * anonymous visitors, so what a token may read must not depend on what the author typed after the colon.
 */
class DT_Token_Safety_Test extends WP_UnitTestCase
{
    private function engine(): WP_Dynamic_Tags_Placeholders
    {
        return WP_Dynamic_Tags_Placeholders::get_instance();
    }

    /** @return string[] titles returned by {query:...} for the current viewer */
    private function titles(string $query): array
    {
        $rows = $this->engine()->resolve_token_raw('query', $query);
        return is_array($rows) ? array_column($rows, 'title') : [];
    }

    /** Creates posts as an administrator (raw content, no kses), then returns to the visitor. */
    private function as_admin_create(callable $create): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $create();
        wp_set_current_user(0);
    }

    private function make_post(string $title, string $status = 'publish', array $extra = []): int
    {
        return wp_insert_post(array_merge(['post_title' => $title, 'post_content' => 'body', 'post_status' => $status, 'post_type' => 'post'], $extra));
    }

    // ------------------------------------------------------------------ S2

    public function test_a_visitor_cannot_widen_post_status_through_the_query_string(): void
    {
        $this->as_admin_create(function (): void {
            $this->make_post('S2 public');
            $this->make_post('S2 private', 'private');
            $this->make_post('S2 draft', 'draft');
            $this->make_post('S2 locked', 'publish', ['post_password' => 'secret']);
        });
        $this->assertSame(['S2 public'], $this->titles('s=S2&post_status=any&posts_per_page=20'));
        $this->assertSame([], $this->titles('s=S2private&post_status=private'));
        $this->assertSame([], $this->titles('s=S2draft&post_status=draft,pending,future'));
    }

    public function test_password_protected_posts_are_never_listed(): void
    {
        $this->as_admin_create(fn() => $this->make_post('S2 locked only', 'publish', ['post_password' => 'secret']));
        $this->assertSame([], $this->titles('s=S2'));
    }

    public function test_an_administrator_may_read_private_posts_but_only_by_asking(): void
    {
        $this->as_admin_create(function (): void {
            $this->make_post('S2 admin public');
            $this->make_post('S2 admin private', 'private');
        });
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(['S2 admin public'], $this->titles('s=S2 admin'), 'private posts are not included unless post_status asks for them');
        $this->assertSame(['Private: S2 admin private'], $this->titles('s=S2 admin&post_status=private'));
    }

    public function test_a_filter_cannot_widen_visibility_beyond_what_the_viewer_may_read(): void
    {
        $this->as_admin_create(fn() => $this->make_post('S2 filter private', 'private'));
        add_filter('dt_query_post_statuses', static fn() => ['publish', 'private', 'draft']);
        $this->assertSame([], $this->titles('s=S2 filter'), 'a visitor still cannot read private posts');
        remove_all_filters('dt_query_post_statuses');
    }

    public function test_non_public_post_types_cannot_be_queried(): void
    {
        register_post_type('dt_secret', ['public' => false, 'label' => 'Secret']);
        $this->as_admin_create(function (): void {
            wp_insert_post(['post_title' => 'S2 internal record', 'post_status' => 'publish', 'post_type' => 'dt_secret']);
            $this->make_post('S2 ordinary');
        });
        $titles = $this->titles('s=S2&post_type=dt_secret');
        $this->assertNotContains('S2 internal record', $titles);
    }

    public function test_the_number_of_posts_is_capped(): void
    {
        $this->as_admin_create(function (): void {
            for ($i = 1; $i <= 5; $i++) {
                $this->make_post('S2 many ' . $i);
            }
        });
        add_filter('dt_query_max_posts', static fn() => 3);
        $this->assertCount(3, $this->titles('s=S2 many&posts_per_page=-1'));
        $this->assertCount(3, $this->titles('s=S2 many&posts_per_page=500'));
        remove_all_filters('dt_query_max_posts');
        $this->assertCount(5, $this->titles('s=S2 many&posts_per_page=-1'), 'the default cap of 50 is not reached by 5 posts');
    }

    public function test_the_documented_arguments_still_work(): void
    {
        $this->as_admin_create(function (): void {
            $this->make_post('S2 doc B');
            $this->make_post('S2 doc A');
        });
        $this->assertSame(['S2 doc A', 'S2 doc B'], $this->titles('s=S2 doc&post_type=post&posts_per_page=5&orderby=title&order=ASC'));
    }

    // ------------------------------------------------------------------ S3 / B7

    private function post_with_meta(array $meta): void
    {
        $this->as_admin_create(function () use ($meta, &$id): void {
            $id = $this->make_post('S3 post');
            foreach ($meta as $k => $v) {
                update_post_meta($id, $k, $v);
            }
        });
        $GLOBALS['post'] = get_post($id);
        setup_postdata($GLOBALS['post']);
    }

    public function test_protected_post_meta_is_not_readable_through_a_token(): void
    {
        $this->post_with_meta(['color' => 'red', '_edit_lock' => '1234:5']);
        $this->assertSame('red', $this->engine()->process_placeholders('{meta:color}'));
        $this->assertSame('', $this->engine()->process_placeholders('{meta:_edit_lock}'), 'protected key reads as empty');
    }

    public function test_an_empty_meta_key_renders_nothing_instead_of_array(): void
    {
        $this->post_with_meta(['color' => 'red']);
        $this->assertSame('', $this->engine()->process_placeholders('{meta:}'));
    }

    public function test_sensitive_user_meta_of_the_author_is_not_readable(): void
    {
        $author = self::factory()->user->create(['role' => 'author', 'first_name' => 'Ada']);
        update_user_meta($author, 'session_tokens', ['hash' => ['expiration' => 1]]);
        update_user_meta($author, 'api_secret_key', 'abc');
        $this->as_admin_create(function () use ($author, &$id): void {
            $id = $this->make_post('S3 authored', 'publish', ['post_author' => $author]);
        });
        $GLOBALS['post'] = get_post($id);
        setup_postdata($GLOBALS['post']);
        $this->assertSame('Ada', $this->engine()->process_placeholders('{user_meta:first_name}'));
        $this->assertSame('', $this->engine()->process_placeholders('{user_meta:session_tokens}'));
        $this->assertSame('', $this->engine()->process_placeholders('{user_meta:api_secret_key}'));
        $this->assertSame('', $this->engine()->process_placeholders('{user_meta:' . $GLOBALS['wpdb']->prefix . 'capabilities}'));
    }

    // ------------------------------------------------------------------ S1 ([dt_loop] substitution)

    /** Registers a data source whose rows are attacker-controlled, like the JSON of a remote {api:} call. */
    private function loop_source(array $rows): void
    {
        $this->engine()->register_parameterized_placeholder('dtstub', static fn($arg) => $rows);
    }

    public function test_loop_values_are_data_and_never_expanded_as_tokens(): void
    {
        $this->loop_source([['name' => '{site_name} {meta:_edit_lock} [dt_count]']]);
        $out = do_shortcode('[dt_loop source="dtstub:x"]<li>{item:name}</li>[/dt_loop]');
        $this->assertStringContainsString('{site_name}', $out, 'the token text from the data stays literal');
        $this->assertStringNotContainsString('Test Blog', $out);
    }

    public function test_loop_values_in_text_cannot_inject_script(): void
    {
        $this->loop_source([['name' => '<script>alert(1)</script>Hi <b>there</b>']]);
        $out = do_shortcode('[dt_loop source="dtstub:x"]<li>{item:name}</li>[/dt_loop]');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<b>there</b>', $out, 'safe markup in a value is kept');
    }

    public function test_loop_values_cannot_break_out_of_an_attribute(): void
    {
        $this->loop_source(['x" onerror="alert(1)']);
        $out = do_shortcode('[dt_loop source="dtstub:x"]<img src="{item}" alt="{item}">[/dt_loop]');
        $this->assertStringNotContainsString('" onerror=', $out);
        $this->assertStringNotContainsString('onerror="alert', $out);
    }

    public function test_loop_urls_cannot_use_a_script_protocol(): void
    {
        $this->loop_source(['javascript:alert(1)']);
        $out = do_shortcode('[dt_loop source="dtstub:x"]<a href="{item}">go</a>[/dt_loop]');
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_loop_still_fills_scalar_and_row_items_and_processes_the_template_tokens(): void
    {
        $this->loop_source(['a', 'b']);
        $this->assertSame('Test Blog: a|Test Blog: b', do_shortcode('[dt_loop source="dtstub:x" separator="|"]{site_name}: {item}[/dt_loop]'));
        $this->loop_source([['name' => 'Ada & Bob', 'url' => 'https://example.org/a?x=1&y=2']]);
        $out = do_shortcode('[dt_loop source="dtstub:x"]<a href="{item:url}">{item:name}</a>[/dt_loop]');
        $this->assertStringContainsString('href="https://example.org/a?x=1&#038;y=2"', $out);
        $this->assertStringContainsString('>Ada &amp; Bob</a>', $out);
    }

    public function test_a_filter_can_reenable_a_meta_key(): void
    {
        $this->post_with_meta(['_internal_note' => 'visible on purpose']);
        add_filter('dt_allow_meta_key', static fn($allowed, $key) => $key === '_internal_note' ? true : $allowed, 10, 2);
        $this->assertSame('visible on purpose', $this->engine()->process_placeholders('{meta:_internal_note}'));
        remove_all_filters('dt_allow_meta_key');
    }
}
