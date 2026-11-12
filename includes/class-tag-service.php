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
    /**
     * Who created a tag, remembered in its meta_data. 'api' is code calling create_tag($data) (a sibling plugin's sync); a tag
     * made by a person (Add New, Duplicate, Import) carries no mark. Only the mark of an api tag is ever stored: it is how the
     * Free limit tells them apart (DT_License_Manager::count_capped_tags()).
     */
    public const ORIGIN_KEY = 'dt_origin';
    public const ORIGIN_API = 'api';
    public const ORIGIN_USER = 'user';

    /** Names of the plugin's own shortcodes. */
    private const RESERVED = array('dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list', 'dt_loop', 'dt_if', 'dt_else', 'dt_template');

    /** Reserved prefix of the table-based helper shortcodes. */
    private const RESERVED_PREFIX = 'dt_table_';

    /** What a tag name and a shortcode may consist of (the facade's field validation and the importer's dry run share them). */
    public const NAME_PATTERN = '/^[a-zA-Z0-9_\-\s]+$/';
    public const SHORTCODE_PATTERN = '/^[a-zA-Z0-9_\-]+$/';

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
     * @param array<string,mixed> $data   The tag that is about to be created.
     * @param string              $origin 'api' for code calling create_tag($data), 'user' for Add New, Duplicate and Import.
     * @return true|WP_Error
     */
    public static function can_create(array $data, string $origin = self::ORIGIN_USER)
    {
        $allowed = apply_filters('dt_can_create_tag', true, $data, $origin);
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
     * A tag's meta_data as it will be stored, with the origin mark handled in this one place for create and update.
     *
     * The mark (dt_origin = api) is what keeps a tag out of the Free limit, so a caller can never choose it: any mark in the
     * data is removed and then set again only when the tag is (or stays) one that another plugin created. A meta_data string
     * that is not an array but mentions the mark cannot carry a real one and is dropped, so that it cannot fake one either.
     * The rest of the meta data is kept as it was sent.
     *
     * @param mixed $raw An array, a JSON or serialized string, or empty.
     * @param bool  $api Whether the tag is created by another plugin (or already was).
     * @return array<string,mixed>|string
     */
    public static function normalize_meta($raw, bool $api)
    {
        $meta = WP_Dynamic_Tags_Database_Manager::decode_meta($raw);
        if (is_array($meta)) {
            unset($meta[self::ORIGIN_KEY]);
            if ($api) {
                $meta[self::ORIGIN_KEY] = self::ORIGIN_API;
            }
            return $meta;
        }
        if ($raw === null || $raw === '') {
            return $api ? array(self::ORIGIN_KEY => self::ORIGIN_API) : '';
        }
        if (is_string($raw) && strpos($raw, self::ORIGIN_KEY) !== false) {
            return '';
        }
        return $raw;
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
        return self::check_cycle_in_graph((string) $shortcode, (string) $content, self::graph_of($rows), null);
    }

    /**
     * @param array<string,string[]> $graph  Stored tags: shortcode => the tags its content refers to.
     * @param string|null            $ignore The stored tag that is being replaced by $content (its old content no longer counts).
     * @return true|WP_Error
     */
    private static function check_cycle_in_graph(string $shortcode, string $content, array $graph, $ignore)
    {
        $me = strtolower($shortcode);
        $stack = self::references($content);
        $seen = array();
        while ($stack) {
            $next = array_pop($stack);
            if ($next === $me) {
                /* translators: %s: shortcode name */
                return new WP_Error('dt_circular_reference', sprintf(__('The tag [%s] would render itself: its content refers back to it, directly or through other tags.', 'wp-dynamic-tags'), $shortcode));
            }
            if (isset($seen[$next]) || $next === $ignore) {
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
     * @param object[] $rows
     * @return array<string,string[]>
     */
    private static function graph_of(array $rows): array
    {
        $graph = array();
        foreach ($rows as $row) {
            $graph[strtolower((string) $row->shortcode)] = self::references((string) $row->content);
        }
        return $graph;
    }

    // ------------------------------------------------------------------ batches

    /**
     * The reference graph of the stored tags, kept for the length of a batch (null outside one, and until first needed).
     * Without a batch every check loads and scans every stored tag, which is right for one write at a time and quadratic
     * for an import: the batch loads the graph once and the actions the facade fires keep it current.
     *
     * @var array<string,string[]>|null
     */
    private static $batch_graph = null;

    /** @var bool */
    private static $in_batch = false;

    /**
     * Starts a batch of writes made through the facade (an import). Always pair with end_batch(), in a finally block.
     * Writes that bypass the facade during a batch are not seen by its checks.
     */
    public static function begin_batch(): void
    {
        self::$in_batch = true;
        self::$batch_graph = null;
        add_action('wp_dynamic_tags_tag_created', array(__CLASS__, 'batch_created'), 10, 2);
        add_action('wp_dynamic_tags_tag_updated', array(__CLASS__, 'batch_updated'), 10, 3);
        add_action('wp_dynamic_tags_tag_deleted', array(__CLASS__, 'batch_deleted'), 10, 2);
    }

    public static function end_batch(): void
    {
        remove_action('wp_dynamic_tags_tag_created', array(__CLASS__, 'batch_created'), 10);
        remove_action('wp_dynamic_tags_tag_updated', array(__CLASS__, 'batch_updated'), 10);
        remove_action('wp_dynamic_tags_tag_deleted', array(__CLASS__, 'batch_deleted'), 10);
        self::$in_batch = false;
        self::$batch_graph = null;
    }

    /** @return array<string,string[]> */
    private static function batch_graph(): array
    {
        if (self::$batch_graph === null) {
            self::$batch_graph = self::graph_of(self::all_rows());
        }
        return self::$batch_graph;
    }

    /** @param mixed $data */
    public static function batch_created($id, $data): void
    {
        if (self::$batch_graph !== null && is_array($data)) {
            self::$batch_graph[strtolower((string) ($data['shortcode'] ?? ''))] = self::references((string) ($data['content'] ?? ''));
        }
    }

    /** @param mixed $data */
    public static function batch_updated($id, $data, $existing): void
    {
        if (self::$batch_graph === null || !is_array($data) || !is_object($existing)) {
            return;
        }
        $shortcode = isset($data['shortcode']) ? (string) $data['shortcode'] : (string) $existing->shortcode;
        $content = array_key_exists('content', $data) ? (string) $data['content'] : (string) $existing->content;
        unset(self::$batch_graph[strtolower((string) $existing->shortcode)]);
        self::$batch_graph[strtolower($shortcode)] = self::references($content);
    }

    public static function batch_deleted($id, $existing): void
    {
        if (self::$batch_graph !== null && is_object($existing)) {
            unset(self::$batch_graph[strtolower((string) $existing->shortcode)]);
        }
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
    public static function guard_create(array $data, string $origin = self::ORIGIN_USER)
    {
        $allowed = self::can_create($data, $origin);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        $shortcode = (string) ($data['shortcode'] ?? '');
        $name = self::check_shortcode($shortcode);
        if (is_wp_error($name)) {
            return $name;
        }
        $content = (string) ($data['content'] ?? '');
        if (self::$in_batch) {
            return self::check_cycle_in_graph($shortcode, $content, self::batch_graph(), null);
        }
        return self::check_cycle($shortcode, $content, self::all_rows());
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
            if (self::$in_batch) {
                return self::check_cycle_in_graph($shortcode, $content, self::batch_graph(), strtolower((string) $existing->shortcode));
            }
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
