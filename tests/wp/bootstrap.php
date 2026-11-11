<?php
/**
 * Bootstrap for the WordPress core test suite (PHPUnit 9.6 under tools/integration, not the root PHPUnit 10).
 * Loads WordPress from WP_CORE_DIR (an extracted release, never the live site) against a local_wpunit* database.
 */
$tests_dir = getenv('WP_TESTS_DIR') ?: dirname(__DIR__, 2) . '/tools/integration/vendor/wp-phpunit/wp-phpunit';
define('WP_TESTS_CONFIG_FILE_PATH', dirname(__DIR__, 2) . '/tools/integration/wp-tests-config.php');

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/wp-dynamic-tags.php';
});

require $tests_dir . '/includes/bootstrap.php';
