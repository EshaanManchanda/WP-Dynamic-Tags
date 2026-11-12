<?php
/**
 * Activation, the license server and the plan state. The HTTP layer is mocked with pre_http_request: no test talks to a server.
 *
 * What a site sends to the license server: the license key and the site address, to POST {server}/api/payments/activate-remote
 * when a key is activated and on the twice-daily check while a key is stored; deactivate-remote when it is deactivated.
 * Nothing else, and nothing at all without a key.
 *
 * The suite runs as the Paid plan (tests/wp/bootstrap.php); this test sets its own plan.
 */
class DT_License_Manager_Test extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, body: array}> */
    private $calls = array();

    public function set_up(): void
    {
        parent::set_up();
        remove_all_filters('pre_option_dt_plan');
        update_option('dt_plan', 'free');
        delete_option('dt_license_server_url');
        delete_option('cg_license_server_url');
        $this->calls = array();
    }

    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('dt_upgrade_url');
        parent::tear_down();
    }

    /** @param array|WP_Error|string $reply the decoded JSON body, an error, or a raw body */
    private function server_says($reply, int $code = 200): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($reply, $code) {
            $this->calls[] = array('url' => $url, 'body' => json_decode((string) ($args['body'] ?? ''), true) ?: array());
            if ($reply instanceof WP_Error) {
                return $reply;
            }
            return array(
                'headers'  => array(),
                'body'     => is_string($reply) ? $reply : wp_json_encode($reply),
                'response' => array('code' => $code, 'message' => 'OK'),
                'cookies'  => array(),
                'filename' => null,
            );
        }, 10, 3);
    }

    // ------------------------------------------------------------------ which server

    public function test_the_default_license_server_is_the_portfolio_backend(): void
    {
        $this->assertSame('https://tlvapi.techlovev.in', DT_License_Manager::get_license_server());
        $this->assertSame('https://tlvapi.techlovev.in', DT_License_Manager::DEFAULT_LICENSE_SERVER);
    }

    public function test_the_servers_a_site_can_choose_win_in_order(): void
    {
        update_option('cg_license_server_url', 'https://cg.example.test/');
        $this->assertSame('https://cg.example.test', DT_License_Manager::get_license_server(), 'Certificate Generator\'s server is shared when ours is unset');

        update_option('dt_license_server_url', 'https://dt.example.test//');
        $this->assertSame('https://dt.example.test', DT_License_Manager::get_license_server());
    }

    public function test_only_an_https_address_is_used_as_a_server(): void
    {
        update_option('dt_license_server_url', 'http://insecure.example.test');
        $this->assertSame('https://tlvapi.techlovev.in', DT_License_Manager::get_license_server(), 'the key must not travel in clear text');

        update_option('dt_license_server_url', 'javascript:alert(1)');
        $this->assertSame('https://tlvapi.techlovev.in', DT_License_Manager::get_license_server());
    }

    // ------------------------------------------------------------------ activation

    public function test_a_valid_key_makes_the_site_paid_and_sends_only_the_key_and_the_site(): void
    {
        $this->server_says(array('valid' => true, 'plan' => 'paid', 'expiry' => '2027-06-30'));

        $result = DT_License_Manager::activate_license(' paid-abc-123 ');

        $this->assertTrue($result['success']);
        $this->assertTrue(DT_License_Manager::is_paid());
        $this->assertSame('PAID-ABC-123', DT_License_Manager::get_license_key());
        $this->assertSame('2027-06-30', DT_License_Manager::get_expiry());
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://tlvapi.techlovev.in/api/payments/activate-remote', $this->calls[0]['url']);
        $this->assertSame(array('license_key' => 'PAID-ABC-123', 'site_url' => home_url()), $this->calls[0]['body'], 'nothing else about the site or the user is sent');
    }

    public function test_an_invalid_key_stays_free_and_shows_the_servers_reason(): void
    {
        $this->server_says(array('valid' => false, 'message' => 'Key revoked.'));

        $result = DT_License_Manager::activate_license('PAID-NOPE');

        $this->assertFalse($result['success']);
        $this->assertSame('Key revoked.', $result['message']);
        $this->assertFalse(DT_License_Manager::is_paid());
        $this->assertSame('', DT_License_Manager::get_license_key());
    }

    public function test_an_unreachable_server_is_reported_and_changes_nothing(): void
    {
        $this->server_says(new WP_Error('http_request_failed', 'Could not resolve host'));

        $result = DT_License_Manager::activate_license('PAID-ABC');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not resolve host', $result['message']);
        $this->assertFalse(DT_License_Manager::is_paid());
    }

    public function test_an_answer_that_is_not_json_or_names_another_plan_is_refused(): void
    {
        $this->server_says('<html>502</html>', 502);
        $this->assertFalse(DT_License_Manager::activate_license('PAID-ABC')['success']);
        remove_all_filters('pre_http_request');

        $this->server_says(array('valid' => true, 'plan' => 'enterprise'));
        $this->assertFalse(DT_License_Manager::activate_license('PAID-ABC')['success']);
        $this->assertFalse(DT_License_Manager::is_paid());
    }

    public function test_an_empty_key_is_refused_without_asking_the_server(): void
    {
        $this->server_says(array('valid' => true, 'plan' => 'paid'));

        $this->assertFalse(DT_License_Manager::activate_license('   ')['success']);
        $this->assertCount(0, $this->calls);
    }

    public function test_a_key_that_looks_paid_is_not_enough_without_the_server(): void
    {
        // The offline "PAID-..." shortcut is gone: with the server unreachable, nobody becomes Paid by typing a prefix.
        $this->server_says(new WP_Error('http_request_failed', 'offline'));

        foreach (array('PAID-ANYTHING', 'PAID-ANYTHING-LIFETIME', 'PAID-X-LOCAL') as $key) {
            $this->assertFalse(DT_License_Manager::activate_license($key)['success'], $key);
        }
        $this->assertFalse(DT_License_Manager::is_paid());
        $this->assertSame('free', get_option('dt_plan'));
    }

    // ------------------------------------------------------------------ deactivation and the check

    public function test_deactivating_tells_the_server_and_returns_to_free(): void
    {
        update_option('dt_plan', 'paid');
        update_option('dt_license_key', 'PAID-ABC');
        update_option('dt_license_expiry', '2027-06-30');
        $this->server_says(array('success' => true));

        DT_License_Manager::deactivate_license();

        $this->assertFalse(DT_License_Manager::is_paid());
        $this->assertSame('', DT_License_Manager::get_license_key());
        $this->assertSame('https://tlvapi.techlovev.in/api/payments/deactivate-remote', $this->calls[0]['url']);
    }

    public function test_the_check_downgrades_a_revoked_key_and_keeps_a_valid_one(): void
    {
        update_option('dt_plan', 'paid');
        update_option('dt_license_key', 'PAID-ABC');

        $this->server_says(array('valid' => true, 'plan' => 'paid', 'expiry' => '2028-01-01'));
        DT_License_Manager::heartbeat();
        $this->assertTrue(DT_License_Manager::is_paid());
        $this->assertSame('2028-01-01', DT_License_Manager::get_expiry());

        remove_all_filters('pre_http_request');
        $this->server_says(array('valid' => false, 'status' => 'revoked'));
        DT_License_Manager::heartbeat();
        $this->assertFalse(DT_License_Manager::is_paid());
        $this->assertSame('', DT_License_Manager::get_license_key());
    }

    public function test_a_server_that_cannot_be_reached_never_downgrades_a_paid_site(): void
    {
        update_option('dt_plan', 'paid');
        update_option('dt_license_key', 'PAID-ABC');
        $this->server_says(new WP_Error('http_request_failed', 'timeout'));

        DT_License_Manager::heartbeat();

        $this->assertTrue(DT_License_Manager::is_paid(), 'a network outage is not a revoked license');
    }

    public function test_without_a_key_the_site_never_contacts_the_server(): void
    {
        $this->server_says(array('valid' => true, 'plan' => 'paid'));

        DT_License_Manager::heartbeat();

        $this->assertCount(0, $this->calls);
    }

    // ------------------------------------------------------------------ the plan and its limit

    public function test_an_unknown_stored_plan_is_free(): void
    {
        update_option('dt_plan', 'platinum');
        $this->assertFalse(DT_License_Manager::is_paid());
        $this->assertSame('Free', DT_License_Manager::get_plan_label());
    }

    public function test_the_free_limit_and_the_upgrade_link(): void
    {
        $this->assertSame(15, DT_License_Manager::FREE_TAG_LIMIT);
        $this->assertSame('', DT_License_Manager::get_upgrade_url(), 'no pricing page is known until the release names one');

        add_filter('dt_upgrade_url', static fn() => 'https://example.test/plans');
        $this->assertSame('https://example.test/plans', DT_License_Manager::get_upgrade_url());

        add_filter('dt_upgrade_url', static fn() => 'javascript:alert(1)', 20);
        $this->assertSame('', DT_License_Manager::get_upgrade_url(), 'only an http(s) address is a link');
    }

    public function test_the_feature_table_lists_what_is_actually_gated(): void
    {
        $labels = wp_list_pluck(DT_License_Manager::get_plan_features(), 'label');
        $joined = implode(' | ', $labels);

        foreach (array('{wc:', '{user_meta:', '[dt_loop]', '[dt_template]', '{query:', '{api:', '{func:', 'Field-based conditions') as $needle) {
            $this->assertStringContainsString($needle, $joined, "$needle is a Paid feature and the table must say so");
        }
        $this->assertSame('Up to 15', DT_License_Manager::get_plan_features()[0]['free']);
    }
}
