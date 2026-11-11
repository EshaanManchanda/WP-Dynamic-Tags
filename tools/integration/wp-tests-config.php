<?php
/**
 * Config for the WordPress core test suite. Everything comes from environment variables so the same file serves the
 * WP 7.1 and WP 7.0 runs. The database is DESTROYED and recreated by the suite: it must be a disposable one.
 *
 *   WP_CORE_DIR  path to an extracted WordPress (NOT the live site)
 *   WP_TEST_DB   database name, must start with "local_wpunit"
 *   WP_TEST_PORT mysql port (default 10006)
 */
$core = getenv('WP_CORE_DIR');
$db   = getenv('WP_TEST_DB');
if (!$core || !is_dir($core) || !$db || strpos($db, 'local_wpunit') !== 0) {
    fwrite(STDERR, "wp-tests-config: set WP_CORE_DIR to an extracted WordPress and WP_TEST_DB to a local_wpunit* database.\n");
    exit(2);
}
define('ABSPATH', rtrim(strtr($core, chr(92), '/'), '/') . '/');
define('WP_DEBUG', true);
define('DB_NAME', $db);
define('DB_USER', 'root');
define('DB_PASSWORD', 'root');
define('DB_HOST', '127.0.0.1:' . (getenv('WP_TEST_PORT') ?: '10006'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wptests_';
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', getenv('WP_PHP_BINARY') ?: 'php');
define('WPLANG', '');
