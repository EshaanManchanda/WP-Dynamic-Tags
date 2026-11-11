<?php
/**
 * S11: hardening of the {api:URL::path} token on top of wp_safe_remote_get().
 *
 * wp_safe_remote_get() already refuses private and reserved addresses and re-validates every redirect hop
 * (wp-includes/class-wp-http.php handle_redirects() passes reject_unsafe_urls on). What was missing: a response size cap,
 * a content-type check, an optional host allow-list, no caching of large payloads, and a cap on fetches per request.
 * The HTTP layer is mocked with pre_http_request, so no test talks to the network.
 */
class DT_Api_Hardening_Test extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array}> every request that reached the HTTP layer */
    private $calls = array();

    public function set_up(): void
    {
        parent::set_up();
        $this->calls = array();
        // The per-request fetch counter is static; every test starts a fresh "request".
        if (property_exists('WP_Dynamic_Tags_Placeholders', 'api_fetches')) {
            $prop = new ReflectionProperty('WP_Dynamic_Tags_Placeholders', 'api_fetches');
            $prop->setAccessible(true);
            $prop->setValue(null, 0);
        }
    }

    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('dt_api_allowed_hosts');
        remove_all_filters('dt_api_max_fetches');
        parent::tear_down();
    }

    /** Answers every HTTP request with this body (or WP_Error) and records the request. */
    private function respond($body, string $type = 'application/json; charset=utf-8', int $code = 200): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($body, $type, $code) {
            $this->calls[] = array('url' => $url, 'args' => $args);
            if ($body instanceof WP_Error) {
                return $body;
            }
            return array(
                'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(array('content-type' => $type)),
                'body'     => $body,
                'response' => array('code' => $code, 'message' => 'OK'),
                'cookies'  => array(),
                'filename' => null,
            );
        }, 10, 3);
    }

    private function token(string $name, string $path = 'a'): string
    {
        return '{api:https://api.example.test/' . $name . '.json::' . $path . '}';
    }

    private function render(string $token): string
    {
        return WP_Dynamic_Tags_Placeholders::get_instance()->process_placeholders($token);
    }

    // ------------------------------------------------------------------ what is asked of the HTTP layer

    public function test_the_request_is_safe_size_capped_and_follows_few_redirects(): void
    {
        $this->respond('{"a":"b"}');
        $this->assertSame('b', $this->render($this->token('args')));

        $this->assertCount(1, $this->calls);
        $args = $this->calls[0]['args'];
        $this->assertTrue($args['reject_unsafe_urls'], 'must go through wp_safe_remote_get (private addresses, redirect hops)');
        $this->assertSame(MB_IN_BYTES, $args['limit_response_size'], 'responses are capped at 1 MB');
        $this->assertLessThanOrEqual(3, $args['redirection']);
    }

    /**
     * WordPress 7.0's wp_http_validate_url() refuses 127/8, 10/8, 0/8, 172.16/12 and 192.168/16 but lets link-local and cloud
     * metadata (169.254/16) and CGNAT (100.64/10) through; 7.1 added them. The plugin declares 7.0 as its minimum, so it
     * checks the host itself. The mock answers any request that gets past the plugin: none may. (Multicast is not in this
     * list on purpose: PHP's global-range check does not cover it, TCP cannot connect to it, and WordPress 7.1 refuses it.)
     */
    public function test_private_reserved_and_metadata_addresses_are_never_contacted(): void
    {
        $this->respond('{"a":"b"}');

        // IPv6 literals are written in full: the token syntax {api:URL::path} splits on the first double colon.
        foreach (array('127.0.0.1', '10.1.2.3', '192.168.0.9', '169.254.169.254', '100.64.0.1', '0.0.0.0', '[0:0:0:0:0:0:0:1]', '[fe80:0:0:0:0:0:0:1]') as $host) {
            $this->assertSame('', $this->render('{api:http://' . $host . '/x.json::a}'), "$host must be refused");
        }
        $this->assertSame(array(), $this->calls, 'no request may reach the HTTP layer');
    }

    public function test_a_public_address_is_still_allowed(): void
    {
        $this->respond('{"a":"b"}');
        $this->assertSame('b', $this->render('{api:https://93.184.216.34/x.json::a}'));
    }

    // ------------------------------------------------------------------ size and content type

    public function test_a_response_that_reaches_the_size_cap_is_refused(): void
    {
        // The transport stops reading at limit_response_size, so a body of exactly that length means "there was more".
        $this->respond('{"a":"' . str_repeat('x', MB_IN_BYTES) . '"}');
        $this->assertSame('', $this->render($this->token('huge')));
    }

    public function test_a_body_that_is_not_json_content_is_refused_but_json_and_plain_text_are_read(): void
    {
        $this->respond('{"a":"b"}', 'text/html; charset=UTF-8');
        $this->assertSame('', $this->render($this->token('html')));

        remove_all_filters('pre_http_request');
        $this->respond('{"a":"b"}', 'application/ld+json');
        $this->assertSame('b', $this->render($this->token('ld')));

        remove_all_filters('pre_http_request');
        $this->respond('{"a":"c"}', 'text/plain'); // raw file hosts serve JSON as text/plain
        $this->assertSame('c', $this->render($this->token('plain')));
    }

    // ------------------------------------------------------------------ caching

    public function test_a_failed_request_returns_nothing_and_is_remembered_briefly(): void
    {
        $this->respond(new WP_Error('http_request_failed', 'down'));
        $this->assertSame('', $this->render($this->token('down')));
        $this->assertSame('', $this->render($this->token('down')));
        $this->assertCount(1, $this->calls, 'the second render must come from the short failure cache');
    }

    public function test_a_payload_over_256_kb_is_not_cached_but_a_small_one_is(): void
    {
        $this->respond('{"a":"b","pad":"' . str_repeat('x', 300 * KB_IN_BYTES) . '"}');
        $this->assertSame('b', $this->render($this->token('big')));
        $this->assertSame('b', $this->render($this->token('big')));
        $this->assertCount(2, $this->calls, 'a payload over 256 KB is fetched again instead of being stored in wp_options');

        remove_all_filters('pre_http_request');
        $this->calls = array();
        $this->respond('{"a":"b"}');
        $this->render($this->token('small'));
        $this->render($this->token('small'));
        $this->assertCount(1, $this->calls, 'a small payload is cached');
    }

    // ------------------------------------------------------------------ allow-list and per-request cap

    public function test_hosts_can_be_restricted_with_a_filter(): void
    {
        $this->respond('{"a":"b"}');
        add_filter('dt_api_allowed_hosts', static fn() => array('API.EXAMPLE.TEST'));

        $this->assertSame('b', $this->render($this->token('allowed')), 'host comparison ignores case');
        $this->assertSame('', $this->render('{api:https://other.example.test/x.json::a}'));
        $this->assertCount(1, $this->calls, 'a refused host never reaches the HTTP layer');
    }

    public function test_at_most_five_urls_are_fetched_per_request(): void
    {
        $this->respond('{"a":"b"}');
        $out = array();
        for ($i = 1; $i <= 7; $i++) {
            $out[] = $this->render($this->token('u' . $i));
        }

        $this->assertSame(array('b', 'b', 'b', 'b', 'b', '', ''), $out);
        $this->assertCount(5, $this->calls);

        // Already cached URLs do not count against the cap.
        $this->assertSame('b', $this->render($this->token('u1')));
        $this->assertCount(5, $this->calls);
    }

    public function test_the_fetch_cap_can_be_changed_with_a_filter(): void
    {
        $this->respond('{"a":"b"}');
        add_filter('dt_api_max_fetches', static fn() => 1);

        $this->assertSame('b', $this->render($this->token('f1')));
        $this->assertSame('', $this->render($this->token('f2')));
        $this->assertCount(1, $this->calls);
    }
}
