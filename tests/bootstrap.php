<?php
// Minimal bootstrap for unit tests (no WP core needed for unit tests)
define('ABSPATH', __DIR__ . '/../');
define('WPINC', 'wp-includes');

// Autoload vendor
require_once __DIR__ . '/../vendor/autoload.php';
