<?php
/**
 * Debug log for WP Dynamic Tags.
 *
 * Off by default. Switch it on with the constant DT_DEBUG_MODE or the filter dt_debug_mode. When on, events that make a tag
 * misbehave (a render guard cutting off a cycle, a shortcode name that is taken, a failed capture of a legacy post) are
 * written to the PHP error log and kept in a short list (the last 50) that the Tools screen shows to administrators.
 *
 * What is recorded is a fixed whitelist: tag id, shortcode, error type, context (front or admin) and the time. Never the
 * content of a tag, a meta value, an API credential or a URL. Nothing is ever shown to visitors.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Debug
{
    private const OPTION = 'wp_dynamic_tags_debug_log';
    private const MAX    = 50;

    /** The only context keys that are ever stored. */
    private const ALLOWED = array('tag_id' => true, 'shortcode' => true, 'error_type' => true, 'context' => true);

    public static function enabled(): bool
    {
        return (bool) apply_filters('dt_debug_mode', defined('DT_DEBUG_MODE') && DT_DEBUG_MODE);
    }

    /**
     * @param string              $event   Short name of what happened, e.g. render_guard.
     * @param array<string,mixed> $context tag_id, shortcode, error_type, context; anything else is dropped.
     */
    public static function log(string $event, array $context = array()): void
    {
        if (!self::enabled()) {
            return;
        }

        $entry = array('time' => gmdate('c'), 'event' => sanitize_key($event));
        foreach (array_intersect_key($context, self::ALLOWED) as $key => $value) {
            $entry[$key] = is_scalar($value) ? (string) $value : '';
        }
        if (!isset($entry['context'])) {
            $entry['context'] = is_admin() ? 'admin' : 'front';
        }

        $log = get_option(self::OPTION, array());
        $log = is_array($log) ? $log : array();
        $log[] = $entry;
        update_option(self::OPTION, array_slice($log, -self::MAX), false);

        error_log('WP Dynamic Tags: ' . wp_json_encode($entry));
    }

    /** @return array<int,array<string,string>> newest last */
    public static function entries(): array
    {
        $log = get_option(self::OPTION, array());
        return is_array($log) ? $log : array();
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
