<?php
// Minimal bootstrap for unit tests (no WP core needed for unit tests)
define('ABSPATH', __DIR__ . '/../');
define('WPINC', 'wp-includes');

// No-op stubs for the handful of WP hook functions the placeholders class
// constructor calls (add_filter) - enough to instantiate the class directly
// for tests that only exercise WP-call-free logic (e.g. the {func:...}
// whitelist), without pulling in a full WP test suite. guard with
// function_exists() so this stays harmless if a real WP bootstrap ever runs
// alongside these tests.
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

// Autoload vendor
require_once __DIR__ . '/../vendor/autoload.php';
