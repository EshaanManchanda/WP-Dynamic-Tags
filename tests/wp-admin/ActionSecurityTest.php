<?php
/**
 * Authorization tests generated from tests/wp/support/actions.php.
 *
 * Desired behavior for every JSON AJAX action: a user without the capability is denied even with a valid nonce, and a
 * request without a nonce is denied even for an administrator. Entries tagged `known` still violate this and are
 * expected to fail until the bug in docs/BUG-REGISTER.md is fixed (the failing run is the evidence that the test can
 * detect the bug).
 */
class DT_Action_Security_Test extends WP_Ajax_UnitTestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $registry;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        self::$registry = require __DIR__ . '/support/actions.php';
    }

    /** @return array<string, array{0: string}> */
    public static function json_actions(): array
    {
        $out = [];
        foreach (require __DIR__ . '/support/actions.php' as $action => $meta) {
            if ($meta['kind'] === 'json') {
                $out[$action . (isset($meta['known']) ? ' [' . $meta['known'] . ']' : '')] = [$action];
            }
        }
        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function positive_controls(): array
    {
        return ['dt_check_table_health' => ['dt_check_table_health'], 'dt_table_statistics' => ['dt_table_statistics']];
    }

    /** Plugin-owned wp_ajax_* hooks as action => list of callbacks. */
    private function plugin_ajax_hooks(): array
    {
        global $wp_filter;
        $root = wp_normalize_path(dirname(__DIR__, 2));
        $found = [];
        foreach ($wp_filter as $tag => $hook) {
            if (!str_starts_with($tag, 'wp_ajax_') || str_starts_with($tag, 'wp_ajax_nopriv_')) {
                continue;
            }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $cb) {
                    $fn = $cb['function'];
                    try {
                        if ($fn instanceof Closure) {
                            $file = (new ReflectionFunction($fn))->getFileName();
                        } elseif (is_array($fn)) {
                            $file = (new ReflectionClass(is_object($fn[0]) ? get_class($fn[0]) : $fn[0]))->getFileName();
                        } elseif (is_string($fn) && function_exists($fn)) {
                            $file = (new ReflectionFunction($fn))->getFileName();
                        } else {
                            $file = null;
                        }
                    } catch (Throwable $e) {
                        $file = null;
                    }
                    if ($file && str_starts_with(wp_normalize_path($file), $root)) {
                        $found[substr($tag, strlen('wp_ajax_'))][] = $fn;
                    }
                }
            }
        }
        return $found;
    }

    /** @return array{die: ?string, raw: string, json: mixed, crash: ?string} */
    private function call(string $action): array
    {
        $this->_last_response = '';
        $die = null;
        $crash = null;
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            $die = $e->getMessage();
        } catch (Throwable $e) {
            $crash = get_class($e) . ': ' . $e->getMessage();
        }
        $raw = (string) $this->_last_response;
        return ['die' => $die, 'raw' => $raw, 'json' => json_decode($raw, true), 'crash' => $crash];
    }

    private function is_denied(array $r): bool
    {
        if (in_array($r['die'], ['-1', '0'], true)) {
            return true;
        }
        $pattern = '/unauthor|permission|not allowed|insufficient|forbidden|nonce|security check/i';
        if (is_string($r['die']) && preg_match($pattern, $r['die'])) {
            return true;
        }
        if (is_array($r['json']) && ($r['json']['success'] ?? null) === false) {
            $data = $r['json']['data'] ?? '';
            return (bool) preg_match($pattern, is_string($data) ? $data : wp_json_encode($data));
        }
        return false;
    }

    private function describe(array $r): string
    {
        return sprintf('die=%s crash=%s response=%s', var_export($r['die'], true), var_export($r['crash'], true), substr($r['raw'], 0, 160));
    }

    private function as_role(string $role): int
    {
        $id = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($id);
        return $id;
    }

    // ------------------------------------------------------------------ registry integrity

    public function test_every_registered_ajax_action_is_in_the_registry(): void
    {
        $registered = array_keys($this->plugin_ajax_hooks());
        $listed = array_keys(self::$registry);
        $this->assertSame([], array_values(array_diff($registered, $listed)), 'registered but not in tests/wp/support/actions.php');
        $this->assertSame([], array_values(array_diff($listed, $registered)), 'listed in the registry but not registered by the plugin');
    }

    public function test_every_registered_ajax_callback_is_callable(): void
    {
        $broken = [];
        foreach ($this->plugin_ajax_hooks() as $action => $callbacks) {
            foreach ($callbacks as $cb) {
                if (!is_callable($cb)) {
                    $broken[] = $action . ' -> ' . (is_array($cb) ? (is_object($cb[0]) ? get_class($cb[0]) : $cb[0]) . '::' . $cb[1] : 'callable');
                }
            }
        }
        $this->assertSame([], $broken, 'wp_ajax_* hooks whose callback method does not exist (L13): calling them is a fatal error');
    }

    // ------------------------------------------------------------------ authorization

    /** @dataProvider json_actions */
    public function test_denies_a_user_without_the_capability_even_with_a_valid_nonce(string $action): void
    {
        $meta = self::$registry[$action];
        $this->as_role('subscriber');
        $_POST[$meta['key']] = wp_create_nonce($meta['nonce']);
        $r = $this->call($action);
        $this->assertTrue($this->is_denied($r), "$action must deny a subscriber; got " . $this->describe($r));
    }

    /**
     * A contributor holds edit_posts, which several handlers wrongly accept (S5): tag content and tag management are
     * administrator-only.
     *
     * @dataProvider json_actions
     */
    public function test_denies_a_contributor_even_with_a_valid_nonce(string $action): void
    {
        $meta = self::$registry[$action];
        $this->as_role('contributor');
        $_POST[$meta['key']] = wp_create_nonce($meta['nonce']);
        $r = $this->call($action);
        $this->assertTrue($this->is_denied($r), "$action must deny a contributor; got " . $this->describe($r));
    }

    /**
     * Every nonce a handler verifies must be created by some code in the plugin, otherwise the endpoint can never be
     * called by its own UI (S6). Static check: the nonce action literal must sit in a file that also creates nonces.
     */
    public function test_every_verified_nonce_is_created_somewhere_in_the_plugin(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/includes/*.php') ?: [],
            glob($root . '/admin/*.php') ?: []
        );
        $creators = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/wp_create_nonce|wp_nonce_field|wp_nonce_url/', $src)) {
                $creators[] = $src;
            }
        }
        $never_created = [];
        foreach (array_unique(array_column(self::$registry, 'nonce')) as $nonce) {
            $found = false;
            foreach ($creators as $src) {
                if (str_contains($src, "'" . $nonce . "'")) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $never_created[] = $nonce;
            }
        }
        $this->assertSame([], $never_created, 'nonce actions verified by a handler but created by no code (S6)');
    }

    /** Handlers that read request keys must tolerate missing ones (S7): no PHP warning, no TypeError. */
    public static function parameterless_probe_actions(): array
    {
        $names = [
            'dt_create_group', 'dt_edit_group', 'dt_delete_group', 'dt_assign_groups', 'dt_get_group_details',
            'dt_get_group_edit_data', 'dt_table_export', 'dt_table_import', 'dt_table_validate_import',
            'dt_table_create_tag', 'dt_table_update_tag', 'dt_table_delete_tag', 'dt_table_get_tags', 'dt_table_bulk_action',
        ];
        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /** @dataProvider parameterless_probe_actions */
    public function test_an_authorized_request_without_parameters_does_not_crash(string $action): void
    {
        $meta = self::$registry[$action];
        $this->as_role('administrator');
        $_POST[$meta['key']] = wp_create_nonce($meta['nonce']);
        $r = $this->call($action);
        $this->assertNull($r['crash'], "$action crashed on missing parameters: " . $r['crash']);
    }

    /** @dataProvider json_actions */
    public function test_denies_an_administrator_without_a_nonce(string $action): void
    {
        $this->as_role('administrator');
        $r = $this->call($action);
        $this->assertTrue($this->is_denied($r), "$action must require a nonce; got " . $this->describe($r));
    }

    // ------------------------------------------------------------------ positive controls (the harness must be able to say yes)

    /** @dataProvider positive_controls */
    public function test_administrator_with_a_valid_nonce_is_not_denied(string $action): void
    {
        $meta = self::$registry[$action];
        $this->as_role('administrator');
        $_POST[$meta['key']] = wp_create_nonce($meta['nonce']);
        $r = $this->call($action);
        $this->assertFalse($this->is_denied($r), "$action must accept an administrator with a valid nonce; got " . $this->describe($r));
        $this->assertNull($r['crash'], 'handler crashed: ' . $r['crash']);
    }
}
