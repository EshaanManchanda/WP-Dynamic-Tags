<?php
/**
 * Cache purge for WP Dynamic Tags.
 *
 * A tag shows on every page that uses it, and a change should show on the whole site at once. The plugin itself keeps
 * nothing between requests, so what can hold an old value is a page cache in front of it (a caching plugin, a host's cache,
 * a CDN). When a tag or a group changes, the page caches are purged: once per request however many changes it made, at the
 * end of the request, and only when something a page shows really changed (a save that changes nothing, such as another
 * plugin re-syncing the same data, and a usage count do not purge anything).
 *
 *  - action dt_tags_changed          fires once after a change; a site with a cache layer of its own listens to it
 *  - filter dt_purge_page_caches     return false to skip the built-in purge of the supported cache plugins (default true)
 *
 * Supported without any setting: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround
 * Optimizer, Hummingbird, Breeze, Cache Enabler, Nginx Helper and the Cloudflare plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Cache_Purge
{
    /** Fields of a tag that a page can show or depend on. */
    private const WATCHED = array('tag_name', 'shortcode', 'content', 'description', 'category_id', 'priority', 'status', 'meta_data');

    /** @var bool */
    private static $pending = false;

    /** @var bool */
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('wp_dynamic_tags_tag_created', array(__CLASS__, 'request'), 20, 0);
        add_action('wp_dynamic_tags_tag_deleted', array(__CLASS__, 'request'), 20, 0);
        add_action('wp_dynamic_tags_tag_updated', array(__CLASS__, 'on_updated'), 20, 3);

        // [dt_group group="..."] and the group filters show other tags when a group appears, is renamed or goes
        foreach (array('created_tag_groups', 'edited_tag_groups', 'delete_tag_groups') as $hook) {
            add_action($hook, array(__CLASS__, 'request'), 20, 0);
        }

        add_action('shutdown', array(__CLASS__, 'flush'), 99);
    }

    /** Something a page shows has changed: purge at the end of the request. */
    public static function request(): void
    {
        self::$pending = true;
    }

    public static function is_pending(): bool
    {
        return self::$pending;
    }

    /**
     * @param int          $id
     * @param array|mixed  $data Fields that were written.
     * @param object|mixed $old  The row as it was.
     */
    public static function on_updated($id, $data, $old): void
    {
        if (!is_array($data) || !is_object($old)) {
            self::request();
            return;
        }
        foreach (self::WATCHED as $field) {
            if (array_key_exists($field, $data) && self::normalise($data[$field]) !== self::normalise($old->$field ?? '')) {
                self::request();
                return;
            }
        }
    }

    /** @param mixed $value */
    private static function normalise($value): string
    {
        if (is_array($value) || is_object($value)) {
            $json = wp_json_encode($value);
            return is_string($json) ? $json : '';
        }
        return (string) $value;
    }

    /**
     * Purges now if a change is waiting (hooked on shutdown; callable directly). Nothing here may stop the request: every
     * listener and every cache plugin is isolated from the others.
     */
    public static function flush(): void
    {
        if (!self::$pending) {
            return;
        }
        self::$pending = false;

        try {
            do_action('dt_tags_changed');
        } catch (Throwable $e) {
            // another plugin's listener failed: the built-in purge below still runs
        }

        if (apply_filters('dt_purge_page_caches', true)) {
            self::purge_known_caches();
        }
    }

    private static function purge_known_caches(): void
    {
        $purgers = array(
            'wp-rocket'        => static function () {
                if (function_exists('rocket_clean_domain')) {
                    rocket_clean_domain();
                }
            },
            'litespeed'        => static function () {
                if (has_action('litespeed_purge_all')) {
                    do_action('litespeed_purge_all');
                }
            },
            'w3-total-cache'   => static function () {
                if (function_exists('w3tc_flush_all')) {
                    w3tc_flush_all();
                } elseif (function_exists('w3tc_pgcache_flush')) {
                    w3tc_pgcache_flush();
                }
            },
            'wp-super-cache'   => static function () {
                if (function_exists('wp_cache_clear_cache')) {
                    wp_cache_clear_cache();
                }
            },
            'wp-fastest-cache' => static function () {
                if (function_exists('wpfc_clear_all_cache')) {
                    wpfc_clear_all_cache(true);
                }
            },
            'sg-optimizer'     => static function () {
                if (function_exists('sg_cachepress_purge_cache')) {
                    sg_cachepress_purge_cache();
                }
            },
            'hummingbird'      => static function () {
                if (has_action('wphb_clear_page_cache')) {
                    do_action('wphb_clear_page_cache');
                }
            },
            'breeze'           => static function () {
                if (has_action('breeze_clear_all_cache')) {
                    do_action('breeze_clear_all_cache');
                }
            },
            'cache-enabler'    => static function () {
                if (has_action('cache_enabler_clear_complete_cache')) {
                    do_action('cache_enabler_clear_complete_cache');
                }
            },
            'nginx-helper'     => static function () {
                if (has_action('rt_nginx_helper_purge_all')) {
                    do_action('rt_nginx_helper_purge_all');
                }
            },
            'cloudflare'       => static function () {
                if (has_action('cloudflare_purge_everything')) {
                    do_action('cloudflare_purge_everything');
                }
            },
        );

        foreach ($purgers as $purge) {
            try {
                $purge();
            } catch (Throwable $e) {
                // one cache plugin failing must not leave the others holding the old page
            }
        }
    }
}
