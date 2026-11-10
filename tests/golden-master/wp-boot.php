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
require getenv('WP_LOAD') ?: dirname(__DIR__, 5) . '/wp-load.php';
