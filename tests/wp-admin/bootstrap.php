<?php
/**
 * Bootstrap for tests that must run as an admin request (wp-admin / admin-ajax.php define WP_ADMIN, which is what makes
 * the plugin construct its admin classes and register their wp_ajax_* hooks). Everything else is shared with tests/wp.
 */
define('WP_ADMIN', true);
require dirname(__DIR__) . '/wp/bootstrap.php';
