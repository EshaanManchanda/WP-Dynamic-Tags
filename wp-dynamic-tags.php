<?php
/**
 * Plugin Name: WP Dynamic Tags
 * Plugin URI: https://github.com/EshaanManchanda/WP-Dynamic-Tags
 * Description: Create and manage unlimited dynamic tags as shortcodes for use in WordPress, Elementor, and other page builders.
 * Version: 3.0.0
 * Author: Eshaan Manchanda
 * Author URI: https://eshaanportfolio.vercel.app/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dynamic-tags
 * Domain Path: /languages
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_DYNAMIC_TAGS_VERSION', '3.0.0');
define('WP_DYNAMIC_TAGS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_DYNAMIC_TAGS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Verbose Debug Logging
 *
 * To enable detailed verbose logging for development/debugging, add this to your wp-config.php:
 * define('DT_DEBUG_VERBOSE', true);
 *
 * This will enable additional logging for:
 * - Shortcode registration details
 * - Individual tag processing
 * - Validation details
 * - Group assignment changes
 *
 * Note: WP_DEBUG must also be enabled for any logging to occur.
 */

/**
 * Main WP Dynamic Tags Plugin Class
 */
class WP_Dynamic_Tags_Plugin
{

    private static $instance = null;
    private $post_type = 'dynamic_tag';
    private $import_export;
    private $placeholders;
    private $tag_groups;
    private $db_manager;
    private $migration_manager;
    private $migration_admin;
    private $table_manager;
    private $table_manager_admin;
    private $table_shortcode_processor;
    private $table_import_export;

    /**
     * Get plugin instance (Singleton pattern)
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('init', array($this, 'init'));
        add_filter('user_has_cap', array($this, 'grant_tag_capabilities'));
        add_action('admin_init', array($this, 'ensure_certificates_group'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Load enhanced classes
        $this->load_includes();

        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Admin notices of the plugin's lifecycle
        add_action('admin_notices', array($this, 'show_data_restoration_notice'));
        add_action('admin_notices', array($this, 'show_deactivation_notice'));

        // Sample CSV download handlers (the import screen links to them)
        add_action('admin_action_dt_download_sample_csv', array($this, 'download_sample_csv'));
        add_action('admin_action_dt_download_sample_full_csv', array($this, 'download_sample_full_csv'));
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        try {
            $this->register_post_type();
            $this->register_shortcodes();
            $this->load_textdomain();

            // Initialize enhanced features
            $this->init_enhanced_features();
        } catch (Exception $e) {
            // Log error for debugging but don't break the site
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags Plugin Init Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Grants the tag post type's own capabilities to everyone who holds the management capability (S4).
     * Filter dt_manage_capability (default manage_options) lets a site hand tag management to another capability.
     *
     * @param array<string, bool> $allcaps All capabilities of the user being checked.
     * @return array<string, bool>
     */
    public function grant_tag_capabilities($allcaps)
    {
        $manage = (string) apply_filters('dt_manage_capability', 'manage_options');
        if (!empty($allcaps[$manage])) {
            foreach (array(
                'edit_dynamic_tags', 'edit_others_dynamic_tags', 'publish_dynamic_tags', 'read_private_dynamic_tags',
                'delete_dynamic_tags', 'delete_private_dynamic_tags', 'delete_published_dynamic_tags',
                'delete_others_dynamic_tags', 'edit_private_dynamic_tags', 'edit_published_dynamic_tags',
            ) as $cap) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }

    /**
     * Register Custom Post Type for Dynamic Tags
     */
    public function register_post_type()
    {
        $labels = array(
            'name' => __('Dynamic Tags', 'wp-dynamic-tags'),
            'singular_name' => __('Dynamic Tag', 'wp-dynamic-tags'),
            'menu_name' => __('Dynamic Tags', 'wp-dynamic-tags'),
            'add_new' => __('Add New Tag', 'wp-dynamic-tags'),
            'add_new_item' => __('Add New Dynamic Tag', 'wp-dynamic-tags'),
            'new_item' => __('New Dynamic Tag', 'wp-dynamic-tags'),
            'edit_item' => __('Edit Dynamic Tag', 'wp-dynamic-tags'),
            'view_item' => __('View Dynamic Tag', 'wp-dynamic-tags'),
            'all_items' => __('All Tags', 'wp-dynamic-tags'),
            'search_items' => __('Search Tags', 'wp-dynamic-tags'),
            'not_found' => __('No tags found', 'wp-dynamic-tags'),
            'not_found_in_trash' => __('No tags found in trash', 'wp-dynamic-tags'),
        );

        // A tag registers a site-wide shortcode and can read data through tokens, so creating one is an administrator
        // capability (S4). With capability_type "post" every Author could publish tags. The type gets its own
        // capabilities (edit_dynamic_tags, ...), granted to whoever holds manage_options by grant_tag_capabilities().
        // They must NOT be mapped onto manage_options itself: with map_meta_cap WordPress then treats manage_options
        // as the meta capability edit_post and denies it to everybody (found by the test for this change).
        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => array('dynamic_tag', 'dynamic_tags'),
            'map_meta_cap' => true,
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 30,
            'menu_icon' => 'dashicons-tag',
            'supports' => array('title', 'editor'),
            'show_in_rest' => false,
        );

        register_post_type($this->post_type, $args);
    }

    /**
     * Whether this request should register the tag shortcodes (L4). Registration used to be skipped on every REST
     * request "to prevent timeouts", so shortcodes in REST-rendered content were left as raw text. They are registered by
     * default now; filter dt_register_shortcodes_on_rest returns false to skip them on REST requests.
     *
     * @param bool|null $is_rest Defaults to "is this a REST request".
     */
    public static function should_register_shortcodes($is_rest = null)
    {
        if ($is_rest === null) {
            $is_rest = defined('REST_REQUEST') && REST_REQUEST;
        }
        return !$is_rest || (bool) apply_filters('dt_register_shortcodes_on_rest', true);
    }

    /**
     * Register all shortcodes with fixed order and error handling
     */
    public function register_shortcodes()
    {
        // REST requests render post content too (block editor previews, headless front ends), so tags must be
        // registered there (L4). Sites with a very large number of tags can opt out with the filter.
        if (!self::should_register_shortcodes()) {
            return;
        }

        // The core [dt_*] shortcodes. Per-tag shortcodes are registered by one class only, the table processor
        // (WP_Dynamic_Tags_Table_Shortcode_Processor). This method used to register the tag posts as well, behind a
        // 3-second transient lock against the processor registering the same names (two database writes per request),
        // and then rendered every tag once to validate it. Registering twice is harmless, it writes nothing.
        add_shortcode('dt', array($this, 'fallback_shortcode'));
        add_shortcode('dt_group', array($this, 'group_shortcode'));
        add_shortcode('dt_random', array($this, 'random_shortcode'));
        add_shortcode('dt_count', array($this, 'count_shortcode'));
        add_shortcode('dt_list', array($this, 'list_shortcode'));
        add_shortcode('dt_loop', array($this, 'loop_shortcode'));
        add_shortcode('dt_if', array($this, 'if_shortcode'));
        add_shortcode('dt_template', array($this, 'template_shortcode'));
    }


    // REMOVED: All alias generation methods to simplify to single shortcode approach

    // REMOVED: All alias registration to ensure single shortcode per tag

    /**
     * Fallback shortcode handler - [dt tag="name" default="..."]. The tag is looked up in the table (its shortcode, the key
     * normalized, its name, a legacy alias) and rendered like its own shortcode; the output is filtered with wp_kses_post
     * as it always was.
     */
    public function fallback_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'key' => '',
            'tag' => '', // Alias for 'key', matching the README's [dt tag="..." default="..."] examples.
            'default' => '',
        ), $atts, 'dt');

        $key = !empty($atts['key']) ? $atts['key'] : $atts['tag'];

        if (empty($key)) {
            return '';
        }

        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $tag = $processor->find_tag($key);
        if (!$tag) {
            return $atts['default'];
        }

        $value = wp_kses_post($processor->render_row($tag));
        return $value !== '' ? $value : $atts['default'];
    }

    /**
     * Term id of a tag group given by name or slug, 0 when there is none.
     */
    private function group_term_id($group)
    {
        $term = get_term_by('name', $group, 'tag_groups');
        if (!$term) {
            $term = get_term_by('slug', $group, 'tag_groups');
        }
        return $term ? (int) $term->term_id : 0;
    }

    /**
     * Group shortcode handler - [dt_group group="contact"]: the active tags of a group, from the table.
     */
    public function group_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'group' => '',
            'format' => 'inline', // inline, list, grid
            'separator' => ', ',
            'before' => '',
            'after' => '',
            'limit' => -1,
            'order' => 'ASC',
            'orderby' => 'title'
        ), $atts, 'dt_group');

        if (empty($atts['group'])) {
            return '';
        }

        $term_id = $this->group_term_id($atts['group']);
        if (!$term_id) {
            return '';
        }

        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $tags = $processor->active_tags($term_id, $atts['orderby'], $atts['order'], intval($atts['limit']));

        if (empty($tags)) {
            return '';
        }

        $values = array();
        foreach ($tags as $tag) {
            $values[] = wp_kses_post($processor->render_row($tag));
        }

        // Format output
        $output = '';
        switch ($atts['format']) {
            case 'list':
                $output = '<ul><li>' . implode('</li><li>', $values) . '</li></ul>';
                break;
            case 'grid':
                $output = '<div class="dt-group-grid">' . implode('</div><div class="dt-group-item">', $values) . '</div>';
                break;
            case 'inline':
            default:
                $output = implode($atts['separator'], $values);
                break;
        }

        return $atts['before'] . $output . $atts['after'];
    }

    /**
     * Random tag shortcode - [dt_random group="quotes"]
     */
    public function random_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'group' => '',
            'count' => 1
        ), $atts, 'dt_random');

        $count = max(1, intval($atts['count']));

        // A group name that does not exist filters nothing (as before): every active tag is a candidate.
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $term_id = !empty($atts['group']) ? $this->group_term_id($atts['group']) : 0;
        $tags = $term_id ? $processor->active_tags($term_id) : $processor->all_active_tags();

        if (empty($tags)) {
            return '';
        }

        shuffle($tags);

        $values = array();
        foreach (array_slice($tags, 0, $count) as $tag) {
            $values[] = wp_kses_post($processor->render_row($tag));
        }

        return $count === 1 ? $values[0] : implode(' ', $values);
    }

    /**
     * Count shortcode - [dt_count group="seo"]
     */
    public function count_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'group' => '',
            'format' => 'number' // number, text
        ), $atts, 'dt_count');

        $count = $this->get_dynamic_tags_count($atts['group']);

        if ($atts['format'] === 'text') {
            return $this->number_to_text($count);
        }

        return (string) $count;
    }

    /**
     * Number of active tags, in every group or in one group (name or slug; an unknown group has none). The registry of the
     * request is already loaded, a group costs one COUNT query.
     */
    private function get_dynamic_tags_count($group_name = '')
    {
        if (empty($group_name)) {
            return count(WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->all_active_tags());
        }

        $term_id = $this->group_term_id($group_name);
        if (!$term_id || !$this->db_manager) {
            return 0;
        }

        return (int) $this->db_manager->get_tags_count(array('status' => 'active', 'category_id' => $term_id));
    }

    /**
     * Convert number to text format
     */
    private function number_to_text($count)
    {
        if ($count === 0) {
            return __('no tags', 'wp-dynamic-tags');
        } elseif ($count === 1) {
            return __('1 tag', 'wp-dynamic-tags');
        } else {
            return sprintf(__('%d tags', 'wp-dynamic-tags'), $count);
        }
    }

    /**
     * List shortcode - [dt_list group="contact" template="{key}: {value}"]
     */
    public function list_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'group' => '',
            'template' => '{value}',
            'separator' => '<br>',
            'limit' => -1,
            'order' => 'ASC',
            'orderby' => 'title'
        ), $atts, 'dt_list');

        // A group name that does not exist filters nothing (as before).
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $term_id = !empty($atts['group']) ? $this->group_term_id($atts['group']) : 0;
        $tags = $processor->active_tags($term_id ?: null, $atts['orderby'], $atts['order'], intval($atts['limit']));

        if (empty($tags)) {
            return '';
        }

        $items = array();
        foreach ($tags as $tag) {
            // Replace template variables
            $items[] = str_replace(
                array('{key}', '{value}', '{title}', '{shortcode}'),
                array(
                    esc_html($tag->shortcode),
                    wp_kses_post($processor->render_row($tag)),
                    esc_html($tag->tag_name),
                    '[' . esc_html($tag->shortcode) . ']'
                ),
                $atts['template']
            );
        }

        return implode($atts['separator'], $items);
    }

    /**
     * Loop shortcode - [dt_loop source="acf:gallery"]<img src="{item}">[/dt_loop]
     * Iterates an array-returning token source, re-rendering the enclosed template
     * once per item. {item} for scalar rows, {item:subfield} for associative rows
     * (e.g. repeater rows). Uses resolve_token_raw() to get the array before the
     * public {}-token path would stringify it.
     */
    public function loop_shortcode($atts, $content = '')
    {
        $atts = shortcode_atts(array(
            'source' => '',
            'separator' => '',
            'limit' => -1,
            'empty' => '',
        ), $atts, 'dt_loop');

        if (empty($atts['source']) || !$this->placeholders || !method_exists($this->placeholders, 'resolve_token_raw')) {
            return $atts['empty'];
        }

        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body($atts['source']);
        $items = $this->placeholders->resolve_token_raw($parsed['key'], $parsed['arg']);

        if (!is_array($items) || empty($items)) {
            return $atts['empty'];
        }

        $limit = intval($atts['limit']);
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        // The author's template is processed ONCE, before any row value is inserted. Row values are data (they can come
        // from a remote {api:} response): they used to be pasted into the template and the whole result was processed
        // again, so a value such as "{func:...}" or "<script>" was executed or injected (S1).
        $template = apply_filters('dt_process_value', $content);

        $rendered = array();
        foreach ($items as $item) {
            $rendered[] = self::fill_loop_template($template, $item);
        }

        return implode($atts['separator'], $rendered);
    }

    /**
     * Fills {item} / {item:field} in an already processed loop template with one row, in a single pass (S1).
     *
     * The value is escaped for the place it lands in: inside an HTML tag as an attribute value (esc_url for
     * href/src/action-like attributes, esc_attr otherwise), in element text through wp_kses_post (safe markup such as
     * <b> survives). The inserted value is never parsed again, so it cannot contain executable tokens.
     *
     * @param string $template Template with the author's tokens already resolved.
     * @param mixed  $item     A scalar, or an array row for {item:field}.
     */
    public static function fill_loop_template($template, $item)
    {
        $template = (string) $template;

        return preg_replace_callback('/\{item(?::([a-z0-9_]+))?\}/i', function ($m) use ($template, $item) {
            $field = isset($m[1]) && $m[1][0] !== '' ? $m[1][0] : null;

            if (is_array($item)) {
                $value = ($field !== null && isset($item[$field]) && is_scalar($item[$field])) ? (string) $item[$field] : '';
            } elseif ($field === null) {
                $value = (string) $item;
            } else {
                return $m[0][0]; // {item:field} on a scalar item stays as written, as before
            }

            if ($value === '') {
                return '';
            }

            $before = substr($template, 0, $m[0][1]);
            $lt = strrpos($before, '<');
            $gt = strrpos($before, '>');
            if ($lt !== false && ($gt === false || $lt > $gt)) {
                // Inside a tag: find the attribute this value belongs to.
                $url_attr = preg_match('/([a-z][a-z0-9:_-]*)\s*=\s*(?:"[^"]*|\'[^\']*|[^\s"\'>]*)$/i', substr($before, $lt), $a)
                    && in_array(strtolower($a[1]), array('href', 'src', 'action', 'formaction', 'poster', 'cite', 'data'), true);
                return $url_attr ? esc_url($value) : esc_attr($value);
            }

            return wp_kses_post($value);
        }, $template, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /**
     * Visibility shortcode - [dt_if condition="wc:price>50"]on sale copy[dt_else]default copy[/dt_if]
     * A block-level generalization of the {if:}...{else}...{/if} token (which
     * only branches text inside a Dynamic Tag's own content): this can wrap
     * ANY content dropped into a builder's HTML/shortcode widget, so it can
     * hide/show a whole rendered block, not just a merge-tag's text.
     * The [dt_else] marker is a plain string split, not a registered shortcode.
     */
    public function if_shortcode($atts, $content = '')
    {
        $atts = shortcode_atts(array(
            'condition' => '',
        ), $atts, 'dt_if');

        if (empty($atts['condition']) || !$this->placeholders || !method_exists($this->placeholders, 'evaluate_condition_public')) {
            return '';
        }

        $parts = explode('[dt_else]', $content, 2);
        $branch = $this->placeholders->evaluate_condition_public($atts['condition']) ? $parts[0] : (isset($parts[1]) ? $parts[1] : '');

        return apply_filters('dt_process_value', $branch);
    }

    /**
     * Dynamic template shortcode - [dt_template tag="product_card" query="post_type=product&posts_per_page=6"]
     * Renders an existing Dynamic Tag's content once per post in a WP_Query,
     * so every per-post placeholder ({post_title}, {wc:price}, {acf:...}, etc.)
     * resolves against each queried record instead of the current page's post.
     * `query` is a standard URL-querystring-style WP_Query args string
     * (parsed with wp_parse_str) - deliberately not a query-builder abstraction,
     * just this one shortcode's own args.
     */
    public function template_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'tag' => '',
            'query' => '',
            'separator' => '',
            'before' => '',
            'after' => '',
            'empty' => '',
        ), $atts, 'dt_template');

        if (empty($atts['tag'])) {
            return $atts['empty'];
        }

        // The template is a tag of the table; an empty one renders nothing.
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $tag = $processor->find_tag($atts['tag']);
        if (!$tag || (string) $tag->content === '') {
            return $atts['empty'];
        }

        $query_args = array(
            'post_type' => 'post',
            'posts_per_page' => 5,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        );
        // Whitelisted, viewer-aware arguments only (S2); the author's string can no longer widen post_status or row count.
        $query_args = WP_Dynamic_Tags_Placeholders::build_query_args((string) $atts['query'], $query_args);

        $query = new WP_Query($query_args);
        if (!$query->have_posts()) {
            wp_reset_postdata();
            return $atts['empty'];
        }

        // One render per queried post: the output depends on the current post, and the per-request output cache is keyed by it.
        $rendered = array();
        while ($query->have_posts()) {
            $query->the_post();
            $rendered[] = wp_kses_post($processor->render_row($tag));
        }
        wp_reset_postdata();

        return $atts['before'] . implode($atts['separator'], $rendered) . $atts['after'];
    }


    /** @var array<string, string> shortcode name => plugin folder (or "core") that owns it, recorded when we skipped a name */
    private static $shortcode_conflicts = array();

    /**
     * Whether the callback currently registered for a shortcode name belongs to this plugin (F1, L10). Decided by where the
     * callback's code lives (the main file, includes/ or admin/), not by the shape of the name: the old name patterns
     * matched every lowercase word, so "ours" meant "any shortcode".
     */
    public static function shortcode_is_ours($name)
    {
        global $shortcode_tags;
        if (!isset($shortcode_tags[$name])) {
            return false;
        }
        $file = self::callback_file($shortcode_tags[$name]);
        if ($file === null) {
            return false;
        }
        $file = wp_normalize_path($file);
        $dir = wp_normalize_path(WP_DYNAMIC_TAGS_PLUGIN_DIR);
        return $file === wp_normalize_path(__FILE__)
            || strpos($file, $dir . 'includes/') === 0
            || strpos($file, $dir . 'admin/') === 0;
    }

    /** The file a callable is defined in, or null when it cannot be determined. */
    private static function callback_file($callback)
    {
        try {
            if ($callback instanceof Closure) {
                return (new ReflectionFunction($callback))->getFileName() ?: null;
            }
            if (is_array($callback) && isset($callback[0])) {
                return (new ReflectionClass(is_object($callback[0]) ? get_class($callback[0]) : $callback[0]))->getFileName() ?: null;
            }
            if (is_string($callback) && strpos($callback, '::') !== false) {
                return (new ReflectionClass(explode('::', $callback, 2)[0]))->getFileName() ?: null;
            }
            if (is_string($callback) && function_exists($callback)) {
                return (new ReflectionFunction($callback))->getFileName() ?: null;
            }
        } catch (ReflectionException $e) {
            return null;
        }
        return null;
    }

    /** Remembers that a tag could not be registered because the name belongs to someone else. */
    private static function record_shortcode_conflict($name)
    {
        global $shortcode_tags;
        $file = isset($shortcode_tags[$name]) ? self::callback_file($shortcode_tags[$name]) : null;
        $owner = 'core';
        if ($file) {
            $file = wp_normalize_path($file);
            $plugins = wp_normalize_path(WP_PLUGIN_DIR) . '/';
            if (strpos($file, $plugins) === 0) {
                $owner = explode('/', substr($file, strlen($plugins)))[0];
            } elseif (strpos($file, wp_normalize_path(ABSPATH) . 'wp-includes/') !== 0) {
                $owner = basename(dirname($file));
            }
        }
        self::$shortcode_conflicts[$name] = $owner;
        do_action('dt_shortcode_conflict', $name, $owner);
    }

    /** Public entry for the table registrar, which lives in another class. */
    public static function note_shortcode_conflict($name)
    {
        self::record_shortcode_conflict($name);
    }

    /**
     * Tag shortcodes this request could not register because the name is already used.
     *
     * @return array<string, string> shortcode => owner (plugin folder, or "core")
     */
    public static function get_shortcode_conflicts()
    {
        return self::$shortcode_conflicts;
    }

    /**
     * wp_get_post_terms() that always returns an array (L7). WordPress answers with a WP_Error when the taxonomy does not
     * exist (this plugin registers tag_groups on init, so code running earlier sees one); callers that merge, count or index
     * the result were a PHP 8 fatal. Same parameters as wp_get_post_terms(); an error means "no terms".
     *
     * @param int          $post_id
     * @param string       $taxonomy
     * @param array<mixed> $args wp_get_object_terms() arguments, e.g. array('fields' => 'ids')
     * @return array<mixed>
     */
    public static function post_terms($post_id, $taxonomy = 'post_tag', $args = array())
    {
        $terms = wp_get_post_terms($post_id, $taxonomy, $args);
        return is_wp_error($terms) ? array() : $terms;
    }



    /**
     * Show data restoration notice after plugin activation
     */
    public function show_data_restoration_notice()
    {
        $restored_data = get_transient('wp_dynamic_tags_data_restored');

        if (!$restored_data || !$restored_data['has_data']) {
            return;
        }

        $total_tags = $restored_data['post_tags_count'] + $restored_data['table_tags_count'];

        ?>
        <div class="notice notice-success is-dismissible">
            <h3><?php esc_html_e('🎉 Welcome Back to WP Dynamic Tags!', 'wp-dynamic-tags'); ?></h3>
            <p>
                <strong><?php esc_html_e('Your data has been automatically restored:', 'wp-dynamic-tags'); ?></strong>
            </p>
            <ul style="margin-left: 20px; list-style: disc;">
                <?php if ($restored_data['post_tags_count'] > 0): ?>
                    <li>
                        <?php
                        printf(
                            esc_html(_n('%d tag post', '%d tag posts', $restored_data['post_tags_count'], 'wp-dynamic-tags')),
                            $restored_data['post_tags_count']
                        );
                        ?>
                    </li>
                <?php endif; ?>
                <?php if ($restored_data['table_tags_count'] > 0): ?>
                    <li>
                        <?php
                        printf(
                            esc_html(_n('%d database tag', '%d database tags', $restored_data['table_tags_count'], 'wp-dynamic-tags')),
                            $restored_data['table_tags_count']
                        );
                        ?>
                    </li>
                <?php endif; ?>
                <?php if ($restored_data['groups_count'] > 0): ?>
                    <li>
                        <?php
                        printf(
                            esc_html(_n('%d tag group', '%d tag groups', $restored_data['groups_count'], 'wp-dynamic-tags')),
                            $restored_data['groups_count']
                        );
                        ?>
                    </li>
                <?php endif; ?>
            </ul>
            <p>
                <strong><?php esc_html_e('✅ All your shortcodes are working and ready to use!', 'wp-dynamic-tags'); ?></strong>
            </p>
        </div>
        <?php

        // Delete the transient so notice only shows once
        delete_transient('wp_dynamic_tags_data_restored');
    }

    /**
     * Show deactivation notice about data retention
     */
    public function show_deactivation_notice()
    {
        $show_notice = get_transient('wp_dynamic_tags_deactivated');

        if (!$show_notice) {
            return;
        }

        $delete_on_uninstall = get_option('wp_dynamic_tags_delete_on_uninstall', false);
        $settings_url = admin_url('edit.php?post_type=dynamic_tag&page=dt-settings');

        ?>
        <div class="notice notice-info is-dismissible">
            <h3><?php esc_html_e('WP Dynamic Tags Deactivated', 'wp-dynamic-tags'); ?></h3>
            <?php if (!$delete_on_uninstall): ?>
                <p>
                    <strong><?php esc_html_e('✅ Your data is safe!', 'wp-dynamic-tags'); ?></strong><br>
                    <?php esc_html_e('All your tags and groups have been preserved. If you reinstall the plugin, everything will work automatically.', 'wp-dynamic-tags'); ?>
                </p>
            <?php else: ?>
                <p>
                    <strong><?php esc_html_e('⚠️ Data deletion is enabled', 'wp-dynamic-tags'); ?></strong><br>
                    <?php esc_html_e('If you uninstall the plugin, all data will be permanently deleted.', 'wp-dynamic-tags'); ?>
                </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary">
                    <?php esc_html_e('Manage Data Retention Settings', 'wp-dynamic-tags'); ?>
                </a>
            </p>
        </div>
        <?php

        // Delete the transient so notice only shows once
        delete_transient('wp_dynamic_tags_deactivated');
    }

    /**
     * Download sample.csv file
     */
    public function download_sample_csv()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download sample files.', 'wp-dynamic-tags'));
        }

        $file_path = WP_DYNAMIC_TAGS_PLUGIN_DIR . 'assets/sample.csv';

        if (!file_exists($file_path)) {
            wp_die(__('Sample file not found.', 'wp-dynamic-tags'));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dynamic-tags-sample.csv"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    /**
     * Download sample-full.csv file
     */
    public function download_sample_full_csv()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download sample files.', 'wp-dynamic-tags'));
        }

        $file_path = WP_DYNAMIC_TAGS_PLUGIN_DIR . 'assets/sample-full.csv';

        if (!file_exists($file_path)) {
            wp_die(__('Sample file not found.', 'wp-dynamic-tags'));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dynamic-tags-sample-full.csv"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('wp-dynamic-tags', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Load includes first to ensure classes are available
        $this->load_includes();

        // Register post type first
        $this->register_post_type();

        // Create custom table with error handling
        $table_created = false;
        if (class_exists('WP_Dynamic_Tags_Database_Manager')) {
            $db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();
            $result = $db_manager->create_table();

            if (is_wp_error($result)) {
                // Log the error but don't fail activation
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags Activation: Table creation failed - ' . $result->get_error_message());
                }
                // Store error for later display
                update_option('wp_dynamic_tags_table_creation_error', $result->get_error_message());
            } else {
                $table_created = true;
                delete_option('wp_dynamic_tags_table_creation_error');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags Activation: Table created successfully');
                }
            }
        } else {
            $error_msg = 'Database Manager class not available during activation';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags Activation: ' . $error_msg);
            }
            update_option('wp_dynamic_tags_table_creation_error', $error_msg);
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set activation flag
        add_option('wp_dynamic_tags_activated', true);

        // Set table integration based on whether table was created successfully
        if (!get_option('wp_dynamic_tags_table_integration_enabled')) {
            add_option('wp_dynamic_tags_table_integration_enabled', $table_created);
        }

        // Store activation timestamp
        update_option('wp_dynamic_tags_activation_time', current_time('mysql'));

        // Detect and restore existing data
        $this->detect_and_restore_existing_data();

        // Ensure the "certificates" tag group exists for Certificate-Generator integration
        $this->ensure_certificates_group();

        if (class_exists('DT_License_Manager')) {
            DT_License_Manager::schedule_cron();
        }
    }

    /**
     * Ensure the "certificates" tag group (taxonomy term) exists.
     * Certificate-Generator uses this group when syncing student/teacher/school data.
     * Safe to call multiple times — only creates if it does not exist.
     */
    public function ensure_certificates_group()
    {
        if (!taxonomy_exists('tag_groups')) {
            return;
        }

        $term = term_exists('certificates', 'tag_groups');
        if (!$term) {
            wp_insert_term(
                'Certificates',
                'tag_groups',
                [
                    'slug' => 'certificates',
                    'description' => 'Tags synced from Certificate-Generator plugin',
                ]
            );
        }
    }

    /**
     * Detect existing data and prepare for restoration
     */
    private function detect_and_restore_existing_data()
    {
        global $wpdb;

        $existing_data = array(
            'has_data' => false,
            'post_tags_count' => 0,
            'table_tags_count' => 0,
            'groups_count' => 0
        );

        // Check for existing posts
        $post_count = wp_count_posts($this->post_type);
        $existing_data['post_tags_count'] = isset($post_count->publish) ? intval($post_count->publish) : 0;

        // Check for existing custom table data
        $table_name = $wpdb->prefix . 'dynamic_tags';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name) {
            $table_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'active'");
            $existing_data['table_tags_count'] = intval($table_count);
        }

        // Check for existing groups
        $groups = get_terms(array(
            'taxonomy' => 'tag_groups',
            'hide_empty' => false
        ));
        $existing_data['groups_count'] = is_array($groups) ? count($groups) : 0;

        // Determine if we have existing data
        $existing_data['has_data'] = ($existing_data['post_tags_count'] > 0 ||
            $existing_data['table_tags_count'] > 0 ||
            $existing_data['groups_count'] > 0);

        if ($existing_data['has_data']) {
            // Set transient to show restoration notice
            set_transient('wp_dynamic_tags_data_restored', $existing_data, 60);

            // Log the restoration
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'WP Dynamic Tags: Existing data detected - %d post tags, %d table tags, %d groups',
                    $existing_data['post_tags_count'],
                    $existing_data['table_tags_count'],
                    $existing_data['groups_count']
                ));
            }

        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        if (class_exists('DT_License_Manager')) {
            DT_License_Manager::unschedule_cron();
        }

        if (class_exists('WP_Dynamic_Tags_Migration_Admin')) {
            WP_Dynamic_Tags_Migration_Admin::unschedule_cron();
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Remove activation flag
        delete_option('wp_dynamic_tags_activated');

        // Set transient to show deactivation notice next time admin loads
        set_transient('wp_dynamic_tags_deactivated', true, DAY_IN_SECONDS);
    }

    /**
     * Get database manager instance
     */
    public function get_db_manager()
    {
        return $this->db_manager;
    }

    /**
     * Get migration manager instance
     */
    public function get_migration_manager()
    {
        return $this->migration_manager;
    }

    /**
     * Get table manager instance
     */
    public function get_table_manager()
    {
        return $this->table_manager;
    }

    /**
     * Get post-table bridge instance
     */
    public function get_post_table_bridge()
    {
        return null; // the Bridge is no longer started (plan Phase 3); the admin screens treat null as "not available"
    }

    /**
     * Get table shortcode processor instance
     */
    public function get_table_shortcode_processor()
    {
        return $this->table_shortcode_processor;
    }

    /**
     * Get table import/export instance
     */
    public function get_table_import_export()
    {
        return $this->table_import_export;
    }

    /**
     * Check if table integration is enabled
     */
    public function is_table_integration_enabled()
    {
        return get_option('wp_dynamic_tags_table_integration_enabled', false);
    }

    /**
     * Load enhanced feature classes
     */
    private function load_includes()
    {
        $includes_dir = WP_DYNAMIC_TAGS_PLUGIN_DIR . 'includes/';

        // Pure {if}/{else}/{/if} block parser shared by both rendering engines (L1); no dependencies, load first.
        require_once $includes_dir . 'class-conditional-parser.php';

        if (file_exists($includes_dir . 'class-license-manager.php')) {
            require_once $includes_dir . 'class-license-manager.php';
        }

        // Load new table system classes first
        if (file_exists($includes_dir . 'class-database-manager.php')) {
            require_once $includes_dir . 'class-database-manager.php';
        }

        // Posts -> table migration (planner is pure logic; the engine does the work; admin/CLI are the entry points)
        foreach (array('class-migration-planner.php', 'class-migration-engine.php', 'class-migration-admin.php') as $migration_file) {
            if (file_exists($includes_dir . $migration_file)) {
                require_once $includes_dir . $migration_file;
            }
        }
        if (defined('WP_CLI') && WP_CLI && file_exists($includes_dir . 'class-migration-cli.php')) {
            require_once $includes_dir . 'class-migration-cli.php';
        }

        if (file_exists($includes_dir . 'class-migration-manager.php')) {
            require_once $includes_dir . 'class-migration-manager.php';
        }

        if (file_exists($includes_dir . 'class-table-manager.php')) {
            require_once $includes_dir . 'class-table-manager.php';
        }

        if (file_exists($includes_dir . 'class-table-shortcode-processor.php')) {
            require_once $includes_dir . 'class-table-shortcode-processor.php';
        }

        if (file_exists($includes_dir . 'class-table-import-export.php')) {
            require_once $includes_dir . 'class-table-import-export.php';
        }

        // Load existing classes
        if (file_exists($includes_dir . 'class-import-export.php')) {
            require_once $includes_dir . 'class-import-export.php';
        }

        if (file_exists($includes_dir . 'class-dynamic-formatters.php')) {
            require_once $includes_dir . 'class-dynamic-formatters.php';
        }

        if (file_exists($includes_dir . 'class-dynamic-placeholders.php')) {
            require_once $includes_dir . 'class-dynamic-placeholders.php';
        }

        if (file_exists($includes_dir . 'class-acf-integration.php')) {
            require_once $includes_dir . 'class-acf-integration.php';
        }

        if (file_exists($includes_dir . 'class-woocommerce-integration.php')) {
            require_once $includes_dir . 'class-woocommerce-integration.php';
        }

        if (file_exists($includes_dir . 'class-tag-groups.php')) {
            require_once $includes_dir . 'class-tag-groups.php';
        }

        if (file_exists(plugin_dir_path(__FILE__) . 'admin/table-manager-admin.php')) {
            require_once plugin_dir_path(__FILE__) . 'admin/table-manager-admin.php';
        }

        if (file_exists(plugin_dir_path(__FILE__) . 'admin/settings-page.php')) {
            require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
        }
    }

    /**
     * Initialize enhanced features
     */
    private function init_enhanced_features()
    {
        // Initialize table system first
        if (class_exists('WP_Dynamic_Tags_Database_Manager')) {
            $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();

            // Ensure table exists as a backup to constructor logic
            if ($this->db_manager && method_exists($this->db_manager, 'ensure_table_exists')) {
                $this->db_manager->ensure_table_exists();
            }
        }

        if (class_exists('WP_Dynamic_Tags_Migration_Manager')) {
            $this->migration_manager = WP_Dynamic_Tags_Migration_Manager::get_instance();
        }

        // Migration entry points: admin notice + AJAX, WP-Cron. Never triggered by a frontend request.
        if (class_exists('WP_Dynamic_Tags_Migration_Admin') && class_exists('WP_Dynamic_Tags_Migration_Engine')) {
            $this->migration_admin = new WP_Dynamic_Tags_Migration_Admin();
            $this->migration_admin->register();
        }

        if (class_exists('WP_Dynamic_Tags_Table_Manager')) {
            $this->table_manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        }

        // Writes to legacy tag posts (save, trash, delete, group change) are carried into the table (plan Phase 3c).
        if (class_exists('WP_Dynamic_Tags_Migration_Engine')) {
            WP_Dynamic_Tags_Migration_Engine::register_capture_hooks();
        }

        // The post <-> table Bridge is no longer started: the table is the only store, and writes to the legacy tag posts
        // are captured by WP_Dynamic_Tags_Migration_Engine::register_capture_hooks() (plan Phase 3). The class is deleted with
        // the rest of the legacy code in Phase 5; get_post_table_bridge() answers null meanwhile.

        if (class_exists('WP_Dynamic_Tags_Table_Shortcode_Processor')) {
            $this->table_shortcode_processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        }

        if (class_exists('WP_Dynamic_Tags_Table_Import_Export')) {
            $this->table_import_export = WP_Dynamic_Tags_Table_Import_Export::get_instance();
        }

        // Initialize import/export
        if (class_exists('WP_Dynamic_Tags_Import_Export')) {
            $this->import_export = new WP_Dynamic_Tags_Import_Export();
        }

        // Initialize dynamic placeholders
        if (class_exists('WP_Dynamic_Tags_Placeholders')) {
            $this->placeholders = new WP_Dynamic_Tags_Placeholders();
        } else {
            // Debug: log if class not found
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: WP_Dynamic_Tags_Placeholders class not found during initialization');

                // Try to load the file again as a fallback
                $includes_dir = plugin_dir_path(__FILE__) . 'includes/';
                $placeholder_file = $includes_dir . 'class-dynamic-placeholders.php';
                if (file_exists($placeholder_file)) {
                    require_once $placeholder_file;
                    if (class_exists('WP_Dynamic_Tags_Placeholders')) {
                        $this->placeholders = new WP_Dynamic_Tags_Placeholders();
                        error_log('WP Dynamic Tags: Placeholders loaded via fallback');
                    } else {
                        error_log('WP Dynamic Tags: File exists but class still not found at: ' . $placeholder_file);
                    }
                } else {
                    error_log('WP Dynamic Tags: Placeholders file not found at: ' . $placeholder_file);
                }
            }
        }

        // Initialize tag groups
        if (class_exists('WP_Dynamic_Tags_Groups')) {
            $this->tag_groups = new WP_Dynamic_Tags_Groups();
        }

        // Initialize table manager admin interface
        if (is_admin() && class_exists('WP_Dynamic_Tags_Table_Manager_Admin')) {
            $this->table_manager_admin = new WP_Dynamic_Tags_Table_Manager_Admin();
        }

        // Shortcodes of existing tags are registered by the table processor on init (also after a reinstall). A SHOW TABLES
        // and a COUNT(*) used to run here on every request just to clear two registration locks; the locks are gone.
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on our plugin pages
        if (strpos($hook, 'dynamic_tag') === false && strpos($hook, 'dt-') === false) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'dt-admin-css',
            WP_DYNAMIC_TAGS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_DYNAMIC_TAGS_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'dt-admin-js',
            WP_DYNAMIC_TAGS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WP_DYNAMIC_TAGS_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('dt-admin-js', 'dtAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'inline_edit' => wp_create_nonce('dt_inline_edit'),
                'bulk_delete' => wp_create_nonce('dt_bulk_delete'),
                'duplicate' => wp_create_nonce('dt_duplicate'),
                'export' => wp_create_nonce('dt_export')
            ),
            'strings' => array(
                'saved' => __('Changes saved successfully.', 'wp-dynamic-tags'),
                'error' => __('An error occurred.', 'wp-dynamic-tags'),
                'confirmBulkDelete' => __('Are you sure you want to delete the selected tags?', 'wp-dynamic-tags'),
                'confirmDuplicate' => __('A tag with this key already exists. Do you want to update it?', 'wp-dynamic-tags'),
                'invalidFileFormat' => __('Please select a JSON or CSV file.', 'wp-dynamic-tags'),
                'shortcodeCopied' => __('Shortcode copied to clipboard!', 'wp-dynamic-tags'),
                'copyFailed' => __('Failed to copy shortcode. Please copy manually.', 'wp-dynamic-tags'),
                'fileTooLarge' => __('File is too large. Maximum size is 5MB.', 'wp-dynamic-tags'),
                'selectedFile' => __('Selected file', 'wp-dynamic-tags'),
                'placeholderCopied' => __('Placeholder copied to clipboard!', 'wp-dynamic-tags'),
                'copied' => __('Copied!', 'wp-dynamic-tags'),
                'keySanitized' => __('Tag key was automatically sanitized.', 'wp-dynamic-tags'),
                'fillRequired' => __('Please fill in both tag key and value.', 'wp-dynamic-tags')
            )
        ));
    }

}

// Initialize the plugin
add_action('plugins_loaded', function () {
    WP_Dynamic_Tags_Plugin::get_instance();
});
