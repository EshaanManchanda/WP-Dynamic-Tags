<?php
/**
 * Automatic updates from GitHub Releases (WP_Dynamic_Tags_Updater). The HTTP layer is mocked with pre_http_request: no test
 * talks to GitHub.
 *
 * The contract being tested:
 *  - WordPress asks through update_plugins_github.com (the plugin's Update URI), once per check; the answer is a newer, fully
 *    validated release or nothing. Anything else leaves the incoming value untouched and never raises a notice.
 *  - A failure (GitHub down, rate limited, nothing published, a release that does not validate) never removes an update that
 *    was already offered: the last good release stays, and the next try waits an hour.
 *  - The ZIP is checked against the SHA-256 of the release's manifest before WordPress installs it.
 *  - The same answer for a Free site and a Paid site; no license is involved.
 */
class DT_Updater_Test extends WP_UnitTestCase
{
    private const API       = 'https://api.github.com/repos/EshaanManchanda/WP-Dynamic-Tags/releases/latest';
    private const DOWNLOADS = 'https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/';

    /** @var array<int, array{url: string, args: array}> */
    private $calls = array();

    /** @var array<string, array{0: mixed, 1: int}> url => body (array|string|WP_Error), status */
    private $routes = array();

    /** @var array<string, string> url => bytes of a package the mock serves as a streamed download */
    private $packages = array();

    /** @var string[] files the mock wrote for a streamed download */
    private $streamed = array();

    /** @var string[] folders a test unpacked an update into */
    private $unpacked = array();

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/file.php';
        remove_all_filters('pre_option_dt_plan');
        update_option('dt_plan', 'free');
        delete_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $this->calls    = array();
        $this->routes   = array();
        $this->packages = array();
        $this->streamed = array();

        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->calls[] = array('url' => $url, 'args' => $args);
            if (isset($this->packages[$url])) {
                file_put_contents($args['filename'], $this->packages[$url]);
                $this->streamed[] = $args['filename'];
                return array('headers' => array(), 'body' => '', 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => $args['filename']);
            }
            if (!isset($this->routes[$url])) {
                return new WP_Error('unexpected_request', 'unexpected request to ' . $url);
            }
            list($body, $code) = $this->routes[$url];
            if ($body instanceof WP_Error) {
                return $body;
            }
            return array(
                'headers'  => array(),
                'body'     => is_string($body) ? $body : wp_json_encode($body),
                'response' => array('code' => $code, 'message' => ''),
                'cookies'  => array(),
                'filename' => null,
            );
        }, 10, 3);
    }

    public function tear_down(): void
    {
        unset($_GET['force-check']);
        foreach ($this->streamed as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->unpacked as $dir) {
            $this->remove_tree($dir);
        }
        $this->unpacked = array();
        delete_site_option(WP_Dynamic_Tags_Updater::OPTION);
        parent::tear_down();
    }

    private function remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item !== '.' && $item !== '..') {
                is_dir("$dir/$item") ? $this->remove_tree("$dir/$item") : unlink("$dir/$item");
            }
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------ fixtures

    /** @return array<string, mixed> what GET /releases/latest returns for a good release */
    private function release(string $ver = '3.1.1'): array
    {
        $base = self::DOWNLOADS . 'v' . $ver . '/';
        return array(
            'tag_name'     => 'v' . $ver,
            'draft'        => false,
            'prerelease'   => false,
            'html_url'     => 'https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/tag/v' . $ver,
            'published_at' => '2026-11-20T10:00:00Z',
            'body'         => "First public release.\n\n* Automatic updates from GitHub",
            'assets'       => array(
                array('name' => "wp-dynamic-tags-$ver.zip", 'size' => 231770, 'state' => 'uploaded', 'browser_download_url' => $base . "wp-dynamic-tags-$ver.zip"),
                array('name' => "wp-dynamic-tags-$ver.manifest.json", 'size' => 420, 'state' => 'uploaded', 'browser_download_url' => $base . "wp-dynamic-tags-$ver.manifest.json"),
            ),
        );
    }

    /** @return array<string, mixed> the manifest asset the release workflow builds */
    private function manifest(string $ver = '3.1.1', ?string $sha = null): array
    {
        return array(
            'plugin'       => 'wp-dynamic-tags',
            'version'      => $ver,
            'tag'          => 'v' . $ver,
            'zip'          => "wp-dynamic-tags-$ver.zip",
            'sha256'       => $sha ?? str_repeat('ab', 32),
            'commit'       => str_repeat('c', 40),
            'requires'     => '7.0',
            'tested'       => '7.1',
            'requires_php' => '8.2',
            'built_at'     => '2026-11-20T09:58:00Z',
        );
    }

    /**
     * Makes GitHub answer. $api and $manifest default to a good 3.1.1; a manifest asset that the release lists is served at its URL.
     *
     * @param array|WP_Error|string|null $api
     * @param array|WP_Error|string|null $manifest
     */
    private function github($api = null, $manifest = null, int $api_code = 200, int $manifest_code = 200): void
    {
        $api      = $api ?? $this->release();
        $manifest = $manifest ?? $this->manifest();
        $this->routes[self::API] = array($api, $api_code);
        foreach ((array) (is_array($api) ? ($api['assets'] ?? array()) : array()) as $asset) {
            if (isset($asset['name'], $asset['browser_download_url']) && substr((string) $asset['name'], -14) === '.manifest.json') {
                $this->routes[$asset['browser_download_url']] = array($manifest, $manifest_code);
            }
        }
    }

    /** What WordPress hands the filter for this plugin. */
    private function offer(string $installed = '3.1.0', $incoming = false, ?string $file = null)
    {
        $data = array('Name' => 'WP Dynamic Tags', 'Version' => $installed, 'UpdateURI' => 'https://github.com/EshaanManchanda/WP-Dynamic-Tags');
        return apply_filters('update_plugins_github.com', $incoming, $data, $file ?? WP_Dynamic_Tags_Updater::plugin_file(), array());
    }

    private function api_calls(): int
    {
        return count(array_filter($this->calls, static fn(array $c): bool => $c['url'] === self::API));
    }

    private function expire(): void
    {
        $state = get_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $state['next_check'] = time() - 1;
        update_site_option(WP_Dynamic_Tags_Updater::OPTION, $state);
    }

    /**
     * Nothing newer than $installed is offered: false, or a no_update style entry (not newer, and carrying no package that
     * anything could install).
     */
    private function assertNothingNewer($answer, string $installed = '3.1.0', string $message = ''): void
    {
        $offered = is_array($answer) && isset($answer['version']) && version_compare((string) $answer['version'], $installed, '>');
        $this->assertFalse($offered, $message ?: 'nothing newer than ' . $installed . ' may be offered');
        if (is_array($answer)) {
            $this->assertArrayNotHasKey('package', $answer, 'an answer that is not an update must not carry a package');
        }
    }

    // ------------------------------------------------------------------ wiring

    public function test_the_plugin_header_names_github_as_the_update_source(): void
    {
        $headers = get_file_data(WP_DYNAMIC_TAGS_PLUGIN_DIR . 'wp-dynamic-tags.php', array('uri' => 'Update URI'));
        $this->assertSame('https://github.com/EshaanManchanda/WP-Dynamic-Tags', $headers['uri']);
        $this->assertSame('github.com', wp_parse_url($headers['uri'], PHP_URL_HOST), 'the hostname decides the filter name');
    }

    public function test_the_four_hooks_are_registered(): void
    {
        $this->assertNotFalse(has_filter('update_plugins_github.com'));
        $this->assertNotFalse(has_filter('plugins_api'));
        $this->assertNotFalse(has_filter('upgrader_pre_download'));
        $this->assertNotFalse(has_filter('upgrader_source_selection', array('WP_Dynamic_Tags_Updater', 'filter_source_selection')));
    }

    // ------------------------------------------------------------------ what is offered

    public function test_a_newer_release_is_offered_with_its_package_and_requirements(): void
    {
        $this->github();
        $update = $this->offer('3.1.0');

        $this->assertIsArray($update);
        $this->assertSame('3.1.1', $update['version']);
        $this->assertSame(self::DOWNLOADS . 'v3.1.1/wp-dynamic-tags-3.1.1.zip', $update['package']);
        $this->assertSame('https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/tag/v3.1.1', $update['url']);
        $this->assertSame('wp-dynamic-tags', $update['slug']);
        $this->assertSame('8.2', $update['requires_php']);
        $this->assertSame('7.1', $update['tested']);
        $this->assertCount(2, $this->calls, 'one request for the release, one for its manifest');
    }

    public function test_the_same_version_is_not_offered(): void
    {
        $this->github($this->release('3.1.0'), $this->manifest('3.1.0'));
        $this->assertNothingNewer($this->offer('3.1.0'));
    }

    public function test_an_older_release_is_never_a_downgrade(): void
    {
        $this->github();
        $this->assertNothingNewer($this->offer('3.2.0'), '3.2.0');
        $this->assertNothingNewer($this->offer('3.1.1'), '3.1.1');
    }

    public function test_a_valid_release_that_is_not_newer_is_still_reported_so_auto_updates_can_be_switched_on(): void
    {
        // WordPress files an answer that is not newer under no_update; the Plugins screen needs that entry to offer the
        // auto-update toggle, so the filter answers with the release instead of false.
        $this->github($this->release('3.1.0'), $this->manifest('3.1.0'));
        $answer = $this->offer('3.1.0');
        $this->assertIsArray($answer);
        $this->assertSame('3.1.0', $answer['version']);
        $this->assertNothingNewer($answer);
    }

    public function test_the_update_is_the_same_on_a_free_and_a_paid_site_and_needs_no_license(): void
    {
        $this->github();
        $this->assertSame('', (string) get_option('dt_license_key', ''));

        update_option('dt_plan', 'free');
        $free = $this->offer();
        update_option('dt_plan', 'paid');
        $paid = $this->offer();

        $this->assertSame('3.1.1', $free['version']);
        $this->assertSame($free, $paid);
    }

    // ------------------------------------------------------------------ what is refused

    /** @dataProvider invalid_releases */
    public function test_a_release_that_does_not_validate_is_not_offered(callable $break): void
    {
        $this->github($break($this->release()));
        $this->assertFalse($this->offer(), 'nothing valid is known, so nothing is offered');
    }

    public static function invalid_releases(): array
    {
        $asset = static function (array $r, int $i, array $change): array {
            $r['assets'][$i] = array_merge($r['assets'][$i], $change);
            return $r;
        };
        $set = static function (string $key, $value): callable {
            return static function (array $r) use ($key, $value): array {
                $r[$key] = $value;
                return $r;
            };
        };
        return array(
            'a prerelease'                         => array($set('prerelease', true)),
            'a draft'                              => array($set('draft', true)),
            'a tag that is not a version'          => array($set('tag_name', 'nightly')),
            'a two-part version'                   => array($set('tag_name', 'v3.1')),
            'a pre-release suffix'                 => array($set('tag_name', 'v3.1.1-beta.1')),
            'a release page of another repository' => array($set('html_url', 'https://github.com/someone/else/releases/tag/v3.1.1')),
            'no assets'                            => array($set('assets', array())),
            'no package'                           => array(static function (array $r): array { unset($r['assets'][0]); return $r; }),
            'a package named differently'          => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('name' => 'wp-dynamic-tag-3.1.1.zip')); }),
            'a package of another version'         => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('name' => 'wp-dynamic-tags-3.1.2.zip')); }),
            'an empty package'                     => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('size' => 0)); }),
            'a package not fully uploaded'         => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('state' => 'starter')); }),
            'a package over plain http'            => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('browser_download_url' => 'http://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip')); }),
            'a package of another repository'      => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('browser_download_url' => 'https://github.com/someone/else/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip')); }),
            'a package on another host'            => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('browser_download_url' => 'https://example.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip')); }),
            'a package of another tag'             => array(static function (array $r) use ($asset): array { return $asset($r, 0, array('browser_download_url' => 'https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v9.9.9/wp-dynamic-tags-3.1.1.zip')); }),
            'no manifest'                          => array(static function (array $r): array { unset($r['assets'][1]); return $r; }),
            'a manifest on another host'           => array(static function (array $r) use ($asset): array { return $asset($r, 1, array('browser_download_url' => 'https://example.com/wp-dynamic-tags-3.1.1.manifest.json')); }),
            'an oversized manifest'                => array(static function (array $r) use ($asset): array { return $asset($r, 1, array('size' => 70000)); }),
        );
    }

    /** @dataProvider invalid_manifests */
    public function test_a_manifest_that_does_not_validate_fails_closed(callable $break): void
    {
        $this->github(null, $break($this->manifest()));
        $this->assertFalse($this->offer(), 'without a trustworthy checksum nothing is offered');
    }

    public static function invalid_manifests(): array
    {
        $set = static function (string $key, $value): callable {
            return static function (array $m) use ($key, $value): array {
                $m[$key] = $value;
                return $m;
            };
        };
        return array(
            'not JSON'                  => array(static fn(array $m): string => 'this is not json'),
            'a different version'       => array($set('version', '3.1.2')),
            'a different package name'  => array($set('zip', 'wp-dynamic-tags-3.1.2.zip')),
            'another plugin'            => array($set('plugin', 'some-other-plugin')),
            'no checksum'               => array(static function (array $m): array { unset($m['sha256']); return $m; }),
            'a short checksum'          => array($set('sha256', 'abc123')),
            'a checksum that is not hex' => array($set('sha256', str_repeat('z', 64))),
            'a bad PHP requirement'     => array($set('requires_php', '8.2; drop table')),
            'a bad WordPress requirement' => array($set('requires', '')),
            'a bad tested version'      => array($set('tested', array('7.1'))),
        );
    }

    /** @dataProvider failures */
    public function test_a_failure_never_raises_and_offers_nothing_when_nothing_was_known(?string $body, int $code): void
    {
        $this->routes[self::API] = array($body === null ? new WP_Error('http_request_failed', 'cURL error 28: timed out') : $body, $code);

        $this->assertFalse($this->offer());
        $this->assertSame(1, $this->api_calls());
    }

    public static function failures(): array
    {
        return array(
            'a transport error'          => array(null, 0),
            'no release published (404)' => array('{"message":"Not Found"}', 404),
            'a rate limit (403)'         => array('{"message":"API rate limit exceeded"}', 403),
            'too many requests (429)'    => array('', 429),
            'a server error (500)'       => array('', 500),
            'a body that is not JSON'    => array('<html>oops</html>', 200),
            'a JSON list'                => array('[1,2,3]', 200),
            'an empty body'              => array('', 200),
        );
    }

    public function test_the_request_carries_nothing_about_the_site(): void
    {
        $this->github();
        $this->offer();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $args = $call['args'];
            $this->assertSame('WP-Dynamic-Tags/' . WP_DYNAMIC_TAGS_VERSION, $args['user-agent'], 'WordPress\'s default agent carries the site address');
            $this->assertLessThanOrEqual(5, $args['timeout']);
            $this->assertEmpty($args['body'] ?? '');
            $this->assertStringNotContainsString(home_url(), wp_json_encode($args));
            $this->assertStringNotContainsString(home_url(), $call['url']);
        }
        $this->assertSame('application/vnd.github+json', $this->calls[0]['args']['headers']['Accept']);
    }

    // ------------------------------------------------------------------ cache and failure handling

    public function test_a_good_answer_is_cached_so_a_second_check_makes_no_request(): void
    {
        $this->github();
        $this->offer();
        $made = count($this->calls);

        $again = $this->offer();
        $this->assertSame('3.1.1', $again['version']);
        $this->assertCount($made, $this->calls, 'the stored answer serves the second check');

        $state = get_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $this->assertGreaterThan(time() + 5 * HOUR_IN_SECONDS, $state['next_check'], 'a good answer is kept for hours');
        $this->assertLessThanOrEqual(time() + 12 * HOUR_IN_SECONDS, $state['next_check'], 'but never longer than one WordPress check cycle');
    }

    public function test_a_failure_is_cached_for_an_hour_so_a_rate_limited_site_does_not_hammer_github(): void
    {
        $this->routes[self::API] = array('{"message":"API rate limit exceeded"}', 403);
        $this->offer();
        $this->offer();
        $this->offer();

        $this->assertSame(1, $this->api_calls(), 'three checks, one request');
        $state = get_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $this->assertGreaterThan(time() + 50 * MINUTE_IN_SECONDS, $state['next_check']);
        $this->assertLessThanOrEqual(time() + HOUR_IN_SECONDS, $state['next_check']);
    }

    public function test_the_last_good_release_stays_offered_while_github_fails(): void
    {
        $this->github();
        $this->assertSame('3.1.1', $this->offer()['version']);

        $this->expire();
        $this->routes[self::API] = array('', 500);
        $answer = $this->offer();

        $this->assertIsArray($answer, 'an outage must not remove the update WordPress already shows');
        $this->assertSame('3.1.1', $answer['version']);
        $this->assertSame(2, $this->api_calls(), 'it did try again after the cache expired');
        $state = get_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $this->assertLessThanOrEqual(time() + HOUR_IN_SECONDS, $state['next_check'], 'and will wait an hour, not half a day');
    }

    public function test_a_release_that_stops_validating_does_not_remove_the_last_good_one_either(): void
    {
        $this->github();
        $this->assertSame('3.1.1', $this->offer()['version']);

        $this->expire();
        $broken = $this->release();
        unset($broken['assets'][0]); // the package is missing from the newest release
        $this->github($broken);

        $this->assertSame('3.1.1', $this->offer()['version']);
    }

    public function test_a_valid_older_release_replaces_the_last_good_one_when_the_newer_one_was_withdrawn(): void
    {
        $this->github();
        $this->assertSame('3.1.1', $this->offer()['version']);

        $this->expire();
        $this->github($this->release('3.1.0'), $this->manifest('3.1.0')); // 3.1.1 was deleted: latest is 3.1.0 again
        $this->assertNothingNewer($this->offer('3.1.0'));
    }

    public function test_a_tampered_cache_is_ignored_and_the_release_is_fetched_again(): void
    {
        $this->github();
        update_site_option(WP_Dynamic_Tags_Updater::OPTION, array(
            'release'    => array('version' => '9.9.9', 'package' => 'http://evil.example/x.zip', 'sha256' => 'nope'),
            'next_check' => time() + DAY_IN_SECONDS,
        ));

        $answer = $this->offer();
        $this->assertSame('3.1.1', $answer['version'], 'never the cached 9.9.9');
        $this->assertSame(self::DOWNLOADS . 'v3.1.1/wp-dynamic-tags-3.1.1.zip', $answer['package']);
    }

    /**
     * A stored release that is complete and well-formed except for one value that points somewhere it should not: the database
     * is not trusted just because it is ours.
     *
     * @dataProvider poisoned_fields
     */
    public function test_a_stored_release_that_points_elsewhere_is_never_offered(string $field, string $value): void
    {
        $this->github();
        $this->offer();                                    // a real, validated release is now stored
        $state = get_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $state['release'][$field] = $value;
        $state['next_check']      = time() + DAY_IN_SECONDS; // fresh, so only the validation can reject it
        update_site_option(WP_Dynamic_Tags_Updater::OPTION, $state);
        $made = $this->api_calls();

        $answer = $this->offer();

        $this->assertSame(self::DOWNLOADS . 'v3.1.1/wp-dynamic-tags-3.1.1.zip', $answer['package'], 'the poisoned value must never be handed to WordPress');
        $this->assertGreaterThan($made, $this->api_calls(), 'the release was fetched again instead of trusted');
    }

    public static function poisoned_fields(): array
    {
        return array(
            'a package on another host'       => array('package', 'https://evil.example/wp-dynamic-tags-3.1.1.zip'),
            'a package in another repository' => array('package', 'https://github.com/someone/else/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip'),
            'a package over plain http'       => array('package', 'http://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip'),
            'a details page elsewhere'        => array('url', 'https://evil.example/releases'),
            'a tag that is not the version'   => array('tag', 'v9.9.9'),
            'a version that is not a version' => array('version', '3.1.1; drop'),
        );
    }

    /** @dataProvider garbage_state */
    public function test_a_cache_of_the_wrong_shape_never_raises(mixed $junk): void
    {
        $this->github();
        update_site_option(WP_Dynamic_Tags_Updater::OPTION, $junk);
        $this->assertSame('3.1.1', $this->offer()['version']);
    }

    public static function garbage_state(): array
    {
        return array(
            'a string'              => array('garbage'),
            'an integer'            => array(7),
            'an empty array'        => array(array()),
            'no time'               => array(array('release' => null)),
            'a time that is a word' => array(array('release' => null, 'next_check' => 'soon')),
            'a release that is a string' => array(array('release' => 'x', 'next_check' => PHP_INT_MAX)),
        );
    }

    public function test_the_state_is_kept_network_wide_and_never_autoloaded(): void
    {
        $this->github();
        $this->offer();

        $this->assertIsArray(get_site_option(WP_Dynamic_Tags_Updater::OPTION));
        if (is_multisite()) {
            $made   = count($this->calls);
            $second = self::factory()->blog->create();
            switch_to_blog($second);
            $answer = $this->offer();
            restore_current_blog();
            $this->assertSame('3.1.1', $answer['version']);
            $this->assertCount($made, $this->calls, 'every site of the network shares one check');
        } else {
            $this->assertArrayNotHasKey(WP_Dynamic_Tags_Updater::OPTION, wp_load_alloptions(), 'a cached release must not load on every page');
        }
    }

    public function test_check_again_bypasses_the_cache_only_for_someone_who_may_update_plugins(): void
    {
        $this->github();
        $this->offer();
        $made = count($this->calls);

        $_GET['force-check'] = '1';

        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));
        $this->offer();
        $this->assertCount($made, $this->calls, 'a visitor adding ?force-check=1 changes nothing');

        $admin = self::factory()->user->create(array('role' => 'administrator'));
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $this->offer();
        $this->assertGreaterThan($made, count($this->calls), 'Check again on the Updates screen asks GitHub');
    }

    // ------------------------------------------------------------------ isolation

    public function test_another_plugins_update_is_left_alone_and_costs_no_request(): void
    {
        $this->github();
        $theirs = array('version' => '1.2.3', 'package' => 'https://github.com/someone/else/releases/download/v1.2.3/else.zip');

        $answer = $this->offer('1.0.0', $theirs, 'else/else.php');

        $this->assertSame($theirs, $answer);
        $this->assertCount(0, $this->calls);
    }

    public function test_an_incoming_update_is_returned_unchanged_when_there_is_no_valid_release(): void
    {
        $this->routes[self::API] = array('', 500);
        $incoming = array('version' => '9.9.9', 'package' => 'https://example.test/x.zip');

        $this->assertSame($incoming, $this->offer('3.1.0', $incoming));
    }

    // ------------------------------------------------------------------ the details popup

    public function test_the_details_popup_describes_the_release(): void
    {
        $this->github();
        $info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'wp-dynamic-tags'));

        $this->assertIsObject($info);
        $this->assertSame('WP Dynamic Tags', $info->name);
        $this->assertSame('wp-dynamic-tags', $info->slug);
        $this->assertSame('3.1.1', $info->version);
        $this->assertSame(self::DOWNLOADS . 'v3.1.1/wp-dynamic-tags-3.1.1.zip', $info->download_link);
        $this->assertSame('7.0', $info->requires);
        $this->assertSame('7.1', $info->tested);
        $this->assertSame('8.2', $info->requires_php);
        $this->assertStringContainsString('Eshaan Manchanda', $info->author);
        $this->assertSame('https://github.com/EshaanManchanda/WP-Dynamic-Tags', $info->homepage);
        $this->assertNotEmpty($info->last_updated);
        $this->assertArrayHasKey('description', $info->sections);
        $this->assertStringContainsString('Automatic updates from GitHub', $info->sections['changelog']);
    }

    public function test_release_notes_are_escaped_in_the_popup(): void
    {
        $release = $this->release();
        $release['body'] = "<script>alert(1)</script>\n\n* <img src=x onerror=alert(2)> item";
        $this->github($release);

        $info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'wp-dynamic-tags'));

        $this->assertStringNotContainsString('<script', $info->sections['changelog']);
        $this->assertStringNotContainsString('<img', $info->sections['changelog']);
        $this->assertStringContainsString('&lt;script&gt;', $info->sections['changelog']);
    }

    public function test_the_popup_works_from_the_cache_while_github_is_down(): void
    {
        $this->github();
        $this->offer();

        $this->expire();
        $this->routes[self::API] = array(new WP_Error('http_request_failed', 'timed out'), 0);
        $info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'wp-dynamic-tags'));

        $this->assertIsObject($info);
        $this->assertSame('3.1.1', $info->version);
    }

    public function test_the_popup_leaves_other_plugins_and_other_actions_alone(): void
    {
        $this->github();
        $theirs = (object) array('name' => 'Theirs');

        $this->assertFalse(apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'akismet')));
        $this->assertSame($theirs, apply_filters('plugins_api', $theirs, 'plugin_information', (object) array('slug' => 'akismet')));
        $this->assertFalse(apply_filters('plugins_api', false, 'query_plugins', (object) array('slug' => 'wp-dynamic-tags')));
        $this->assertCount(0, $this->calls);
    }

    public function test_the_popup_says_nothing_when_no_release_is_known(): void
    {
        $this->routes[self::API] = array('', 404);
        $this->assertFalse(apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'wp-dynamic-tags')));
    }

    // ------------------------------------------------------------------ the download is checked

    private function offered_package(string $bytes = 'the-zip-bytes'): string
    {
        $manifest = $this->manifest('3.1.1', hash('sha256', $bytes));
        $this->github(null, $manifest);
        $package = $this->offer()['package'];
        $this->packages[$package] = $bytes;
        return $package;
    }

    public function test_a_download_that_matches_the_manifest_checksum_is_handed_to_wordpress(): void
    {
        $package = $this->offered_package('the-zip-bytes');

        $path = apply_filters('upgrader_pre_download', false, $package, null, array('plugin' => WP_Dynamic_Tags_Updater::plugin_file()));

        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->assertSame('the-zip-bytes', file_get_contents($path));
    }

    public function test_a_download_that_does_not_match_is_refused_and_removed(): void
    {
        $package = $this->offered_package('the-zip-bytes');
        $this->packages[$package] = 'a different file';

        $result = apply_filters('upgrader_pre_download', false, $package, null, array());

        $this->assertWPError($result);
        $this->assertSame('dt_update_checksum', $result->get_error_code());
        $this->assertNotEmpty($this->streamed);
        foreach ($this->streamed as $file) {
            $this->assertFileDoesNotExist($file, 'the rejected file must not stay in the temp folder');
        }
    }

    public function test_our_package_that_is_not_the_checked_release_is_refused_without_downloading(): void
    {
        $this->offered_package();
        $other = self::DOWNLOADS . 'v3.0.9/wp-dynamic-tags-3.0.9.zip';
        $this->packages[$other] = 'old bytes';

        $result = apply_filters('upgrader_pre_download', false, $other, null, array());

        $this->assertWPError($result);
        $this->assertSame('dt_update_unverified', $result->get_error_code());
        $this->assertSame(array(), $this->streamed, 'nothing is downloaded that cannot be checked');
    }

    public function test_our_package_is_refused_when_no_release_was_ever_checked(): void
    {
        $package = self::DOWNLOADS . 'v3.1.1/wp-dynamic-tags-3.1.1.zip';
        $this->packages[$package] = 'bytes';

        $result = apply_filters('upgrader_pre_download', false, $package, null, array());

        $this->assertWPError($result);
        $this->assertSame('dt_update_unverified', $result->get_error_code());
    }

    public function test_other_downloads_pass_through_untouched(): void
    {
        $this->offered_package();
        $calls = count($this->calls);

        $this->assertFalse(apply_filters('upgrader_pre_download', false, 'https://downloads.wordpress.org/plugin/akismet.5.0.zip', null, array()));
        $this->assertFalse(apply_filters('upgrader_pre_download', false, self::DOWNLOADS . 'v3.1.1/other-plugin.zip', null, array()), 'not our file name, not ours');
        $this->assertFalse(apply_filters('upgrader_pre_download', false, 'https://example.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v3.1.1/wp-dynamic-tags-3.1.1.zip', null, array()));
        $this->assertCount($calls, $this->calls);
    }

    public function test_a_download_another_filter_already_handled_is_not_touched(): void
    {
        $package = $this->offered_package();
        $this->assertSame('/tmp/already.zip', apply_filters('upgrader_pre_download', '/tmp/already.zip', $package, null, array()));
    }

    // ------------------------------------------------------------------ the update goes into the folder the plugin lives in

    /**
     * WordPress installs an update into the folder named by the package's root, not into the folder the plugin is in. The release
     * ZIP's root is wp-dynamic-tags/, so a copy that lives anywhere else (GitHub's "Source code" ZIP, a renamed or development
     * folder) would get a second copy next to the old one and stay on the old version. The updater renames the unpacked folder.
     *
     * @return array{0: string, 1: string} the folder WordPress unpacked into, and the unpacked plugin folder inside it (with the slash)
     */
    private function unpacked(string $root = 'wp-dynamic-tags'): array
    {
        $remote = untrailingslashit(wp_normalize_path(get_temp_dir())) . '/dt-unpack-' . wp_generate_password(8, false);
        wp_mkdir_p("$remote/$root");
        file_put_contents("$remote/$root/wp-dynamic-tags.php", '<?php // the new version');
        $this->unpacked[] = $remote;
        return array($remote, "$remote/$root/");
    }

    private function installed_folder(): string
    {
        return basename(untrailingslashit(WP_DYNAMIC_TAGS_PLUGIN_DIR));
    }

    private function select_source(string $source, string $remote, $extra = null)
    {
        WP_Filesystem();
        $extra = $extra ?? array('plugin' => WP_Dynamic_Tags_Updater::plugin_file(), 'type' => 'plugin', 'action' => 'update');
        return apply_filters('upgrader_source_selection', $source, $remote, null, $extra);
    }

    public function test_the_unpacked_folder_is_renamed_to_the_folder_the_plugin_is_installed_in(): void
    {
        [$remote, $source] = $this->unpacked('some-other-folder');

        $selected = $this->select_source($source, $remote);

        $this->assertSame("$remote/" . $this->installed_folder() . '/', $selected);
        $this->assertFileExists($selected . 'wp-dynamic-tags.php');
        $this->assertSame('<?php // the new version', file_get_contents($selected . 'wp-dynamic-tags.php'));
        $this->assertDirectoryDoesNotExist(untrailingslashit($source), 'the folder was moved, not copied');
    }

    public function test_a_folder_that_already_has_the_right_name_is_left_alone(): void
    {
        [$remote, $source] = $this->unpacked($this->installed_folder());

        $this->assertSame($source, $this->select_source($source, $remote));
        $this->assertFileExists($source . 'wp-dynamic-tags.php');
    }

    public function test_another_plugins_package_is_never_renamed(): void
    {
        [$remote, $source] = $this->unpacked('some-other-folder');

        $this->assertSame($source, $this->select_source($source, $remote, array('plugin' => 'akismet/akismet.php')));
        $this->assertSame($source, $this->select_source($source, $remote, array('theme' => 'twentytwentysix')));
        $this->assertSame($source, $this->select_source($source, $remote, array()));
        $this->assertSame($source, $this->select_source($source, $remote, 'not an array'));
        $this->assertFileExists($source . 'wp-dynamic-tags.php', 'nothing was moved');
    }

    public function test_a_source_that_is_not_inside_the_unpack_folder_is_left_alone(): void
    {
        [$remote] = $this->unpacked('some-other-folder');
        [, $elsewhere] = $this->unpacked('another-folder');

        $this->assertSame($elsewhere, $this->select_source($elsewhere, $remote));
        $this->assertFileExists($elsewhere . 'wp-dynamic-tags.php');
    }

    public function test_a_folder_that_cannot_be_moved_stops_the_update_with_an_error(): void
    {
        [$remote] = $this->unpacked('some-other-folder');

        $result = $this->select_source("$remote/missing-folder/", $remote);

        $this->assertWPError($result);
        $this->assertSame('dt_update_rename', $result->get_error_code());
    }
}
