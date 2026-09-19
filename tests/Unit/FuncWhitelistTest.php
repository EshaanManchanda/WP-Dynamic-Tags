<?php
/**
 * Unit tests for the {func:name}/{func:name:arg} developer escape hatch.
 * Unlike most of this plugin's getters, register_allowed_function() and
 * get_func_value() make zero real WordPress calls, so - with the minimal
 * add_filter/apply_filters stubs in tests/bootstrap.php - a real instance
 * can be exercised end-to-end without a WP bootstrap.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class FuncWhitelistTest extends TestCase {

    public function test_unregistered_function_returns_empty(): void {
        $instance = new WP_Dynamic_Tags_Placeholders();
        $this->assertSame('', $instance->resolve_token_raw('func', 'not_registered'));
    }

    public function test_registered_function_with_no_arg(): void {
        $instance = new WP_Dynamic_Tags_Placeholders();
        $instance->register_allowed_function('greet', function ($arg) {
            return 'hello';
        });
        $this->assertSame('hello', $instance->resolve_token_raw('func', 'greet'));
    }

    public function test_registered_function_with_arg(): void {
        $instance = new WP_Dynamic_Tags_Placeholders();
        $instance->register_allowed_function('shipping_estimate', function ($arg) {
            return $arg === 'express' ? '1 day' : '5 days';
        });
        $this->assertSame('1 day', $instance->resolve_token_raw('func', 'shipping_estimate:express'));
        $this->assertSame('5 days', $instance->resolve_token_raw('func', 'shipping_estimate:standard'));
    }

    public function test_registered_function_can_return_an_array(): void {
        $instance = new WP_Dynamic_Tags_Placeholders();
        $instance->register_allowed_function('colors', function () {
            return ['Red', 'Green', 'Blue'];
        });
        $this->assertSame(['Red', 'Green', 'Blue'], $instance->resolve_token_raw('func', 'colors'));
    }

    public function test_only_explicitly_registered_names_are_callable(): void {
        $instance = new WP_Dynamic_Tags_Placeholders();
        $instance->register_allowed_function('safe_one', function () {
            return 'ok';
        });
        // A name that was never registered - even something dangerous-sounding -
        // must never be reachable, proving this is a whitelist, not eval().
        $this->assertSame('', $instance->resolve_token_raw('func', 'system'));
        $this->assertSame('', $instance->resolve_token_raw('func', 'exec'));
        $this->assertSame('ok', $instance->resolve_token_raw('func', 'safe_one'));
    }
}
