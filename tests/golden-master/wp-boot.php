<?php
/**
 * Loads the local WordPress site from the CLI for the golden-master harness.
 *
 * Env overrides: WP_LOAD (path to wp-load.php), WP_HOST (site host name).
 * Default WP root is five levels up: plugins/<this plugin>/tests/golden-master -> public/.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

$host = getenv('WP_HOST') ?: 'gema-ecosystem.local';
$_SERVER['HTTP_HOST']   = $host;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

define('WP_USE_THEMES', false);

// WP_DB_NAME points the whole run at a different database (a disposable clone of the site, for the
// destructive live scenarios). wp-config.php defines DB_NAME again afterwards; PHP keeps the first value
// and warns, and that one warning is the only thing swallowed here.
$db_override = getenv('WP_DB_NAME');
if ($db_override) {
    define('DB_NAME', $db_override);
    set_error_handler(static fn(int $no, string $str): bool => str_contains($str, 'already defined'), E_WARNING);
}

require getenv('WP_LOAD') ?: dirname(__DIR__, 5) . '/wp-load.php';

if ($db_override) {
    restore_error_handler();
}
