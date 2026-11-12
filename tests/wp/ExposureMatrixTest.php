<?php
/**
 * Gate 8 / A5, the front-end half of the data-exposure matrix: role x path, with a private post, a draft, a pending
 * and a scheduled post, a password-protected post, protected post meta and another user's meta.
 *
 * A tag is written by an administrator but rendered for everybody, so what a token or shortcode may read has to follow
 * WordPress's own rules for the viewer's role, not the author's. Paths covered here: [dt_template], {query:}, [dt_loop
 * source="query:"], REST-rendered post content, {meta:}, {user_meta:}, {post_content} and {post_excerpt}. The wp_ajax_*
 * paths (preview, import/export, tag retrieval) are in tests/wp-admin/ActionSecurityTest.
 */
class DT_Exposure_Matrix_Test extends WP_UnitTestCase
{
    private const MARKERS = array(
        'public'  => 'Zq1 public',
        'private' => 'Zq1 private',
        'draft'   => 'Zq1 draft',
        'pending' => 'Zq1 pending',
        'future'  => 'Zq1 future',
        'locked'  => 'Zq1 locked',
    );
    private const SECRET_BODY = 'LOCKED BODY SECRET';
    private const QUERY = 's=Zq1&post_status=any&posts_per_page=20';

    /** @var array<string,int> role => user id (the visitor is 0) */
    private $users = array();
    /** @var array<string,int> fixture key => post id */
    private $posts = array();
    /** @var int */
    private $author_b;

    public static function roles(): array
    {
        $out = array();
        foreach (array('visitor', 'subscriber', 'contributor', 'author', 'editor', 'administrator') as $role) {
            $out[$role] = array($role);
        }
        return $out;
    }

    /** Which fixture posts a query may list for a role: WordPress's own rules, and password-protected posts never. */
    private static function visible_to(string $role): array
    {
        $staff = in_array($role, array('editor', 'administrator'), true);
        return $staff ? array('public', 'private', 'draft', 'pending', 'future') : array('public');
    }

    public function set_up(): void
    {
        parent::set_up();
        foreach (array('subscriber', 'contributor', 'author', 'editor', 'administrator') as $role) {
            $this->users[$role] = self::factory()->user->create(array('role' => $role));
            update_user_meta($this->users[$role], 'api_secret_key', 'viewer-secret-' . $role);
        }
        $this->users['visitor'] = 0;
        $this->author_b = self::factory()->user->create(array('role' => 'author', 'first_name' => 'Bee'));
        update_user_meta($this->author_b, 'session_tokens', array('hash' => array('expiration' => 1)));
        update_user_meta($this->author_b, 'api_secret_key', 'bee-secret');

        // The fixture is written by its own administrator (raw content, no kses), then the test acts as the viewer.
        wp_set_current_user($this->fixture_author());
        $statuses = array('public' => 'publish', 'private' => 'private', 'draft' => 'draft', 'pending' => 'pending', 'future' => 'future', 'locked' => 'publish');
        foreach (self::MARKERS as $key => $title) {
            $args = array('post_title' => $title, 'post_content' => 'body of ' . $key, 'post_status' => $statuses[$key], 'post_type' => 'post', 'post_author' => $this->author_b);
            if ($key === 'future') {
                $args['post_date'] = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
            }
            if ($key === 'locked') {
                $args['post_password'] = 'pw';
                $args['post_content'] = self::SECRET_BODY;
            }
            $this->posts[$key] = (int) wp_insert_post($args);
        }
        update_post_meta($this->posts['public'], 'color', 'red');
        update_post_meta($this->posts['public'], '_hidden_key', 'protected-value');
        wp_set_current_user(0);

        $this->seed('ex_card', '<i>{post_title}</i>');
    }

    /**
     * The user the fixture is written as: an administrator, and on a network a super administrator. Only a super
     * administrator has unfiltered_html there, and kses would otherwise rewrite the & in a shortcode's query attribute
     * (standard WordPress behaviour for every shortcode), which would change what the query asks for.
     */
    private function fixture_author(): int
    {
        $id = self::factory()->user->create(array('role' => 'administrator'));
        if (is_multisite()) {
            grant_super_admin($id);
        }
        return $id;
    }

    private function seed(string $code, string $content): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array(
            'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'exposure test', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ));
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
    }

    private function act_as(string $role): void
    {
        wp_set_current_user($this->users[$role]);
    }

    /** @return string[] the fixture keys whose marker title appears in $text */
    private function found_in(string $text): array
    {
        $found = array();
        foreach (self::MARKERS as $key => $marker) {
            if (str_contains($text, $marker)) {
                $found[] = $key;
            }
        }
        return $found;
    }

    private function engine(): WP_Dynamic_Tags_Placeholders
    {
        return WP_Dynamic_Tags_Placeholders::get_instance();
    }

    private function in_the_loop_of(string $key): void
    {
        $GLOBALS['post'] = get_post($this->posts[$key]);
        setup_postdata($GLOBALS['post']);
    }

    // ------------------------------------------------------------------ what a query may list

    /** @dataProvider roles */
    public function test_dt_template_lists_only_what_the_viewer_may_read(string $role): void
    {
        $this->act_as($role);
        $html = do_shortcode('[dt_template tag="ex_card" query="' . self::QUERY . '"]');
        $this->assertEqualsCanonicalizing(self::visible_to($role), $this->found_in($html), "[dt_template] for $role");
        $this->assertStringNotContainsString(self::SECRET_BODY, $html);
    }

    /** @dataProvider roles */
    public function test_the_query_token_lists_only_what_the_viewer_may_read(string $role): void
    {
        $this->act_as($role);
        $rows = $this->engine()->resolve_token_raw('query', self::QUERY);
        $text = is_array($rows) ? wp_json_encode($rows) : '';
        $this->assertEqualsCanonicalizing(self::visible_to($role), $this->found_in($text), "{query:} for $role");
        $this->assertStringNotContainsString(self::SECRET_BODY, $text, 'a row never carries the body of a protected post');
    }

    /** @dataProvider roles */
    public function test_a_loop_over_a_query_lists_only_what_the_viewer_may_read(string $role): void
    {
        $this->act_as($role);
        $html = do_shortcode('[dt_loop source="query:' . self::QUERY . '"]{item:title}|[/dt_loop]');
        $this->assertEqualsCanonicalizing(self::visible_to($role), $this->found_in($html), "[dt_loop] for $role");
    }

    /** @dataProvider roles */
    public function test_rest_rendered_content_follows_the_viewers_role(string $role): void
    {
        wp_set_current_user($this->fixture_author());
        $host = (int) wp_insert_post(array(
            'post_title' => 'Host page', 'post_status' => 'publish', 'post_type' => 'post',
            'post_content' => '[dt_template tag="ex_card" query="' . self::QUERY . '"]',
        ));
        $this->act_as($role);
        $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/' . $host));
        $this->assertSame(200, $response->get_status(), "the host page is public ($role)");
        $html = (string) ($response->get_data()['content']['rendered'] ?? '');
        $this->assertEqualsCanonicalizing(self::visible_to($role), $this->found_in($html), "REST content for $role");
        $this->assertStringNotContainsString(self::SECRET_BODY, $html);
    }

    // ------------------------------------------------------------------ meta, for every role

    /** @dataProvider roles */
    public function test_protected_post_meta_and_other_users_meta_read_as_empty_for_every_role(string $role): void
    {
        $this->act_as($role);
        $this->in_the_loop_of('public');
        $this->assertSame('red', $this->engine()->process_placeholders('{meta:color}'), 'ordinary meta is readable, as documented');
        $this->assertSame('', $this->engine()->process_placeholders('{meta:_hidden_key}'), "protected meta ($role)");
        $this->assertSame('Bee', $this->engine()->process_placeholders('{user_meta:first_name}'), 'ordinary author meta is readable');
        foreach (array('session_tokens', 'api_secret_key', $GLOBALS['wpdb']->prefix . 'capabilities', 'user_pass') as $key) {
            $this->assertSame('', $this->engine()->process_placeholders('{user_meta:' . $key . '}'), "{user_meta:$key} for $role");
        }
    }

    // ------------------------------------------------------------------ the page being viewed is password-protected

    /**
     * WordPress shows a protected post's form instead of its body to everybody until the password is entered, an
     * administrator included. A tag placed where the protected post is the current post (a widget, a template part)
     * must not print the body either.
     *
     * @dataProvider roles
     */
    public function test_a_password_protected_posts_body_is_not_printed_by_its_tokens(string $role): void
    {
        $this->act_as($role);
        $this->in_the_loop_of('locked');
        $this->assertTrue(post_password_required($GLOBALS['post']), 'the fixture is locked for this viewer');
        $this->assertStringNotContainsString(self::SECRET_BODY, $this->engine()->process_placeholders('{post_content}'), "{post_content} for $role");
        $this->assertStringNotContainsString(self::SECRET_BODY, $this->engine()->process_placeholders('{post_excerpt}'), "{post_excerpt} for $role");
    }
}
