<?php
/**
 * Tag Service for WP Dynamic Tags.
 *
 * The rules a tag has to satisfy before it is stored, on top of the facade's field validation (WP_Dynamic_Tags_Table_Manager
 * still validates names, formats, duplicates, priority and status and keeps its public signatures):
 *
 *  - reserved names      the shortcode may not be one of the plugin's own ([dt], [dt_group], ...) or start with dt_table_
 *  - foreign names       nor a name WordPress or another plugin owns (a tag never replaces a shortcode it does not own)
 *  - circular references a tag may not render itself, directly or through other tags (a cycle used to be found at render
 *                        time only, where the recursion guard cuts it off silently)
 *  - markup              content written by a user without the unfiltered_html capability goes through wp_kses_post, as
 *                        WordPress does for post content; code that runs without a user (cron, WP-CLI, another plugin's
 *                        setup) is trusted
 *  - the cap seam        filter dt_can_create_tag is the single place a limit on the number of tags is enforced
 *
 * Error codes: dt_reserved_shortcode, dt_shortcode_conflict, dt_circular_reference, dt_tag_limit.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Tag_Service
{
    /** Names of the plugin's own shortcodes. */
    private const RESERVED = array('dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list', 'dt_loop', 'dt_if', 'dt_else', 'dt_template');

    /** Reserved prefix of the table-based helper shortcodes. */
    private const RESERVED_PREFIX = 'dt_table_';

    public static function is_reserved($shortcode): bool
    {
        $name = strtolower((string) $shortcode);
        return in_array($name, self::RESERVED, true) || strpos($name, self::RESERVED_PREFIX) === 0;
    }

    /**
     * Whether a shortcode name may be used for a tag.
     *
     * @return true|WP_Error
     */
    public static function check_shortcode($shortcode)
    {
        $shortcode = (string) $shortcode;

        if (self::is_reserved($shortcode)) {
            /* translators: %s: shortcode name */
            return new WP_Error('dt_reserved_shortcode', sprintf(__('The shortcode name [%s] is reserved for the plugin\'s own shortcodes.', 'wp-dynamic-tags'), $shortcode));
        }

        if (shortcode_exists($shortcode) && !WP_Dynamic_Tags_Plugin::shortcode_is_ours($shortcode) && !apply_filters('dt_allow_shortcode_override', false, $shortcode)) {
            /* translators: %s: shortcode name */
            return new WP_Error('dt_shortcode_conflict', sprintf(__('The shortcode name [%s] is already used by WordPress or another plugin.', 'wp-dynamic-tags'), $shortcode));
        }

        return true;
    }

    /**
     * Whether another tag may be created. The one place a limit on the number of tags is decided: filter dt_can_create_tag
     * returns true to allow, false to refuse with the default message, or a WP_Error to refuse with its own.
     *
     * @param array<string,mixed> $data The tag that is about to be created.
     * @return true|WP_Error
     */
    public static function can_create(array $data)
    {
        $allowed = apply_filters('dt_can_create_tag', true, $data);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        return $allowed ? true : new WP_Error('dt_tag_limit', __('No more tags can be created.', 'wp-dynamic-tags'));
    }

    /**
     * The content as it will be stored: filtered like post content when the current user may not post unfiltered HTML.
     */
    public static function prepare_content($content): string
    {
        $content = (string) $content;
        if (get_current_user_id() > 0 && !current_user_can('unfiltered_html')) {
            return wp_kses_post($content);
        }
        return $content;
    }

    /**
     * Whether a tag with this shortcode and content would render itself, directly or through other tags.
     *
     * @param string   $shortcode The tag being saved.
     * @param string   $content   Its new content.
     * @param object[] $rows      Every stored tag (active or not); the row of this tag is replaced by the new content.
     * @return true|WP_Error
     */
    public static function check_cycle($shortcode, $content, array $rows)
    {
        $graph = array();
        foreach ($rows as $row) {
            $graph[strtolower((string) $row->shortcode)] = self::references((string) $row->content);
        }
        $me = strtolower((string) $shortcode);
        $graph[$me] = self::references((string) $content);

        $stack = $graph[$me];
        $seen = array();
        while ($stack) {
            $next = array_pop($stack);
            if ($next === $me) {
                /* translators: %s: shortcode name */
                return new WP_Error('dt_circular_reference', sprintf(__('The tag [%s] would render itself: its content refers back to it, directly or through other tags.', 'wp-dynamic-tags'), $shortcode));
            }
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            foreach ($graph[$next] ?? array() as $reference) {
                $stack[] = $reference;
            }
        }
        return true;
    }

    /**
     * The tag names a content refers to: every [name ...] shortcode, and the tag="..." / key="..." of [dt] and [dt_template].
     *
     * @return string[] lower case
     */
    private static function references(string $content): array
    {
        $found = array();
        if (preg_match_all('/\[([a-zA-Z0-9_\-]+)([^\]]*)\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $found[] = strtolower($hit[1]);
                if (preg_match('/\b(?:tag|key)\s*=\s*["\']([^"\']+)["\']/', $hit[2], $attribute)) {
                    $key = strtolower($attribute[1]);
                    $found[] = trim((string) preg_replace('/_{2,}/', '_', (string) preg_replace('/[^a-z0-9]/', '_', $key)), '_');
                }
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * Everything that has to hold before a new tag is stored; call after the facade's own validation.
     *
     * @param array<string,mixed> $data The new tag, with its shortcode already known.
     * @return true|WP_Error
     */
    public static function guard_create(array $data)
    {
        $allowed = self::can_create($data);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        $shortcode = (string) ($data['shortcode'] ?? '');
        $name = self::check_shortcode($shortcode);
        if (is_wp_error($name)) {
            return $name;
        }
        return self::check_cycle($shortcode, (string) ($data['content'] ?? ''), self::all_rows());
    }

    /**
     * Everything that has to hold before an existing tag is changed. Only what changes is checked: saving a description
     * never fails because of a name or a reference that was already there.
     *
     * @param object              $existing The stored row.
     * @param array<string,mixed> $data     The fields being changed.
     * @return true|WP_Error
     */
    public static function guard_update($existing, array $data)
    {
        $shortcode = isset($data['shortcode']) ? (string) $data['shortcode'] : (string) $existing->shortcode;
        $renamed = $shortcode !== (string) $existing->shortcode;
        if ($renamed) {
            $name = self::check_shortcode($shortcode);
            if (is_wp_error($name)) {
                return $name;
            }
        }

        $content_changed = array_key_exists('content', $data) && (string) $data['content'] !== (string) $existing->content;
        if ($content_changed || $renamed) {
            $content = array_key_exists('content', $data) ? (string) $data['content'] : (string) $existing->content;
            $rows = array_filter(self::all_rows(), static function ($row) use ($existing) {
                return (int) $row->id !== (int) $existing->id; // this tag is represented by its new content
            });
            return self::check_cycle($shortcode, $content, $rows);
        }

        return true;
    }

    /** @return object[] */
    private static function all_rows(): array
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance()->get_tags(array('status' => ''));
    }
}
