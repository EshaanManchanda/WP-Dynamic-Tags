<?php
/**
 * Plugin Name: WP Dynamic Tags
 * Plugin URI: https://github.com/yourusername/wp-dynamic-tags
 * Description: Create and manage unlimited dynamic tags as shortcodes for use in WordPress, Elementor, and other page builders.
 * Version: 3.0.0
 * Author: Eshaan Manchanda
 * Author URI: https://eshaanportfolio.vercel.app/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-dynamic-tags
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_DYNAMIC_TAGS_VERSION', '2.0.0');
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
    private $transient_key = 'wp_dynamic_tags_cache';
    private $bulk_manager;
    private $import_export;
    private $placeholders;
    private $tag_groups;
    public $conflict_resolver;  // Public so conflict manager admin can access
    private $conflict_manager_admin;

    // Performance optimization: shared cache instance
    private $shared_tags_cache = null;
    private $shared_tags_timestamp = 0;
    private $db_manager;
    private $migration_manager;
    private $table_manager;
    private $post_table_bridge;
    private $table_manager_admin;
    private $table_shortcode_processor;
    private $table_import_export;

    // Performance optimization: Cache expensive operations during admin page load
    private $admin_cache = array(
        'duplicate_tags' => null,
        'all_groups' => null,
        'initialized' => false
    );

    // Multi-level caching for performance optimization
    private static $static_cache = array();
    private $cache_version = '2.0.1'; // Increment when cache structure changes

    // Performance monitoring
    private static $performance_stats = array();
    private static $query_count = 0;

    // Registration throttling and state tracking
    private static $registration_state = array(
        'is_registered' => false,
        'last_registration_time' => 0,
        'registration_fingerprint' => '',
        'registration_count' => 0
    );

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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_quick_create'));
        add_action('admin_init', array($this, 'handle_conflict_resolution_actions'));
        add_action('admin_init', array($this, 'ensure_certificates_group'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Load enhanced classes
        $this->load_includes();

        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Hook to refresh shortcodes when posts are updated
        add_action('save_post', array($this, 'refresh_shortcodes_on_save'));
        add_action('set_object_terms', array($this, 'refresh_shortcodes_on_terms_change'), 10, 6);
        add_action('delete_post', array($this, 'refresh_shortcodes_on_delete'));

        // Hook to validate duplicate tag names on save
        add_action('save_post', array($this, 'validate_tag_name_on_save'), 5); // Priority 5 to run before other save hooks

        // Show admin notices for conflicts
        add_action('admin_notices', array($this, 'show_existing_conflict_notices'));

        // Show admin notice for menu consolidation
        add_action('admin_notices', array($this, 'show_menu_consolidation_notice'));

        // Show admin notices for bulk/row actions
        add_action('admin_notices', array($this, 'show_action_notices'));
        add_action('admin_notices', array($this, 'show_data_restoration_notice'));
        add_action('admin_notices', array($this, 'show_deactivation_notice'));
        add_action('wp_ajax_dt_dismiss_menu_notice', array($this, 'dismiss_menu_notice'));

        // Debug shortcode testing
        add_action('wp_ajax_dt_test_shortcode', array($this, 'test_shortcode_ajax'));

        // Row action handlers
        add_action('admin_action_dt_duplicate_tag', array($this, 'handle_duplicate_tag_action'));
        add_action('admin_action_dt_export_single', array($this, 'handle_export_single_action'));

        // AJAX handler for real-time duplicate checking
        add_action('wp_ajax_dt_check_duplicate', array($this, 'ajax_check_duplicate'));

        // Sample CSV download handlers
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

        // Admin customizations
        if (is_admin()) {
            add_filter('manage_' . $this->post_type . '_posts_columns', array($this, 'custom_columns'));
            add_action('manage_' . $this->post_type . '_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
            add_filter('manage_edit-' . $this->post_type . '_sortable_columns', array($this, 'sortable_columns'));

            // Add filter functionality
            add_action('restrict_manage_posts', array($this, 'add_admin_filters'));
            add_filter('parse_query', array($this, 'filter_admin_query'));
            add_action('pre_get_posts', array($this, 'modify_admin_query'));

            // Add bulk actions
            add_filter('bulk_actions-edit-' . $this->post_type, array($this, 'register_bulk_actions'));
            add_filter('handle_bulk_actions-edit-' . $this->post_type, array($this, 'handle_bulk_actions'), 10, 3);

            // Add row actions
            add_filter('post_row_actions', array($this, 'add_row_actions'), 10, 2);

            // Add editor meta boxes
            add_action('add_meta_boxes', array($this, 'add_editor_meta_boxes'));
        }
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

        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
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
     * Register all shortcodes with fixed order and error handling
     */
    public function register_shortcodes()
    {
        // Skip registration during REST API requests to prevent timeouts
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Lock mechanism to prevent duplicate registration calls
        $lock_key = 'dt_registration_lock';
        if (get_transient($lock_key)) {
            // Registration already in progress or recently completed
            return;
        }

        // Set lock for 3 seconds
        set_transient($lock_key, true, 3);

        try {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                error_log('WP Dynamic Tags: Starting shortcode registration...');
            }

            // Register fallback shortcode
            add_shortcode('dt', array($this, 'fallback_shortcode'));

            // Register new group-based shortcodes
            add_shortcode('dt_group', array($this, 'group_shortcode'));
            add_shortcode('dt_random', array($this, 'random_shortcode'));
            add_shortcode('dt_count', array($this, 'count_shortcode'));
            add_shortcode('dt_list', array($this, 'list_shortcode'));

            // Register individual tag shortcodes
            $this->register_simple_shortcodes();

            // REMOVED: clear_shortcode_cache() call that was deleting registered shortcodes

            // Production validation - ensure registration was successful
            $this->validate_all_registrations();

            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                error_log('WP Dynamic Tags: Shortcode registration completed');
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags Registration Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Generate fingerprint for current tags state to detect changes
     */
    private function generate_tags_fingerprint()
    {
        global $wpdb;

        // Get count and last modified date for quick fingerprint
        $result = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) as count, MAX(post_modified) as last_modified
            FROM {$wpdb->posts}
            WHERE post_type = %s AND post_status = 'publish'
        ", $this->post_type));

        $fingerprint = md5($result->count . '_' . $result->last_modified);
        wp_cache_set('dt_tags_fingerprint', $fingerprint, '', 300); // Cache for 5 minutes

        return $fingerprint;
    }

    /**
     * Get all dynamic tags with caching - FIXED to prevent duplicate key overwrites
     */
    /**
     * Optimized method to get dynamic tags with multi-level caching
     */
    private function get_dynamic_tags($limit = 0, $offset = 0)
    {
        $cache_key = $this->transient_key . '_' . $this->cache_version;
        if ($limit > 0) {
            $cache_key .= '_' . $limit . '_' . $offset;
        }

        // Level 1: Static cache (fastest)
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        // Level 2: Transient cache (fast)
        $cached_tags = get_transient($cache_key);
        if ($cached_tags !== false && is_array($cached_tags)) {
            self::$static_cache[$cache_key] = $cached_tags;
            return $cached_tags;
        }

        // Level 3: Database query (optimized)
        $tags = $this->fetch_dynamic_tags_from_db($limit, $offset);

        // Cache results at both levels
        self::$static_cache[$cache_key] = $tags;
        set_transient($cache_key, $tags, HOUR_IN_SECONDS);

        return $tags;
    }

    /**
     * Optimized database fetch with pagination support and improved indexing
     */
    private function fetch_dynamic_tags_from_db($limit = 0, $offset = 0)
    {
        global $wpdb;

        // Optimized SQL with proper indexing hints
        $sql = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_content, p.post_modified
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            ORDER BY p.post_title ASC
        ", $this->post_type);

        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        // Use WordPress cache to avoid duplicate queries
        $cache_key = 'dt_db_query_' . md5($sql);
        $posts = wp_cache_get($cache_key, 'dynamic_tags');

        if ($posts === false) {
            $posts = $wpdb->get_results($sql);
            wp_cache_set($cache_key, $posts, 'dynamic_tags', 300); // 5 minute cache
        }

        $tags = array();

        // Optimized processing with reduced function calls
        foreach ($posts as $post) {
            $tag_key = $this->sanitize_tag_key($post->post_title);
            if (!empty($tag_key)) {
                // Use unique key format: post_id:tag_key to prevent overwrites
                $unique_key = $post->ID . ':' . $tag_key;
                $tags[$unique_key] = array(
                    'content' => $post->post_content,
                    'post_id' => (int) $post->ID,
                    'title' => $post->post_title,
                    'tag_key' => $tag_key,
                    'modified' => $post->post_modified
                );
            }
        }

        return $tags;
    }


    // REMOVED: All alias generation methods to simplify to single shortcode approach

    // REMOVED: All alias registration to ensure single shortcode per tag

    /**
     * Fallback shortcode handler - UPDATED to work with new data structure
     */
    public function fallback_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'key' => '',
        ), $atts, 'dt');

        if (empty($atts['key'])) {
            return '';
        }

        $tags = $this->get_dynamic_tags();
        $sanitized_key = $this->sanitize_tag_key($atts['key']);

        // Find tag by matching tag_key in the new structure
        foreach ($tags as $unique_key => $tag_data) {
            if ($tag_data['tag_key'] === $sanitized_key) {
                $value = $tag_data['content'];
                // Process dynamic placeholders if available
                if ($this->placeholders) {
                    $value = apply_filters('dt_process_value', $value);
                }
                return wp_kses_post($value);
            }
        }

        return '';
    }

    /**
     * Group shortcode handler - [dt_group group="contact"]
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

        // Get group by name or slug
        $group = get_term_by('name', $atts['group'], 'tag_groups');
        if (!$group) {
            $group = get_term_by('slug', $atts['group'], 'tag_groups');
        }

        if (!$group) {
            return '';
        }

        // Get tags in this group
        $posts = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => intval($atts['limit']),
            'orderby' => $atts['orderby'],
            'order' => strtoupper($atts['order']),
            'tax_query' => array(
                array(
                    'taxonomy' => 'tag_groups',
                    'field' => 'term_id',
                    'terms' => $group->term_id
                )
            )
        ));

        if (empty($posts)) {
            return '';
        }

        $values = array();
        foreach ($posts as $post) {
            $value = $post->post_content;
            // Process dynamic placeholders if available
            if ($this->placeholders) {
                $value = apply_filters('dt_process_value', $value);
            }
            $values[] = wp_kses_post($value);

            // Update usage statistics
            $tag_key = $this->sanitize_tag_key($post->post_title);
            $this->update_tag_usage($tag_key);
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

        // Build query args
        $query_args = array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => 'rand'
        );

        // Add group filter if specified
        if (!empty($atts['group'])) {
            $group = get_term_by('name', $atts['group'], 'tag_groups');
            if (!$group) {
                $group = get_term_by('slug', $atts['group'], 'tag_groups');
            }

            if ($group) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => 'tag_groups',
                        'field' => 'term_id',
                        'terms' => $group->term_id
                    )
                );
            }
        }

        $posts = get_posts($query_args);

        if (empty($posts)) {
            return '';
        }

        $values = array();
        foreach ($posts as $post) {
            $value = $post->post_content;
            // Process dynamic placeholders if available
            if ($this->placeholders) {
                $value = apply_filters('dt_process_value', $value);
            }
            $values[] = wp_kses_post($value);

            // Update usage statistics
            $tag_key = $this->sanitize_tag_key($post->post_title);
            $this->update_tag_usage($tag_key);
        }

        return $count === 1 ? $values[0] : implode(' ', $values);
    }

    /**
     * Optimized count shortcode - [dt_count group="seo"]
     */
    public function count_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'group' => '',
            'format' => 'number' // number, text
        ), $atts, 'dt_count');

        // Use optimized counting method
        $count = $this->get_dynamic_tags_count($atts['group']);

        if ($atts['format'] === 'text') {
            return $this->number_to_text($count);
        }

        return (string) $count;
    }

    /**
     * Optimized counting method using direct SQL
     */
    private function get_dynamic_tags_count($group_name = '')
    {
        global $wpdb;

        $cache_key = 'count_' . ($group_name ? md5($group_name) : 'all');

        // Check static cache first
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        if (empty($group_name)) {
            // Count all dynamic tags
            $sql = $wpdb->prepare("
                SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_type = %s
                AND post_status = 'publish'
            ", $this->post_type);
        } else {
            // Count tags in specific group
            $group = get_term_by('name', $group_name, 'tag_groups');
            if (!$group) {
                $group = get_term_by('slug', $group_name, 'tag_groups');
            }

            if (!$group) {
                return 0;
            }

            $sql = $wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE p.post_type = %s
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'tag_groups'
                AND tt.term_id = %d
            ", $this->post_type, $group->term_id);
        }

        $count = (int) $wpdb->get_var($sql);

        // Cache the result
        self::$static_cache[$cache_key] = $count;

        return $count;
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

        $query_args = array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => intval($atts['limit']),
            'orderby' => $atts['orderby'],
            'order' => strtoupper($atts['order'])
        );

        // Add group filter if specified
        if (!empty($atts['group'])) {
            $group = get_term_by('name', $atts['group'], 'tag_groups');
            if (!$group) {
                $group = get_term_by('slug', $atts['group'], 'tag_groups');
            }

            if ($group) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => 'tag_groups',
                        'field' => 'term_id',
                        'terms' => $group->term_id
                    )
                );
            }
        }

        $posts = get_posts($query_args);

        if (empty($posts)) {
            return '';
        }

        $items = array();
        foreach ($posts as $post) {
            $tag_key = $this->sanitize_tag_key($post->post_title);
            $value = $post->post_content;

            // Process dynamic placeholders if available
            if ($this->placeholders) {
                $value = apply_filters('dt_process_value', $value);
            }

            // Replace template variables
            $item = str_replace(
                array('{key}', '{value}', '{title}', '{shortcode}'),
                array(
                    esc_html($tag_key),
                    wp_kses_post($value),
                    esc_html($post->post_title),
                    '[' . esc_html($tag_key) . ']'
                ),
                $atts['template']
            );

            $items[] = $item;

            // Update usage statistics
            $this->update_tag_usage($tag_key);
        }

        return implode($atts['separator'], $items);
    }

    /**
     * Register enhanced shortcodes with group support - FIXED to use cached data
     */
    /**
     * NEW CLEAN REGISTRATION: Simple [group_tag] logic with one shortcode per tag
     */
    /**
     * Optimized shortcode registration with lazy loading
     */
    private function register_simple_shortcodes()
    {
        // Performance metrics tracking
        $start_time = microtime(true);

        // Use optimized caching system instead of direct DB query
        $cached_tags = $this->get_dynamic_tags();

        if (empty($cached_tags)) {
            return;
        }

        $registered_count = 0;
        $failed_count = 0;
        $skipped_count = 0;
        $batch_logs = array(); // Collect logs for batching

        // Use cached data for faster registration
        foreach ($cached_tags as $unique_key => $tag_data) {
            try {
                $tag_title = $tag_data['title'];
                $tag_content = $tag_data['content'];
                $post_id = $tag_data['post_id'];

                // Skip if essential data is missing
                if (empty($tag_content) || empty($tag_title)) {
                    $skipped_count++;
                    $batch_logs[] = "Skipped tag ID {$post_id} - missing data";
                    continue;
                }

                // Generate the ONE shortcode for this tag
                $shortcode_key = $this->generate_single_shortcode($post_id, $tag_title);

                // ENSURE NO CONFLICTING SHORTCODES: Remove base shortcode for grouped tags
                $this->remove_conflicting_shortcodes($post_id, $tag_title, $shortcode_key);

                // Register the shortcode
                if ($this->register_clean_shortcode($shortcode_key, $tag_content, $post_id)) {
                    $registered_count++;
                    // Only log every 10th registration to reduce noise
                    if ($registered_count % 10 === 0) {
                        $batch_logs[] = "Registered {$registered_count} shortcodes (latest: [{$shortcode_key}])";
                    }
                } else {
                    $failed_count++;
                    $batch_logs[] = "Failed: [{$shortcode_key}] for '{$tag_title}'";
                }

            } catch (Exception $e) {
                $failed_count++;
                $batch_logs[] = "Error processing tag ID {$post_id}: " . $e->getMessage();
            }
        }

        // Performance metrics and batched logging
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Only log if there are issues or verbose debugging is enabled
            $is_verbose = defined('DT_DEBUG_VERBOSE') && DT_DEBUG_VERBOSE;
            $has_issues = $failed_count > 0 || $execution_time > 100; // Log if failures or slow (>100ms)

            if ($is_verbose || $has_issues) {
                // Single performance summary log
                error_log("WP Dynamic Tags: Registration complete - {$registered_count} registered, {$failed_count} failed, {$skipped_count} skipped in {$execution_time}ms");

                // Only log batch details if there are failures or in detailed debug mode
                if ($failed_count > 0 || $is_verbose) {
                    error_log("WP Dynamic Tags: Details - " . implode('; ', array_slice($batch_logs, 0, 10)) .
                        (count($batch_logs) > 10 ? '... (' . (count($batch_logs) - 10) . ' more)' : ''));
                }
            }
        }
    }


    /**
     * Smart shortcode management - track registered shortcodes to avoid conflicts
     */
    private static $registered_shortcodes = array();

    /**
     * Register a shortcode with conflict prevention
     */
    private function register_shortcode_safely($shortcode_name, $callback)
    {
        // Remove if already exists
        if (shortcode_exists($shortcode_name)) {
            remove_shortcode($shortcode_name);
        }

        // Register new shortcode
        add_shortcode($shortcode_name, $callback);

        // Track it
        self::$registered_shortcodes[$shortcode_name] = true;

        if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
            error_log("WP Dynamic Tags: Safely registered shortcode [{$shortcode_name}]");
        }

        return true;
    }

    /**
     * Clean only our tracked shortcodes (much more efficient)
     */
    private function cleanup_tracked_shortcodes()
    {
        $removed_count = 0;
        foreach (self::$registered_shortcodes as $shortcode => $status) {
            if (shortcode_exists($shortcode)) {
                remove_shortcode($shortcode);
                $removed_count++;
            }
        }
        self::$registered_shortcodes = array();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Cleaned up {$removed_count} tracked shortcodes");
        }
    }

    /**
     * Remove conflicting shortcodes for a specific tag
     */
    private function remove_conflicting_shortcodes($post_id, $tag_title, $primary_shortcode)
    {
        $tag_key = $this->sanitize_tag_key($tag_title);
        $groups = wp_get_post_terms($post_id, 'tag_groups');
        $has_groups = !is_wp_error($groups) && !empty($groups);

        // For grouped tags: remove base shortcode
        if ($has_groups) {
            if (shortcode_exists($tag_key)) {
                remove_shortcode($tag_key);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Dynamic Tags: Removed conflicting base shortcode [{$tag_key}] for grouped tag '{$tag_title}'");
                }
            }
        }
        // For ungrouped tags: remove any potential group shortcodes
        else {
            // Check for group-based shortcodes that might exist
            $potential_group_shortcodes = array(
                'math_olympiad_' . $tag_key,
                'science_olympiad_' . $tag_key,
                'general_' . $tag_key
            );

            foreach ($potential_group_shortcodes as $group_shortcode) {
                if (shortcode_exists($group_shortcode)) {
                    remove_shortcode($group_shortcode);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("WP Dynamic Tags: Removed conflicting group shortcode [{$group_shortcode}] for ungrouped tag '{$tag_title}'");
                    }
                }
            }
        }
    }

    /**
     * NEW CLEAN: Register a single shortcode without complex conflict resolution
     */
    /**
     * Optimized shortcode registration with safe conflict handling
     */
    private function register_clean_shortcode($shortcode_key, $tag_content, $post_id)
    {
        // Skip empty or invalid shortcode keys
        if (empty($shortcode_key) || !is_string($shortcode_key)) {
            return false;
        }

        // Create optimized callback with reduced overhead
        $callback = function ($atts) use ($tag_content, $post_id, $shortcode_key) {
            // Frontend debug logging - only in verbose mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                $context = is_admin() ? 'ADMIN' : 'FRONTEND';
                error_log("WP Dynamic Tags: [{$shortcode_key}] called in {$context} context");
                error_log("WP Dynamic Tags: Raw content for [{$shortcode_key}]: " . $tag_content);
            }

            // Track usage (only if needed)
            if (is_admin() || (defined('WP_DEBUG') && WP_DEBUG)) {
                $this->track_shortcode_usage($post_id);
            }

            // Process the tag content through the placeholder system
            $processed_content = apply_filters('dt_process_value', $tag_content);

            // Fallback check if placeholder processing didn't work
            if ($processed_content === $tag_content && $this->placeholders) {
                if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                    error_log("WP Dynamic Tags: Filter didn't process content, trying direct placeholder processing");
                }
                $processed_content = $this->placeholders->process_placeholders($tag_content);
            }

            // Frontend debug logging for processed content - only in verbose mode
            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                $changed = ($processed_content !== $tag_content) ? 'CHANGED' : 'UNCHANGED';
                error_log("WP Dynamic Tags: Processed content for [{$shortcode_key}] ({$changed}): " . $processed_content);
            }

            // Return the processed content
            return $processed_content;
        };

        // Use safe registration method
        return $this->register_shortcode_safely($shortcode_key, Closure::bind($callback, $this));
    }

    /**
     * Track shortcode usage for analytics
     */
    private function track_shortcode_usage($post_id)
    {
        $current_count = get_post_meta($post_id, '_dt_usage_count', true) ?: 0;
        update_post_meta($post_id, '_dt_usage_count', $current_count + 1);
        update_post_meta($post_id, '_dt_last_used', current_time('mysql'));
    }

    /**
     * NEW: Validate the simplified shortcode system (ensure one shortcode per tag)
     */
    public function validate_simplified_system()
    {
        global $shortcode_tags;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Validating simplified shortcode system...');
        }

        // Get all published tags
        $tags = get_posts(array(
            'post_type' => $this->post_type,
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));

        $validation_results = array(
            'total_tags' => count($tags),
            'successful' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($tags as $tag) {
            $expected_shortcode = $this->generate_single_shortcode($tag->ID, $tag->post_title);

            if (shortcode_exists($expected_shortcode)) {
                // Test functionality
                $test_result = do_shortcode("[{$expected_shortcode}]");
                if (!empty($test_result) && $test_result !== "[{$expected_shortcode}]") {
                    $validation_results['successful']++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("WP Dynamic Tags: ✓ Tag '{$tag->post_title}' -> [{$expected_shortcode}] works");
                    }
                } else {
                    $validation_results['failed']++;
                    $validation_results['errors'][] = "Tag '{$tag->post_title}' shortcode [{$expected_shortcode}] registered but not functional";
                }
            } else {
                $validation_results['failed']++;
                $validation_results['errors'][] = "Tag '{$tag->post_title}' shortcode [{$expected_shortcode}] not registered";
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Validation complete - {$validation_results['successful']}/{$validation_results['total_tags']} tags working");
        }

        return $validation_results;
    }


    /**
     * Check if a shortcode belongs to our plugin
     */
    private function is_our_shortcode($shortcode_key)
    {
        // Check if shortcode matches our patterns
        $our_patterns = array(
            '/^[a-z0-9_]+$/',  // Basic tag pattern
            '/^[a-z0-9_]+_[a-z0-9_]+$/',  // Group_tag pattern
            '/^dt_/',  // Our special shortcodes
            '/^math_olympiad_/',  // Known group prefix
            '/^science_olympiad_/',  // Known group prefix
        );

        foreach ($our_patterns as $pattern) {
            if (preg_match($pattern, $shortcode_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract base key from a potentially group-prefixed shortcode
     */
    private function extract_base_key($shortcode_key)
    {
        // If it contains underscore, try to extract the last part as base key
        if (strpos($shortcode_key, '_') !== false) {
            $parts = explode('_', $shortcode_key);
            return end($parts);
        }
        return $shortcode_key;
    }

    /**
     * Clear only dynamic tag shortcodes (surgical removal, not aggressive)
     */
    private function clear_dynamic_tag_shortcodes()
    {
        global $shortcode_tags;
        $removed_count = 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Starting selective shortcode clearing...');
        }

        // More precise patterns - avoid removing non-dynamic-tag shortcodes
        $precise_patterns = array(
            '/^dt_/',                    // Our dt_ prefixed shortcodes
            '/^[a-z]+_olympiad_[a-z0-9_]+$/', // Group-based olympiad shortcodes
            '/^math_olympiad_[a-z0-9_]+$/',   // Math olympiad specific
            '/^science_olympiad_[a-z0-9_]+$/', // Science olympiad specific
        );

        // Get list of our registered dynamic tags for precise removal
        $known_tag_shortcodes = $this->get_known_tag_shortcodes();

        foreach ($shortcode_tags as $shortcode => $callback) {
            $should_remove = false;

            // Check against precise patterns
            foreach ($precise_patterns as $pattern) {
                if (preg_match($pattern, $shortcode)) {
                    $should_remove = true;
                    break;
                }
            }

            // Check against known tag shortcodes
            if (!$should_remove && in_array($shortcode, $known_tag_shortcodes)) {
                $should_remove = true;
            }

            if ($should_remove) {
                remove_shortcode($shortcode);
                $removed_count++;

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Dynamic Tags: Removed shortcode [{$shortcode}]");
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Cleared {$removed_count} dynamic tag shortcodes");
        }

        // Clear WordPress shortcode caches
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('shortcodes');
        }
    }

    /**
     * Get list of known tag shortcodes for precise removal
     */
    private function get_known_tag_shortcodes()
    {
        $known_shortcodes = array();

        try {
            $cached_tags = $this->get_dynamic_tags();

            foreach ($cached_tags as $unique_key => $tag_data) {
                $post_id = $tag_data['post_id'];
                $post_title = $tag_data['title'];

                $shortcode = $this->get_tag_shortcode($post_id, $post_title);
                $known_shortcodes[] = $shortcode;
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Error getting known shortcodes: ' . $e->getMessage());
            }
        }

        return array_unique($known_shortcodes);
    }

    /**
     * Refresh shortcodes when a post is deleted
     */
    public function refresh_shortcodes_on_delete($post_id)
    {
        if (get_post_type($post_id) === $this->post_type) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Refreshing shortcodes after post {$post_id} deletion");
            }

            // Clear cache and precisely re-register shortcodes
            delete_transient($this->transient_key);
            $this->clear_dynamic_tag_shortcodes();
            $this->register_shortcodes();
        }
    }

    /**
     * Refresh shortcodes when post is saved (enhanced)
     */
    public function refresh_shortcodes_on_save($post_id)
    {
        // Check if this is our post type
        if (get_post_type($post_id) !== $this->post_type) {
            return;
        }

        // Skip on autosave and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Prevent duplicate processing for the same post within 3 seconds
        $refresh_lock = 'dt_refresh_lock_' . $post_id;
        if (get_transient($refresh_lock)) {
            return; // Already processed recently
        }
        set_transient($refresh_lock, true, 3);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
            error_log("WP Dynamic Tags: Refreshing shortcodes after post {$post_id} save");
        }

        // Clear cache and refresh shortcodes
        $this->clear_all_plugin_caches();

        // Only re-register if this is a published post
        if (get_post_status($post_id) === 'publish') {
            $this->clear_dynamic_tag_shortcodes();
            $this->register_shortcodes();

            // Validate registration worked
            $this->validate_shortcode_registration($post_id);
        }
    }

    /**
     * Validate that shortcode registration worked for a specific post
     */
    private function validate_shortcode_registration($post_id)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return; // Only run validation in debug mode
        }

        // Skip verbose validation unless DT_DEBUG_VERBOSE is set
        if (!defined('DT_DEBUG_VERBOSE')) {
            return;
        }

        try {
            $post_title = get_the_title($post_id);
            // Get the single shortcode for this tag (uses new simplified logic)
            $expected_shortcode = $this->get_tag_shortcode($post_id, $post_title);

            // Test if the expected shortcode exists
            if (shortcode_exists($expected_shortcode)) {
                error_log("WP Dynamic Tags: ✓ Validation PASSED - Shortcode [{$expected_shortcode}] exists for post {$post_id} ('{$post_title}')");

                // Test if it actually works
                $test_result = do_shortcode("[{$expected_shortcode}]");
                if (!empty($test_result) && $test_result !== "[{$expected_shortcode}]") {
                    error_log("WP Dynamic Tags: ✓ Shortcode [{$expected_shortcode}] is functional - Output: " . substr($test_result, 0, 50));
                } else {
                    error_log("WP Dynamic Tags: ✗ Shortcode [{$expected_shortcode}] exists but not functional");
                }
            } else {
                error_log("WP Dynamic Tags: ✗ Validation FAILED - Shortcode [{$expected_shortcode}] does NOT exist for post {$post_id} ('{$post_title}')");
            }

            // Additional debug info about groups (if any)
            $tag_groups = wp_get_post_terms($post_id, 'tag_groups');
            if (!is_wp_error($tag_groups) && !empty($tag_groups)) {
                $group_names = array();
                foreach ($tag_groups as $group) {
                    $group_names[] = $group->name;
                }
                error_log("WP Dynamic Tags: Post {$post_id} groups: " . implode(', ', $group_names));
            }

        } catch (Exception $e) {
            error_log("WP Dynamic Tags: Validation error for post {$post_id}: " . $e->getMessage());
        }
    }

    /**
     * Production-ready validation of all shortcode registrations
     */
    private function validate_all_registrations()
    {
        try {
            $cached_tags = $this->get_dynamic_tags();
            $total_tags = count($cached_tags);
            $successful_registrations = 0;
            $failed_registrations = 0;
            $registration_errors = array();

            foreach ($cached_tags as $unique_key => $tag_data) {
                $post_id = $tag_data['post_id'];
                $post_title = $tag_data['title'];

                // Get the single shortcode for this tag
                $expected_shortcode = $this->get_tag_shortcode($post_id, $post_title);

                // Validate registration
                if (shortcode_exists($expected_shortcode)) {
                    // Test functionality
                    $test_result = do_shortcode("[{$expected_shortcode}]");
                    if (!empty($test_result) && $test_result !== "[{$expected_shortcode}]") {
                        $successful_registrations++;
                    } else {
                        $failed_registrations++;
                        $registration_errors[] = "Shortcode [{$expected_shortcode}] registered but not functional (post: {$post_title})";
                    }
                } else {
                    $failed_registrations++;
                    $registration_errors[] = "Shortcode [{$expected_shortcode}] not registered (post: {$post_title})";
                }

                // Note: Legacy validation removed - new system prevents conflicts at registration
            }

            // Log production summary - only if errors found or verbose mode enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Always log errors
                if ($failed_registrations > 0) {
                    error_log("WP Dynamic Tags Production Validation:");
                    error_log("  Total tags: {$total_tags}");
                    error_log("  Successful registrations: {$successful_registrations}");
                    error_log("  Failed registrations: {$failed_registrations}");
                    error_log("  Errors found:");
                    foreach ($registration_errors as $error) {
                        error_log("    - {$error}");
                    }
                } elseif (defined('DT_DEBUG_VERBOSE')) {
                    // Only log success summary in verbose mode
                    error_log("WP Dynamic Tags Production Validation:");
                    error_log("  Total tags: {$total_tags}");
                    error_log("  Successful registrations: {$successful_registrations}");
                    error_log("  Failed registrations: {$failed_registrations}");
                }
            }

            // Store validation results for admin notices
            if ($failed_registrations > 0) {
                update_option('dt_validation_errors', $registration_errors);
                update_option('dt_validation_stats', array(
                    'total' => $total_tags,
                    'successful' => $successful_registrations,
                    'failed' => $failed_registrations,
                    'last_check' => current_time('c')
                ));
            } else {
                delete_option('dt_validation_errors');
                delete_option('dt_validation_stats');
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Validation error: " . $e->getMessage());
            }
        }
    }

    /**
     * Show admin notice for validation errors (production-ready)
     */
    public function show_validation_errors()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $errors = get_option('dt_validation_errors');
        $stats = get_option('dt_validation_stats');

        if (!empty($errors)) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<h3>WP Dynamic Tags: Registration Issues Detected</h3>';
            echo '<p><strong>Some shortcodes failed to register properly:</strong></p>';
            echo '<ul>';
            foreach (array_slice($errors, 0, 5) as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            if (count($errors) > 5) {
                echo '<li><em>... and ' . (count($errors) - 5) . ' more issues</em></li>';
            }
            echo '</ul>';

            if (!empty($stats)) {
                echo '<p><strong>Statistics:</strong> ' . $stats['successful'] . ' working, ' . $stats['failed'] . ' failed out of ' . $stats['total'] . ' total tags.</p>';
            }

            echo '<p><em>Enable WP_DEBUG to see detailed error logging.</em></p>';
            echo '</div>';
        }
    }

    /**
     * Enhanced shortcode refresh for better performance
     */
    public function force_refresh_shortcodes()
    {
        try {
            // Clear all related caches (transients, static cache, admin cache)
            $this->clear_all_plugin_caches();

            // Re-register all shortcodes
            $this->register_shortcodes();

            return true;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Force refresh shortcodes failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Enforce single shortcode per tag by removing conflicting variants
     */
    private function enforce_single_shortcode_per_tag($base_key, $group_key, $has_groups, $tag_title)
    {
        global $shortcode_tags;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Enforcing single shortcode for tag '{$tag_title}' (base: '{$base_key}', group: '{$group_key}', has_groups: " . ($has_groups ? 'yes' : 'no') . ")");
        }

        // Determine which shortcode should NOT exist (the conflicting one)
        if ($has_groups) {
            // For grouped tags: remove base shortcode if it exists
            $conflicting_shortcode = $base_key;
            $keep_shortcode = $group_key;
        } else {
            // For ungrouped tags: remove group shortcode if it exists
            $conflicting_shortcode = $group_key;
            $keep_shortcode = $base_key;
        }

        // Remove the conflicting shortcode if it exists
        if (!empty($conflicting_shortcode) && shortcode_exists($conflicting_shortcode)) {
            // Only remove if it's actually one of our shortcodes
            if ($this->is_our_shortcode($conflicting_shortcode)) {
                remove_shortcode($conflicting_shortcode);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Dynamic Tags: ⚠ Removed conflicting shortcode '[{$conflicting_shortcode}]' for tag '{$tag_title}' (keeping '[{$keep_shortcode}]')");
                }
            }
        }

        // Also check for and remove any other potential conflicting patterns
        $tag_base = $this->extract_base_key($base_key);
        $conflicting_patterns = array();

        if ($has_groups) {
            // For grouped tags, remove any base variants
            $conflicting_patterns = array($tag_base, $base_key);
        } else {
            // For ungrouped tags, remove any group variants
            $all_groups = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
            if (!is_wp_error($all_groups) && !empty($all_groups)) {
                foreach ($all_groups as $group) {
                    $group_slug = $this->sanitize_tag_key($group->name);
                    $conflicting_patterns[] = $group_slug . '_' . $tag_base;
                }
            }
        }

        // Remove any conflicting patterns found
        foreach ($conflicting_patterns as $pattern) {
            if (!empty($pattern) && shortcode_exists($pattern) && $this->is_our_shortcode($pattern)) {
                remove_shortcode($pattern);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Dynamic Tags: ⚠ Removed conflicting pattern '[{$pattern}]' for tag '{$tag_title}'");
                }
            }
        }
    }

    /**
     * Initialize admin cache for performance (with error handling)
     */
    private function init_admin_cache()
    {
        if ($this->admin_cache['initialized']) {
            return;
        }

        try {
            // Cache duplicate tags detection (expensive operation)
            $this->admin_cache['duplicate_tags'] = $this->check_for_duplicate_tag_names_uncached();

            // Cache groups for quick access (with taxonomy check)
            if (taxonomy_exists('tag_groups')) {
                $this->admin_cache['all_groups'] = get_terms(array(
                    'taxonomy' => 'tag_groups',
                    'hide_empty' => false
                ));

                // Ensure we have a valid array
                if (is_wp_error($this->admin_cache['all_groups'])) {
                    $this->admin_cache['all_groups'] = array();
                }
            } else {
                $this->admin_cache['all_groups'] = array();
            }

            $this->admin_cache['initialized'] = true;
        } catch (Exception $e) {
            // Set fallback values on error
            $this->admin_cache['duplicate_tags'] = array();
            $this->admin_cache['all_groups'] = array();
            $this->admin_cache['initialized'] = true;
        }
    }

    /**
     * Get cached duplicate tags (performance optimized)
     */
    private function get_cached_duplicate_tags()
    {
        $this->init_admin_cache();
        return $this->admin_cache['duplicate_tags'];
    }

    /**
     * Check for duplicate tag names across all tags
     */
    private function check_for_duplicate_tag_names()
    {
        // Use cached version if available (for admin page performance)
        if (is_admin() && !empty($this->admin_cache['duplicate_tags'])) {
            return $this->admin_cache['duplicate_tags'];
        }

        return $this->check_for_duplicate_tag_names_uncached();
    }

    /**
     * Original uncached version for when we need fresh data
     */
    /**
     * Optimized duplicate checking using direct SQL
     */
    private function check_for_duplicate_tag_names_uncached()
    {
        global $wpdb;

        // Use SQL to find duplicates directly
        $sql = $wpdb->prepare("
            SELECT post_title, COUNT(*) as count, GROUP_CONCAT(ID) as post_ids
            FROM {$wpdb->posts}
            WHERE post_type = %s
            AND post_status = 'publish'
            GROUP BY post_title
            HAVING count > 1
            ORDER BY post_title
        ", $this->post_type);

        $results = $wpdb->get_results($sql);
        $tag_names = array();

        foreach ($results as $result) {
            $tag_key = $this->sanitize_tag_key($result->post_title);
            if (!empty($tag_key)) {
                $post_ids = explode(',', $result->post_ids);
                $tag_names[$tag_key] = array();

                foreach ($post_ids as $post_id) {
                    $tag_names[$tag_key][] = array(
                        'post_id' => (int) $post_id,
                        'title' => $result->post_title
                    );
                }
            }
        }

        return $tag_names;
    }

    /**
     * Get ungrouped duplicate tags that need group assignment
     */
    public function get_ungrouped_duplicate_tags()
    {
        $duplicate_tags = $this->check_for_duplicate_tag_names();
        $ungrouped_duplicates = array();

        foreach ($duplicate_tags as $tag_key => $posts) {
            foreach ($posts as $post_data) {
                $post_id = $post_data['post_id'];
                $groups = wp_get_post_terms($post_id, 'tag_groups');

                if (empty($groups) || is_wp_error($groups)) {
                    if (!isset($ungrouped_duplicates[$tag_key])) {
                        $ungrouped_duplicates[$tag_key] = array();
                    }
                    $ungrouped_duplicates[$tag_key][] = $post_data;
                }
            }
        }

        return $ungrouped_duplicates;
    }

    /**
     * Check if a tag name is available for ungrouped use
     */
    public function is_tag_name_available($tag_name, $exclude_post_id = 0)
    {
        $sanitized_key = $this->sanitize_tag_key($tag_name);
        $duplicate_tags = $this->check_for_duplicate_tag_names();

        if (!isset($duplicate_tags[$sanitized_key])) {
            return true; // No duplicates, name is available
        }

        // Check if any of the existing tags with this name are ungrouped
        foreach ($duplicate_tags[$sanitized_key] as $post_data) {
            if ($post_data['post_id'] == $exclude_post_id) {
                continue; // Skip the post we're editing
            }

            $groups = wp_get_post_terms($post_data['post_id'], 'tag_groups');
            if (empty($groups) || is_wp_error($groups)) {
                return false; // Found an ungrouped tag with same name
            }
        }

        return true; // All existing tags with this name are grouped
    }

    /**
     * Optimized tag usage tracking with caching
     */
    private function update_tag_usage($tag_key)
    {
        // Skip usage tracking if not needed (performance optimization)
        if (!is_admin() && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }

        $cache_key = 'post_id_' . md5($tag_key);

        // Check static cache first
        if (!isset(self::$static_cache[$cache_key])) {
            global $wpdb;

            // Use direct SQL for better performance
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status = 'publish' LIMIT 1",
                $this->post_type,
                $tag_key
            ));

            self::$static_cache[$cache_key] = $post_id ? (int) $post_id : null;
        }

        $post_id = self::$static_cache[$cache_key];
        if ($post_id) {
            // Update usage statistics
            $current_count = get_post_meta($post_id, '_dt_usage_count', true) ?: 0;
            update_post_meta($post_id, '_dt_usage_count', $current_count + 1);
            update_post_meta($post_id, '_dt_last_used', current_time('mysql'));
        }
    }

    /**
     * Sanitize tag key
     */
    private function sanitize_tag_key($key)
    {
        // NEW SIMPLIFIED SANITIZATION: only lowercase letters, numbers, and underscores
        $key = strtolower(trim($key));
        // Remove all non-alphanumeric characters and replace with underscore
        $key = preg_replace('/[^a-z0-9]/', '_', $key);
        // Collapse multiple underscores into single underscore
        $key = preg_replace('/_{2,}/', '_', $key);
        // Remove leading/trailing underscores
        $key = trim($key, '_');

        // Ensure we have a valid key
        if (empty($key)) {
            $key = 'unnamed_tag';
        }

        return $key;
    }

    /**
     * NEW SIMPLIFIED: Generate the ONE shortcode for a tag based on [group_tag] logic
     */
    public function generate_single_shortcode($post_id, $tag_title)
    {
        $tag_key = $this->sanitize_tag_key($tag_title);

        // Get groups for this tag (for logging and metadata only)
        $groups = wp_get_post_terms($post_id, 'tag_groups');

        // Check for conflicts with existing tags
        $conflicts = $this->check_shortcode_conflicts($post_id, $tag_key, $groups);

        // SIMPLIFIED: Always use [tag_key] format regardless of groups
        // Groups are now only for organizational purposes, not part of the shortcode
        $shortcode = $tag_key;

        // Check for shortcode conflicts and resolve if needed
        if ($this->shortcode_exists_for_other_tag($shortcode, $post_id)) {
            $shortcode = $this->resolve_shortcode_conflict($shortcode, $post_id, false);
        }

        if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
            if (!is_wp_error($groups) && !empty($groups)) {
                $primary_group = $groups[0];
                $conflict_status = $conflicts ? ' (resolved conflicts)' : '';
                error_log("WP Dynamic Tags: Generated shortcode [{$shortcode}] for tag '{$tag_title}' in group '{$primary_group->name}'{$conflict_status}");
            } else {
                error_log("WP Dynamic Tags: Generated shortcode [{$shortcode}] for tag '{$tag_title}'");
            }
        }

        return $shortcode;
    }

    /**
     * Check for shortcode conflicts
     */
    private function check_shortcode_conflicts($post_id, $tag_key, $groups)
    {
        $conflicts = array();

        // Check for tags with same title but different grouping
        $similar_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'post__not_in' => array($post_id),
            'title' => get_the_title($post_id)
        ));

        if (!empty($similar_tags)) {
            foreach ($similar_tags as $similar_tag) {
                $similar_groups = wp_get_post_terms($similar_tag->ID, 'tag_groups');
                $conflicts[] = array(
                    'tag_id' => $similar_tag->ID,
                    'tag_title' => $similar_tag->post_title,
                    'groups' => $similar_groups,
                    'shortcode' => $this->generate_basic_shortcode($similar_tag->ID, $similar_tag->post_title, $similar_groups)
                );
            }
        }

        return $conflicts;
    }

    /**
     * Generate basic shortcode without conflict resolution
     */
    private function generate_basic_shortcode($post_id, $tag_title, $groups)
    {
        $tag_key = $this->sanitize_tag_key($tag_title);

        if (!is_wp_error($groups) && !empty($groups)) {
            $primary_group = $groups[0];
            $group_key = $this->sanitize_tag_key($primary_group->name);
            return $group_key . '_' . $tag_key;
        } else {
            return $tag_key;
        }
    }

    /**
     * Check if shortcode exists for another tag
     */
    private function shortcode_exists_for_other_tag($shortcode, $exclude_post_id)
    {
        global $shortcode_tags;

        if (isset($shortcode_tags[$shortcode])) {
            // Shortcode exists, check if it's for a different tag
            $existing_tags = get_posts(array(
                'post_type' => $this->post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'post__not_in' => array($exclude_post_id),
                'meta_query' => array(
                    array(
                        'key' => '_dt_shortcode',
                        'value' => $shortcode,
                        'compare' => '='
                    )
                )
            ));

            return !empty($existing_tags);
        }

        return false;
    }

    /**
     * Find grouped alternative for an ungrouped tag
     */
    private function find_grouped_alternative($tag_key, $exclude_post_id)
    {
        $current_title = get_the_title($exclude_post_id);

        // Look for tags with same title that are grouped
        $grouped_tags = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post__not_in' => array($exclude_post_id),
            'title' => $current_title,
            'tax_query' => array(
                array(
                    'taxonomy' => 'tag_groups',
                    'operator' => 'EXISTS'
                )
            )
        ));

        if (!empty($grouped_tags)) {
            $grouped_tag = $grouped_tags[0];
            $groups = wp_get_post_terms($grouped_tag->ID, 'tag_groups');
            if (!empty($groups)) {
                $group_key = $this->sanitize_tag_key($groups[0]->name);
                return $group_key . '_' . $tag_key;
            }
        }

        return false;
    }

    /**
     * Resolve shortcode conflict by creating unique version
     */
    private function resolve_shortcode_conflict($shortcode, $post_id, $is_grouped = false)
    {
        $counter = 1;
        $base_shortcode = $shortcode;

        // Keep incrementing until we find a unique shortcode
        while ($this->shortcode_exists_for_other_tag($shortcode, $post_id)) {
            $counter++;
            $shortcode = $base_shortcode . '_' . $counter;
        }

        if ($counter > 1) {
            // Store conflict resolution info
            update_post_meta($post_id, '_dt_conflict_resolved', true);
            update_post_meta($post_id, '_dt_original_shortcode', $base_shortcode);
            update_post_meta($post_id, '_dt_resolved_shortcode', $shortcode);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Resolved shortcode conflict - {$base_shortcode} became {$shortcode}");
            }
        }

        return $shortcode;
    }

    /**
     * SIMPLIFIED: Get the single shortcode for a tag (replaces complex get_tag_shortcodes)
     */
    public function get_tag_shortcode($post_id, $tag_title)
    {
        return $this->generate_single_shortcode($post_id, $tag_title);
    }

    /**
     * Clear cache when posts are saved/deleted
     */
    /**
     * Smart cache invalidation with granular control
     */
    private function clear_cache($specific_key = null)
    {
        if ($specific_key) {
            // Clear only specific cache entry
            $cache_key = $this->transient_key . '_' . $this->cache_version . '_' . $specific_key;
            delete_transient($cache_key);
            unset(self::$static_cache[$cache_key]);
        } else {
            // Clear all cache entries for this plugin
            $this->clear_all_plugin_caches();
        }
    }

    /**
     * Clear all plugin-related caches efficiently
     */
    private function clear_all_plugin_caches()
    {
        global $wpdb;

        // Clear transients in batch
        $transient_pattern = $this->transient_key . '_' . $this->cache_version . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $transient_pattern,
            '_transient_timeout_' . $transient_pattern
        ));

        // Clear static cache
        self::$static_cache = array();

        // Clear admin cache
        $this->admin_cache = array(
            'duplicate_tags' => null,
            'all_groups' => null,
            'initialized' => false
        );
    }

    /**
     * Clear admin cache for performance optimization
     */
    private function clear_admin_cache()
    {
        $this->admin_cache = array(
            'duplicate_tags' => null,
            'all_groups' => null,
            'initialized' => false
        );
    }


    /**
     * Refresh shortcodes when terms are changed
     */
    public function refresh_shortcodes_on_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
        if ($taxonomy === 'tag_groups' && get_post_type($object_id) === $this->post_type) {
            $this->clear_cache();
            $this->clear_admin_cache();
            // Re-register shortcodes with fresh data since group assignments changed
            $this->register_simple_shortcodes();
        }
    }

    /**
     * Validate tag name on save to prevent duplicate ungrouped tags
     */
    public function validate_tag_name_on_save($post_id)
    {
        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check post type
        if (get_post_type($post_id) !== $this->post_type) {
            return;
        }

        // Check if user can edit
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Skip if this is a new post being created
        if (get_post_status($post_id) === 'auto-draft') {
            return;
        }

        $post_title = get_the_title($post_id);

        // Check if this tag name is available for ungrouped use
        if (!$this->is_tag_name_available($post_title, $post_id)) {
            // Get groups assigned to this post
            $groups = wp_get_post_terms($post_id, 'tag_groups');

            if (empty($groups) || is_wp_error($groups)) {
                // This tag has a duplicate name and no groups - prevent save
                $this->add_duplicate_name_error($post_id, $post_title);

                // Store error in transient for display
                set_transient('dt_duplicate_error_' . $post_id, $post_title, 60);

                // Add an admin notice for the conflict
                add_action('admin_notices', function () use ($post_title) {
                    $this->show_duplicate_tag_notice($post_title);
                });

                // Change post status to draft to prevent publication
                remove_action('save_post', array($this, 'validate_tag_name_on_save'), 5);
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'draft'
                ));
                add_action('save_post', array($this, 'validate_tag_name_on_save'), 5);

                // Set validation error flag
                update_post_meta($post_id, '_dt_has_conflict', 1);
            } else {
                // Clear conflict flag if groups are assigned
                delete_post_meta($post_id, '_dt_has_conflict');
            }
        }
    }

    /**
     * Add duplicate name error for logging
     */
    private function add_duplicate_name_error($post_id, $post_title)
    {
        $errors = get_option('dt_duplicate_errors', array());
        $errors[] = array(
            'post_id' => $post_id,
            'title' => $post_title,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        );

        // Keep only last 50 errors
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }

        update_option('dt_duplicate_errors', $errors);
    }

    /**
     * Show admin notice for duplicate tag conflicts
     */
    public function show_duplicate_tag_notice($tag_title)
    {
        global $post;

        // Check for transient error
        if ($post && get_transient('dt_duplicate_error_' . $post->ID)) {
            delete_transient('dt_duplicate_error_' . $post->ID);

            $class = 'notice notice-error is-dismissible';
            $message = sprintf(
                __('<strong>Duplicate Tag Name:</strong> Tag name "%s" conflicts with an existing ungrouped tag. Your tag has been saved as draft. Please:', 'wp-dynamic-tags'),
                esc_html($tag_title)
            );
            $message .= '<br>• ' . __('Assign this tag to a group, or', 'wp-dynamic-tags');
            $message .= '<br>• ' . __('Choose a different tag name', 'wp-dynamic-tags');

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        } else {
            $class = 'notice notice-warning';
            $message = sprintf(
                __('Tag name "%s" conflicts with existing ungrouped tag. Please assign to a group or choose different name.', 'wp-dynamic-tags'),
                esc_html($tag_title)
            );

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        }
    }

    /**
     * Show admin notices for existing duplicate conflicts
     */
    public function show_existing_conflict_notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();

        if (!empty($ungrouped_duplicates)) {
            $class = 'notice notice-warning is-dismissible';
            $count = 0;
            foreach ($ungrouped_duplicates as $posts) {
                $count += count($posts);
            }

            $message = sprintf(
                _n(
                    'Warning: %d dynamic tag has duplicate names without group assignment, causing shortcode conflicts.',
                    'Warning: %d dynamic tags have duplicate names without group assignments, causing shortcode conflicts.',
                    $count,
                    'wp-dynamic-tags'
                ),
                $count
            );

            $message .= ' <a href="' . admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-conflict-resolution') . '">';
            $message .= __('Resolve conflicts', 'wp-dynamic-tags') . '</a>';

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        }
    }

    /**
     * Show admin notice about menu consolidation
     */
    public function show_menu_consolidation_notice()
    {
        // Only show on Dynamic Tags related pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'dynamic_tag') === false) {
            return;
        }

        // Check if user has already dismissed this notice
        if (get_user_meta(get_current_user_id(), 'dt_menu_consolidation_dismissed', true)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $class = 'notice notice-info is-dismissible';
        $message = sprintf(
            __('📢 <strong>Menu Update:</strong> We\'ve consolidated group management into a single "Groups" menu for a better experience. All functionality is now available in one place. <a href="%s">Explore the new unified Groups interface</a>.', 'wp-dynamic-tags'),
            admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-group-manager')
        );

        printf('<div class="%1$s" data-dismissible="dt-menu-consolidation"><p>%2$s</p></div>', esc_attr($class), $message);

        // Add JavaScript to handle dismissal
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $(document).on('click', '.notice[data-dismissible="dt-menu-consolidation"] .notice-dismiss', function () {
                    $.post(ajaxurl, {
                        action: 'dt_dismiss_menu_notice',
                        nonce: '<?php echo wp_create_nonce('dt_dismiss_notice'); ?>'
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Handle dismissal of menu consolidation notice
     */
    public function dismiss_menu_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'dt_dismiss_notice')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        update_user_meta(get_current_user_id(), 'dt_menu_consolidation_dismissed', true);
        wp_die(); // This is required to terminate immediately and return a proper response
    }

    /**
     * Show admin notices for bulk and row actions
     */
    public function show_action_notices()
    {
        global $pagenow;

        if ($pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== $this->post_type) {
            return;
        }

        // Show duplication success notice
        if (isset($_GET['duplicated'])) {
            $count = intval($_GET['duplicated']);
            if ($count > 0) {
                $class = 'notice notice-success is-dismissible';
                $message = sprintf(
                    _n(
                        '%d tag duplicated successfully.',
                        '%d tags duplicated successfully.',
                        $count,
                        'wp-dynamic-tags'
                    ),
                    $count
                );
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
            }
        }
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
     * AJAX handler for testing shortcodes
     */
    public function test_shortcode_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'dt_test_shortcode')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $shortcode = sanitize_text_field($_POST['shortcode']);
        if (empty($shortcode)) {
            wp_die('No shortcode provided');
        }

        // Check if shortcode exists
        if (!shortcode_exists($shortcode)) {
            echo '<strong style="color: #d63638;">Error:</strong> Shortcode [' . esc_html($shortcode) . '] is not registered.<br>';
            echo '<em>Make sure a tag with the key "' . esc_html($shortcode) . '" exists and is published.</em>';
            wp_die();
        }

        // Test the shortcode
        $result = do_shortcode('[' . $shortcode . ']');

        // Check if the result is the same as the input (shortcode wasn't processed)
        if ($result === '[' . $shortcode . ']') {
            echo '<strong style="color: #d63638;">Warning:</strong> Shortcode returned unchanged.<br>';
            echo '<em>The shortcode exists but may not be working correctly.</em>';
        } else {
            // Return the actual result
            echo $result ?: '<em style="color: #d63638;">Empty result (the tag value may be empty)</em>';
        }

        wp_die();
    }

    /**
     * AJAX handler for real-time duplicate checking
     */
    public function ajax_check_duplicate()
    {
        check_ajax_referer('dt_check_duplicate', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-dynamic-tags'));
        }

        $tag_key = sanitize_text_field($_POST['tag_key']);
        $post_id = intval($_POST['post_id']);

        if (empty($tag_key)) {
            wp_send_json_success(array(
                'is_duplicate' => false,
                'message' => __('Enter a tag name', 'wp-dynamic-tags')
            ));
        }

        // Check if this tag name exists
        $args = array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $all_tags = get_posts($args);
        $is_duplicate = false;
        $duplicate_count = 0;

        foreach ($all_tags as $tag_id) {
            if ($tag_id == $post_id) {
                continue; // Skip the current post
            }

            $title = get_the_title($tag_id);
            $existing_key = $this->sanitize_tag_key($title);

            if ($existing_key === $tag_key) {
                $is_duplicate = true;
                $duplicate_count++;
            }
        }

        if ($is_duplicate) {
            $message = sprintf(
                _n(
                    'This tag name is used by %d other tag. Assign to a group to avoid conflicts.',
                    'This tag name is used by %d other tags. Assign to a group to avoid conflicts.',
                    $duplicate_count,
                    'wp-dynamic-tags'
                ),
                $duplicate_count
            );

            wp_send_json_success(array(
                'is_duplicate' => true,
                'message' => $message
            ));
        } else {
            wp_send_json_success(array(
                'is_duplicate' => false,
                'message' => __('This tag name is available!', 'wp-dynamic-tags')
            ));
        }
    }

    /**
     * Admin menu setup
     */
    public function admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Quick Create & Help', 'wp-dynamic-tags'),
            __('Quick Create & Help', 'wp-dynamic-tags'),
            'manage_options',
            'dt-quick-create',
            array($this, 'quick_create_page')
        );

        // Add conflict resolution page
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Conflict Resolution', 'wp-dynamic-tags'),
            __('Conflict Resolution', 'wp-dynamic-tags'),
            'manage_options',
            'dt-conflict-resolution',
            array($this, 'conflict_resolution_page')
        );

        // Add debug page (only if WP_DEBUG is enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'edit.php?post_type=' . $this->post_type,
                __('Debug Info', 'wp-dynamic-tags'),
                __('Debug Info', 'wp-dynamic-tags'),
                'manage_options',
                'dt-debug',
                array($this, 'debug_page')
            );
        }

        // Hook admin notices for validation errors
        add_action('admin_notices', array($this, 'show_validation_errors'));
    }

    /**
     * Custom admin columns
     */
    public function custom_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Tag Key', 'wp-dynamic-tags');
        $new_columns['tag_value'] = __('Tag Value', 'wp-dynamic-tags');
        $new_columns['conflict_status'] = __('Status', 'wp-dynamic-tags');
        $new_columns['shortcode'] = __('Shortcode', 'wp-dynamic-tags');
        $new_columns['usage_count'] = __('Usage', 'wp-dynamic-tags');
        $new_columns['last_used'] = __('Last Used', 'wp-dynamic-tags');
        $new_columns['priority'] = __('Priority', 'wp-dynamic-tags');
        $new_columns['date'] = $columns['date'];

        return $new_columns;
    }

    /**
     * Custom column content
     */
    public function custom_column_content($column, $post_id)
    {
        switch ($column) {
            case 'tag_value':
                $content = get_post_field('post_content', $post_id);
                echo wp_trim_words($content, 10, '...');
                break;

            case 'conflict_status':
                // Performance optimization: Use try-catch and caching
                try {
                    $title = get_the_title($post_id);
                    if (empty($title)) {
                        echo '<span style="color: #999;">—</span>';
                        break;
                    }

                    $tag_key = $this->sanitize_tag_key($title);
                    $groups = wp_get_post_terms($post_id, 'tag_groups');
                    $is_available = $this->is_tag_name_available($title, $post_id);
                    $has_conflicts = !$is_available && (empty($groups) || is_wp_error($groups));

                    if ($has_conflicts) {
                        echo '<div style="display: flex; align-items: center; gap: 4px;">';
                        echo '<span style="color: #d63638; font-weight: bold;">⚠️</span>';
                        echo '<span style="color: #721c24; font-size: 11px; font-weight: bold;">' . __('CONFLICT', 'wp-dynamic-tags') . '</span>';
                        echo '</div>';
                        echo '<div style="font-size: 10px; color: #666; margin-top: 2px;">' . __('Needs group', 'wp-dynamic-tags') . '</div>';
                    } else {
                        // Use cached duplicate tags for better performance
                        $duplicate_tags = $this->get_cached_duplicate_tags();
                        $has_duplicates = isset($duplicate_tags[$tag_key]) && count($duplicate_tags[$tag_key]) > 1;

                        if ($has_duplicates) {
                            echo '<div style="display: flex; align-items: center; gap: 4px;">';
                            echo '<span style="color: #ffc107;">👥</span>';
                            echo '<span style="color: #856404; font-size: 11px; font-weight: bold;">' . __('GROUPED', 'wp-dynamic-tags') . '</span>';
                            echo '</div>';
                            echo '<div style="font-size: 10px; color: #666; margin-top: 2px;">' . __('Has duplicates', 'wp-dynamic-tags') . '</div>';
                        } else {
                            echo '<div style="display: flex; align-items: center; gap: 4px;">';
                            echo '<span style="color: #00a32a;">✅</span>';
                            echo '<span style="color: #1e4620; font-size: 11px; font-weight: bold;">' . __('OK', 'wp-dynamic-tags') . '</span>';
                            echo '</div>';
                            echo '<div style="font-size: 10px; color: #666; margin-top: 2px;">' . __('No conflicts', 'wp-dynamic-tags') . '</div>';
                        }
                    }
                } catch (Exception $e) {
                    echo '<span style="color: #999;" title="Error: ' . esc_attr($e->getMessage()) . '">Error</span>';
                }
                break;

            case 'shortcode':
                // Performance optimization: Add error handling and caching
                try {
                    $title = get_the_title($post_id);
                    if (empty($title)) {
                        echo '<span style="color: #999;">—</span>';
                        break;
                    }

                    // Get the single shortcode for this tag
                    $primary_shortcode = $this->get_tag_shortcode($post_id, $title);

                    if (!empty($primary_shortcode)) {
                        // Check for conflicts using cached data
                        $tag_key = $this->sanitize_tag_key($title);
                        $groups = wp_get_post_terms($post_id, 'tag_groups');
                        $is_available = $this->is_tag_name_available($title, $post_id);
                        $has_conflicts = !$is_available && (empty($groups) || is_wp_error($groups));

                        $output = '<div class="dt-shortcode-container">';

                        // Show conflict warning if exists
                        if ($has_conflicts) {
                            $output .= '<div class="dt-conflict-warning" style="background: #ffecec; border: 1px solid #f56565; padding: 4px 6px; border-radius: 3px; margin-bottom: 6px; font-size: 11px;">';
                            $output .= '<span style="color: #d63638; font-weight: bold;">⚠️ CONFLICT:</span> ';
                            $output .= '<span style="color: #721c24;">' . __('Duplicate name, needs group', 'wp-dynamic-tags') . '</span>';
                            $output .= ' <a href="' . admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-conflict-resolution') . '" style="color: #0073aa; text-decoration: none; font-weight: bold;">' . __('Fix', 'wp-dynamic-tags') . '</a>';
                            $output .= '</div>';
                        }

                        // Group requirement indicator using cached duplicate detection
                        $duplicate_tags = $this->get_cached_duplicate_tags();
                        $has_duplicates = isset($duplicate_tags[$tag_key]) && count($duplicate_tags[$tag_key]) > 1;
                        if ($has_duplicates && !$has_conflicts) {
                            $output .= '<div class="dt-group-requirement" style="background: #fff3cd; border: 1px solid #ffc107; padding: 3px 5px; border-radius: 3px; margin-bottom: 4px; font-size: 10px;">';
                            $output .= '<span style="color: #856404;">👥 Group required for duplicates</span>';
                            $output .= '</div>';
                        }

                        // Display the single shortcode
                        $output .= '<div class="dt-primary-shortcode">';

                        // Apply different styling based on conflict status
                        $shortcode_class = $has_conflicts ? 'dt-shortcode-conflict' : 'dt-shortcode-primary';
                        $shortcode_style = $has_conflicts ? 'background: #ffecec; border: 1px solid #f56565;' : '';

                        $output .= '<code class="' . $shortcode_class . '" data-shortcode="[' . esc_attr($primary_shortcode) . ']" style="' . $shortcode_style . '">[' . esc_html($primary_shortcode) . ']</code>';

                        if (!$has_conflicts) {
                            $output .= '<button type="button" class="dt-copy-btn" title="' . __('Copy shortcode', 'wp-dynamic-tags') . '" onclick="copyShortcode(this)">📋</button>';
                        } else {
                            $output .= '<span class="dt-disabled-indicator" style="color: #999; font-size: 10px; margin-left: 4px;" title="' . __('Shortcode disabled due to conflict', 'wp-dynamic-tags') . '">🚫</span>';
                        }
                        $output .= '</div>';

                        // Note: Now showing only ONE shortcode per tag (group_tag format)

                        // Show group badges if assigned
                        if (!empty($groups) && !is_wp_error($groups)) {
                            $output .= '<div class="dt-group-badges" style="margin-top: 4px;">';
                            foreach ($groups as $group) {
                                $color = get_term_meta($group->term_id, 'color', true) ?: '#2271b1';
                                $output .= '<span class="dt-mini-group-badge" style="background-color: ' . esc_attr($color) . '; color: white; padding: 1px 4px; border-radius: 8px; font-size: 9px; margin-right: 2px;">' . esc_html($group->name) . '</span>';
                            }
                            $output .= '</div>';
                        }

                        $output .= '</div>';
                        echo $output;
                    } else {
                        echo '<span style="color: #999;">No shortcode</span>';
                    }
                } catch (Exception $e) {
                    echo '<span style="color: #999;" title="Error: ' . esc_attr($e->getMessage()) . '">Error</span>';
                }
                break;

            case 'usage_count':
                $usage_count = get_post_meta($post_id, '_dt_usage_count', true);
                if ($usage_count) {
                    echo '<strong>' . number_format($usage_count) . '</strong>';
                } else {
                    echo '<span style="color: #999;">0</span>';
                }
                break;

            case 'last_used':
                $last_used = get_post_meta($post_id, '_dt_last_used', true);
                if ($last_used) {
                    $timestamp = strtotime($last_used);
                    $time_diff = human_time_diff($timestamp, current_time('timestamp'));
                    echo '<span title="' . esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)) . '">';
                    echo esc_html($time_diff) . ' ago';
                    echo '</span>';
                } else {
                    echo '<span style="color: #999;">Never</span>';
                }
                break;

            case 'priority':
                $priority = get_post_meta($post_id, '_dt_priority', true);
                if ($priority !== '' && $priority !== false) {
                    $priority_val = intval($priority);
                    $color = $priority_val >= 7 ? '#00a32a' : ($priority_val >= 4 ? '#996800' : '#999');
                    echo '<span style="color: ' . $color . '; font-weight: bold;">' . esc_html($priority_val) . '</span>';
                } else {
                    echo '<span style="color: #999;">0</span>';
                }
                break;
        }
    }

    /**
     * Make columns sortable
     */
    public function sortable_columns($columns)
    {
        $columns['tag_value'] = 'post_content';
        $columns['usage_count'] = 'usage_count';
        $columns['last_used'] = 'last_used';
        $columns['priority'] = 'priority';
        return $columns;
    }

    /**
     * Register custom bulk actions
     */
    public function register_bulk_actions($bulk_actions)
    {
        $bulk_actions['export_json'] = __('Export as JSON', 'wp-dynamic-tags');
        $bulk_actions['export_csv'] = __('Export as CSV', 'wp-dynamic-tags');
        $bulk_actions['assign_group'] = __('Assign to Group', 'wp-dynamic-tags');
        $bulk_actions['duplicate_tags'] = __('Duplicate Tags', 'wp-dynamic-tags');
        return $bulk_actions;
    }

    /**
     * Handle custom bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids)
    {
        if (empty($post_ids)) {
            return $redirect_to;
        }

        switch ($action) {
            case 'export_json':
                $this->bulk_export_json($post_ids);
                exit; // File download, don't redirect
                break;

            case 'export_csv':
                $this->bulk_export_csv($post_ids);
                exit; // File download, don't redirect
                break;

            case 'assign_group':
                // Redirect to bulk manager with pre-selected tags
                $redirect_to = admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-bulk-manager&action=assign_group&ids=' . implode(',', $post_ids));
                break;

            case 'duplicate_tags':
                $count = 0;
                foreach ($post_ids as $post_id) {
                    $original_post = get_post($post_id);
                    if ($original_post) {
                        $new_post = array(
                            'post_title' => $original_post->post_title . ' (Copy)',
                            'post_content' => $original_post->post_content,
                            'post_type' => $this->post_type,
                            'post_status' => 'publish'
                        );
                        $new_id = wp_insert_post($new_post);
                        if ($new_id) {
                            // Copy meta and groups
                            $priority = get_post_meta($post_id, '_dt_priority', true);
                            if ($priority) {
                                update_post_meta($new_id, '_dt_priority', $priority);
                            }
                            $groups = wp_get_post_terms($post_id, 'tag_groups', array('fields' => 'ids'));
                            if (!is_wp_error($groups) && !empty($groups)) {
                                wp_set_post_terms($new_id, $groups, 'tag_groups');
                            }
                            $count++;
                        }
                    }
                }
                $redirect_to = add_query_arg('duplicated', $count, $redirect_to);
                break;
        }

        return $redirect_to;
    }

    /**
     * Add custom row actions
     */
    public function add_row_actions($actions, $post)
    {
        if ($post->post_type !== $this->post_type) {
            return $actions;
        }

        $post_id = $post->ID;

        // Add Duplicate action
        $duplicate_url = wp_nonce_url(
            admin_url('admin.php?action=dt_duplicate_tag&post_id=' . $post_id),
            'dt_duplicate_tag_' . $post_id
        );
        $actions['duplicate'] = '<a href="' . esc_url($duplicate_url) . '">' . __('Duplicate', 'wp-dynamic-tags') . '</a>';

        // Add Export action
        $export_url = wp_nonce_url(
            admin_url('admin.php?action=dt_export_single&post_id=' . $post_id),
            'dt_export_single_' . $post_id
        );
        $actions['export'] = '<a href="' . esc_url($export_url) . '">' . __('Export', 'wp-dynamic-tags') . '</a>';

        return $actions;
    }

    /**
     * Bulk export tags as JSON
     */
    private function bulk_export_json($post_ids)
    {
        $tags = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $groups = wp_get_post_terms($post_id, 'tag_groups');
                $group_names = array();
                if (!is_wp_error($groups)) {
                    foreach ($groups as $group) {
                        $group_names[] = $group->name;
                    }
                }

                $tags[] = array(
                    'tag_key' => $post->post_title,
                    'tag_value' => $post->post_content,
                    'groups' => implode(';', $group_names),
                    'priority' => get_post_meta($post_id, '_dt_priority', true) ?: 0,
                    'usage_count' => get_post_meta($post_id, '_dt_usage_count', true) ?: 0
                );
            }
        }

        $data = array(
            'version' => '2.0',
            'export_date' => current_time('mysql'),
            'tags' => $tags
        );

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="dynamic-tags-bulk-export-' . date('Y-m-d-H-i-s') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Bulk export tags as CSV
     */
    private function bulk_export_csv($post_ids)
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="dynamic-tags-bulk-export-' . date('Y-m-d-H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('tag_key', 'tag_value', 'groups', 'priority', 'usage_count'));

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $groups = wp_get_post_terms($post_id, 'tag_groups');
                $group_names = array();
                if (!is_wp_error($groups)) {
                    foreach ($groups as $group) {
                        $group_names[] = $group->name;
                    }
                }

                fputcsv($output, array(
                    $post->post_title,
                    $post->post_content,
                    implode(';', $group_names),
                    get_post_meta($post_id, '_dt_priority', true) ?: 0,
                    get_post_meta($post_id, '_dt_usage_count', true) ?: 0
                ));
            }
        }

        fclose($output);
    }

    /**
     * Handle duplicate tag action from row actions
     */
    public function handle_duplicate_tag_action()
    {
        if (!isset($_GET['post_id'])) {
            wp_die(__('No tag specified for duplication.', 'wp-dynamic-tags'));
        }

        $post_id = intval($_GET['post_id']);
        check_admin_referer('dt_duplicate_tag_' . $post_id);

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to duplicate tags.', 'wp-dynamic-tags'));
        }

        $original_post = get_post($post_id);
        if (!$original_post || $original_post->post_type !== $this->post_type) {
            wp_die(__('Invalid tag.', 'wp-dynamic-tags'));
        }

        // Create duplicate
        $new_post = array(
            'post_title' => $original_post->post_title . ' (Copy)',
            'post_content' => $original_post->post_content,
            'post_type' => $this->post_type,
            'post_status' => 'publish'
        );

        $new_id = wp_insert_post($new_post);

        if ($new_id) {
            // Copy meta
            $priority = get_post_meta($post_id, '_dt_priority', true);
            if ($priority) {
                update_post_meta($new_id, '_dt_priority', $priority);
            }

            // Copy groups
            $groups = wp_get_post_terms($post_id, 'tag_groups', array('fields' => 'ids'));
            if (!is_wp_error($groups) && !empty($groups)) {
                wp_set_post_terms($new_id, $groups, 'tag_groups');
            }

            // Redirect back to list with success message
            wp_redirect(add_query_arg(
                array('post_type' => $this->post_type, 'duplicated' => 1),
                admin_url('edit.php')
            ));
            exit;
        } else {
            wp_die(__('Failed to duplicate tag.', 'wp-dynamic-tags'));
        }
    }

    /**
     * Handle export single tag action from row actions
     */
    public function handle_export_single_action()
    {
        if (!isset($_GET['post_id'])) {
            wp_die(__('No tag specified for export.', 'wp-dynamic-tags'));
        }

        $post_id = intval($_GET['post_id']);
        check_admin_referer('dt_export_single_' . $post_id);

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export tags.', 'wp-dynamic-tags'));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== $this->post_type) {
            wp_die(__('Invalid tag.', 'wp-dynamic-tags'));
        }

        // Get groups
        $groups = wp_get_post_terms($post_id, 'tag_groups');
        $group_names = array();
        if (!is_wp_error($groups)) {
            foreach ($groups as $group) {
                $group_names[] = $group->name;
            }
        }

        // Create export data
        $data = array(
            'version' => '2.0',
            'export_date' => current_time('mysql'),
            'tags' => array(
                array(
                    'tag_key' => $post->post_title,
                    'tag_value' => $post->post_content,
                    'groups' => implode(';', $group_names),
                    'priority' => get_post_meta($post_id, '_dt_priority', true) ?: 0,
                    'usage_count' => get_post_meta($post_id, '_dt_usage_count', true) ?: 0
                )
            )
        );

        // Export as JSON
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="dynamic-tag-' . sanitize_file_name($post->post_title) . '-' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
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
     * Add custom meta boxes to edit screen
     */
    public function add_editor_meta_boxes()
    {
        // Shortcode Preview Box
        add_meta_box(
            'dt-shortcode-preview',
            __('Shortcode Preview', 'wp-dynamic-tags'),
            array($this, 'render_shortcode_preview_box'),
            $this->post_type,
            'side',
            'high'
        );

        // Placeholder Helper Box
        add_meta_box(
            'dt-placeholder-helper',
            __('Available Placeholders', 'wp-dynamic-tags'),
            array($this, 'render_placeholder_helper_box'),
            $this->post_type,
            'normal',
            'default'
        );
    }

    /**
     * Render shortcode preview meta box
     */
    public function render_shortcode_preview_box($post)
    {
        $post_title = $post->post_title;
        $tag_key = $this->sanitize_tag_key($post_title);

        echo '<div id="dt-shortcode-preview-container">';

        // Preview area
        echo '<div style="margin-bottom: 15px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: bold;">' . __('Your Shortcode:', 'wp-dynamic-tags') . '</label>';
        echo '<div id="dt-generated-shortcode" style="background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; word-break: break-all;">';
        if (!empty($tag_key)) {
            echo '<code>[' . esc_html($tag_key) . ']</code>';
        } else {
            echo '<span style="color: #999;">' . __('Enter a tag name to generate shortcode', 'wp-dynamic-tags') . '</span>';
        }
        echo '</div>';
        echo '</div>';

        // Copy button
        echo '<button type="button" id="dt-copy-shortcode-btn" class="button button-primary" style="width: 100%;" ' . (empty($tag_key) ? 'disabled' : '') . '>';
        echo '📋 ' . __('Copy Shortcode', 'wp-dynamic-tags');
        echo '</button>';

        // Duplicate check
        echo '<div id="dt-duplicate-check" style="margin-top: 10px; padding: 8px; border-radius: 4px; display: none;"></div>';

        echo '</div>';

        // JavaScript for real-time preview
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                var $title = $('#title');
                var $shortcodeDisplay = $('#dt-generated-shortcode');
                var $copyBtn = $('#dt-copy-shortcode-btn');
                var $duplicateCheck = $('#dt-duplicate-check');
                var checkTimeout;

                function updateShortcodePreview() {
                    var title = $title.val().trim();

                    if (!title) {
                        $shortcodeDisplay.html('<span style="color: #999;"><?php _e('Enter a tag name to generate shortcode', 'wp-dynamic-tags'); ?></span>');
                        $copyBtn.prop('disabled', true);
                        $duplicateCheck.hide();
                        return;
                    }

                    // Sanitize tag key (client-side approximation)
                    var tagKey = title.toLowerCase()
                        .replace(/[^a-z0-9_\-]/g, '_')
                        .replace(/_{2,}/g, '_')
                        .replace(/^_|_$/g, '');

                    $shortcodeDisplay.html('<code>[' + tagKey + ']</code>');
                    $copyBtn.prop('disabled', false);

                    // Check for duplicates
                    clearTimeout(checkTimeout);
                    checkTimeout = setTimeout(function () {
                        checkDuplicate(tagKey);
                    }, 500);
                }

                function checkDuplicate(tagKey) {
                    $.post(ajaxurl, {
                        action: 'dt_check_duplicate',
                        tag_key: tagKey,
                        post_id: <?php echo $post->ID; ?>,
                        nonce: '<?php echo wp_create_nonce('dt_check_duplicate'); ?>'
                    }, function (response) {
                        if (response.success) {
                            if (response.data.is_duplicate) {
                                $duplicateCheck.html('<span style="color: #d63638;">⚠️ ' + response.data.message + '</span>').show();
                            } else {
                                $duplicateCheck.html('<span style="color: #00a32a;">✅ ' + response.data.message + '</span>').show();
                            }
                        }
                    });
                }

                $title.on('input', updateShortcodePreview);

                $copyBtn.on('click', function () {
                    var shortcode = $shortcodeDisplay.find('code').text();
                    navigator.clipboard.writeText(shortcode).then(function () {
                        var originalText = $copyBtn.html();
                        $copyBtn.html('✅ <?php _e('Copied!', 'wp-dynamic-tags'); ?>');
                        setTimeout(function () {
                            $copyBtn.html(originalText);
                        }, 2000);
                    });
                });

                // Initial update
                updateShortcodePreview();
            });
        </script>
        <?php
    }

    /**
     * Render placeholder helper meta box
     */
    public function render_placeholder_helper_box($post)
    {
        // Enhanced validation: check if placeholders object exists and has required method
        if (!$this->placeholders || !is_object($this->placeholders) || !method_exists($this->placeholders, 'get_all_placeholders')) {
            echo '<div class="notice notice-warning inline">';
            echo '<p>' . __('Placeholder system not initialized. The dynamic placeholders feature requires the WP_Dynamic_Tags_Placeholders class.', 'wp-dynamic-tags') . '</p>';
            echo '</div>';

            // Debug information in WP_DEBUG mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Placeholders not initialized - Class: ' . (class_exists('WP_Dynamic_Tags_Placeholders') ? 'EXISTS' : 'NOT FOUND') . ', Object: ' . (is_object($this->placeholders) ? get_class($this->placeholders) : 'NULL'));
            }
            return;
        }

        $placeholders = $this->placeholders->get_all_placeholders();

        echo '<p class="description">' . __('Click any placeholder to copy it to clipboard. Use these in your tag value to insert dynamic content.', 'wp-dynamic-tags') . '</p>';

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; margin-top: 15px;">';

        // Group placeholders by category
        $categories = array(
            'user' => __('User Data', 'wp-dynamic-tags'),
            'date' => __('Date & Time', 'wp-dynamic-tags'),
            'site' => __('Site Information', 'wp-dynamic-tags'),
            'post' => __('Post Context', 'wp-dynamic-tags'),
            'taxonomy' => __('Taxonomy / Terms', 'wp-dynamic-tags'),
            'author' => __('Post Author', 'wp-dynamic-tags'),
            'url' => __('URL / Request', 'wp-dynamic-tags'),
            'chatbot' => __('Chatbot Event', 'wp-dynamic-tags'),
        );

        foreach ($categories as $cat_key => $cat_name) {
            echo '<div style="border: 1px solid #ddd; padding: 12px; border-radius: 4px;">';
            echo '<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #2271b1;">' . esc_html($cat_name) . '</h4>';
            echo '<div style="display: flex; flex-direction: column; gap: 4px;">';

            foreach ($placeholders as $key => $value) {
                // Simple categorization based on prefix
                if (
                    ($cat_key === 'user' && strpos($key, 'user_') === 0) ||
                    ($cat_key === 'date' && (strpos($key, 'current_') === 0 || strpos($key, 'date') !== false)) ||
                    ($cat_key === 'site' && (strpos($key, 'site_') === 0 || strpos($key, 'admin_') === 0 || strpos($key, 'wp_') === 0 || strpos($key, 'theme_') === 0 || strpos($key, 'php_') === 0)) ||
                    ($cat_key === 'post' && strpos($key, 'post_') === 0) ||
                    ($cat_key === 'taxonomy' && (strpos($key, 'term_') === 0 || strpos($key, 'tag_') === 0)) ||
                    ($cat_key === 'author' && strpos($key, 'author_') === 0) ||
                    ($cat_key === 'url' && strpos($key, 'url_') === 0) ||
                    ($cat_key === 'chatbot' && (strpos($key, 'event_') === 0 || strpos($key, 'chatbot_') === 0))
                ) {

                    echo '<button type="button" class="button button-small dt-insert-placeholder" data-placeholder="{' . esc_attr($key) . '}" style="text-align: left; padding: 4px 8px; font-size: 11px;">';
                    echo '<code>{' . esc_html($key) . '}</code>';
                    echo '</button>';
                }
            }

            echo '</div>';
            echo '</div>';
        }

        // Parameterized placeholders: no fixed key to list as a chip, so offer a key input instead.
        echo '<div style="border: 1px solid #ddd; padding: 12px; border-radius: 4px;">';
        echo '<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #2271b1;">' . esc_html__('Custom / Meta Fields', 'wp-dynamic-tags') . '</h4>';
        echo '<div style="display: flex; flex-direction: column; gap: 6px;">';
        echo '<div style="display: flex; gap: 4px;">';
        echo '<input type="text" id="dt-meta-key-input" placeholder="' . esc_attr__('meta key', 'wp-dynamic-tags') . '" style="flex: 1; font-size: 11px; padding: 2px 4px;">';
        echo '<button type="button" class="button button-small" id="dt-insert-meta">' . esc_html__('Insert {meta:...}', 'wp-dynamic-tags') . '</button>';
        echo '</div>';

        if (function_exists('get_field')) {
            echo '<div style="display: flex; gap: 4px;">';
            echo '<input type="text" id="dt-acf-key-input" placeholder="' . esc_attr__('ACF field name', 'wp-dynamic-tags') . '" style="flex: 1; font-size: 11px; padding: 2px 4px;">';
            echo '<button type="button" class="button button-small" id="dt-insert-acf">' . esc_html__('Insert {acf:...}', 'wp-dynamic-tags') . '</button>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        echo '</div>';

        // JavaScript for inserting placeholders
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                function insertAtCursor(el, text) {
                    var start = el.selectionStart;
                    var end = el.selectionEnd;
                    var val = el.value;
                    el.value = val.substring(0, start) + text + val.substring(end);
                    // Move cursor to after the inserted text
                    el.selectionStart = el.selectionEnd = start + text.length;
                    // Trigger change event so frameworks notice the update
                    $(el).trigger('change');
                }

                // Insert into whichever editor/field currently has focus, or the main content editor.
                function dtInsertPlaceholderText(text) {
                    var $focused = $(document.activeElement);
                    if ($focused.is('input[type="text"], textarea') && !$focused.is('#content')) {
                        insertAtCursor($focused[0], text);
                        return;
                    }

                    if (typeof tinymce !== 'undefined') {
                        var editor = tinymce.get('content');
                        if (editor && !editor.isHidden()) {
                            editor.execCommand('mceInsertContent', false, text);
                            return;
                        }
                    }

                    var textArea = document.getElementById('content');
                    if (textArea) {
                        insertAtCursor(textArea, text);
                        textArea.focus();
                    }
                }

                $('.dt-insert-placeholder').on('click', function () {
                    var placeholder = $(this).data('placeholder');
                    var $btn = $(this);

                    // ── 1. Copy to clipboard ───────────────────────────────────
                    // navigator.clipboard requires HTTPS. Provide an execCommand fallback.
                    function showCopied() {
                        var originalHTML = $btn.html();
                        $btn.html('✅ <?php _e('Copied!', 'wp-dynamic-tags'); ?>');
                        setTimeout(function () { $btn.html(originalHTML); }, 1500);
                    }

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(placeholder).then(showCopied).catch(function () {
                            fallbackCopy(placeholder, showCopied);
                        });
                    } else {
                        fallbackCopy(placeholder, showCopied);
                    }

                    function fallbackCopy(text, cb) {
                        var $ta = $('<textarea>').val(text).css({ position: 'fixed', top: 0, left: 0, opacity: 0 });
                        $('body').append($ta);
                        $ta[0].select();
                        try { document.execCommand('copy'); } catch (e) { }
                        $ta.remove();
                        if (cb) cb();
                    }

                    // ── 2. Insert into editor / textarea ──────────────────────
                    dtInsertPlaceholderText(placeholder);
                });

                $('#dt-insert-meta').on('click', function () {
                    var key = $('#dt-meta-key-input').val();
                    if (!key) return;
                    dtInsertPlaceholderText('{meta:' + key + '}');
                });

                $('#dt-insert-acf').on('click', function () {
                    var key = $('#dt-acf-key-input').val();
                    if (!key) return;
                    dtInsertPlaceholderText('{acf:' + key + '}');
                });
            });
        </script>
        <?php
    }

    /**
     * Add filter dropdowns to admin list
     */
    public function add_admin_filters($post_type)
    {
        if ($post_type !== $this->post_type) {
            return;
        }

        // Group filter dropdown
        $this->render_group_filter();

        // Conflict status filter dropdown
        $this->render_conflict_status_filter();

        // Usage filter dropdown
        $this->render_usage_filter();

        // Date range filter
        $this->render_date_filter();

        // Content length filter
        $this->render_content_length_filter();
    }

    /**
     * Render group filter dropdown (with error handling)
     */
    private function render_group_filter()
    {
        try {
            // Check if taxonomy exists
            if (!taxonomy_exists('tag_groups')) {
                echo '<select name="filter_group" id="filter_group" disabled>';
                echo '<option value="">' . __('Groups Not Available', 'wp-dynamic-tags') . '</option>';
                echo '</select>';
                return;
            }

            $groups = get_terms(array(
                'taxonomy' => 'tag_groups',
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ));

            if (!is_wp_error($groups) && !empty($groups)) {
                $selected = isset($_GET['filter_group']) ? sanitize_text_field($_GET['filter_group']) : '';

                echo '<select name="filter_group" id="filter_group">';
                echo '<option value="">' . __('All Groups', 'wp-dynamic-tags') . '</option>';

                foreach ($groups as $group) {
                    // Ensure group object is valid
                    if (!is_object($group) || !isset($group->term_id) || !isset($group->slug) || !isset($group->name)) {
                        continue;
                    }

                    // Get count with error handling
                    $count = $this->get_posts_count_by_group($group->term_id);
                    printf(
                        '<option value="%s" %s>%s (%d)</option>',
                        esc_attr($group->slug),
                        selected($selected, $group->slug, false),
                        esc_html($group->name),
                        $count
                    );
                }

                echo '<option value="ungrouped" ' . selected($selected, 'ungrouped', false) . '>' . __('Ungrouped', 'wp-dynamic-tags') . '</option>';
                echo '</select>';
            } else {
                // Fallback when no groups exist
                echo '<select name="filter_group" id="filter_group">';
                echo '<option value="">' . __('All Groups', 'wp-dynamic-tags') . '</option>';
                echo '<option value="ungrouped">' . __('Ungrouped', 'wp-dynamic-tags') . '</option>';
                echo '</select>';
            }
        } catch (Exception $e) {
            // Error fallback
            echo '<select name="filter_group" id="filter_group" disabled>';
            echo '<option value="">' . __('Error Loading Groups', 'wp-dynamic-tags') . '</option>';
            echo '</select>';
        }
    }

    /**
     * Render conflict status filter dropdown
     */
    private function render_conflict_status_filter()
    {
        $selected = isset($_GET['filter_conflict']) ? sanitize_text_field($_GET['filter_conflict']) : '';

        echo '<select name="filter_conflict" id="filter_conflict">';
        echo '<option value="">' . __('All Status', 'wp-dynamic-tags') . '</option>';
        echo '<option value="conflict" ' . selected($selected, 'conflict', false) . '>' . __('⚠️ Conflicts', 'wp-dynamic-tags') . '</option>';
        echo '<option value="grouped" ' . selected($selected, 'grouped', false) . '>' . __('👥 Grouped Duplicates', 'wp-dynamic-tags') . '</option>';
        echo '<option value="ok" ' . selected($selected, 'ok', false) . '>' . __('✅ No Issues', 'wp-dynamic-tags') . '</option>';
        echo '</select>';
    }

    /**
     * Render usage filter dropdown
     */
    private function render_usage_filter()
    {
        $selected = isset($_GET['filter_usage']) ? sanitize_text_field($_GET['filter_usage']) : '';

        echo '<select name="filter_usage" id="filter_usage">';
        echo '<option value="">' . __('All Usage Levels', 'wp-dynamic-tags') . '</option>';
        echo '<option value="high" ' . selected($selected, 'high', false) . '>' . __('High Usage (10+)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="medium" ' . selected($selected, 'medium', false) . '>' . __('Medium Usage (5-9)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="low" ' . selected($selected, 'low', false) . '>' . __('Low Usage (1-4)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="unused" ' . selected($selected, 'unused', false) . '>' . __('Never Used', 'wp-dynamic-tags') . '</option>';
        echo '</select>';
    }

    /**
     * Render date filter dropdown
     */
    private function render_date_filter()
    {
        $selected = isset($_GET['filter_date']) ? sanitize_text_field($_GET['filter_date']) : '';

        echo '<select name="filter_date" id="filter_date">';
        echo '<option value="">' . __('All Dates', 'wp-dynamic-tags') . '</option>';
        echo '<option value="today" ' . selected($selected, 'today', false) . '>' . __('Created Today', 'wp-dynamic-tags') . '</option>';
        echo '<option value="week" ' . selected($selected, 'week', false) . '>' . __('This Week', 'wp-dynamic-tags') . '</option>';
        echo '<option value="month" ' . selected($selected, 'month', false) . '>' . __('This Month', 'wp-dynamic-tags') . '</option>';
        echo '<option value="quarter" ' . selected($selected, 'quarter', false) . '>' . __('Last 3 Months', 'wp-dynamic-tags') . '</option>';
        echo '</select>';
    }

    /**
     * Render content length filter dropdown
     */
    private function render_content_length_filter()
    {
        $selected = isset($_GET['filter_length']) ? sanitize_text_field($_GET['filter_length']) : '';

        echo '<select name="filter_length" id="filter_length">';
        echo '<option value="">' . __('All Lengths', 'wp-dynamic-tags') . '</option>';
        echo '<option value="short" ' . selected($selected, 'short', false) . '>' . __('Short (< 50 chars)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="medium" ' . selected($selected, 'medium', false) . '>' . __('Medium (50-200 chars)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="long" ' . selected($selected, 'long', false) . '>' . __('Long (> 200 chars)', 'wp-dynamic-tags') . '</option>';
        echo '<option value="empty" ' . selected($selected, 'empty', false) . '>' . __('Empty Content', 'wp-dynamic-tags') . '</option>';
        echo '</select>';
    }

    /**
     * Get count of posts in a specific group (replaces non-existent wp_count_posts_by_term)
     */
    private function get_posts_count_by_group($group_id)
    {
        try {
            // Validate group ID
            if (empty($group_id) || !is_numeric($group_id)) {
                return 0;
            }

            // Check if taxonomy exists
            if (!taxonomy_exists('tag_groups')) {
                return 0;
            }

            $posts = get_posts(array(
                'post_type' => $this->post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'tag_groups',
                        'field' => 'term_id',
                        'terms' => intval($group_id)
                    )
                )
            ));

            return is_array($posts) ? count($posts) : 0;
        } catch (Exception $e) {
            // Return 0 on error to prevent breaking the admin interface
            return 0;
        }
    }

    /**
     * Filter admin query based on custom filters
     */
    public function filter_admin_query($query)
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'edit.php') {
            return $query;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== $this->post_type) {
            return $query;
        }

        // This method is for parse_query hook - we'll handle the actual filtering in modify_admin_query
        return $query;
    }

    /**
     * Modify admin query for filtering
     */
    public function modify_admin_query($query)
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query()) {
            return;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== $this->post_type) {
            return;
        }

        // Handle group filter
        if (isset($_GET['filter_group']) && !empty($_GET['filter_group'])) {
            $group_slug = sanitize_text_field($_GET['filter_group']);

            if ($group_slug === 'ungrouped') {
                // Show posts without any groups
                $query->set('tax_query', array(
                    array(
                        'taxonomy' => 'tag_groups',
                        'operator' => 'NOT EXISTS'
                    )
                ));
            } else {
                // Show posts in specific group
                $query->set('tax_query', array(
                    array(
                        'taxonomy' => 'tag_groups',
                        'field' => 'slug',
                        'terms' => $group_slug
                    )
                ));
            }
        }

        // Handle conflict status filter
        if (isset($_GET['filter_conflict']) && !empty($_GET['filter_conflict'])) {
            $conflict_filter = sanitize_text_field($_GET['filter_conflict']);

            switch ($conflict_filter) {
                case 'conflict':
                    // This would require complex meta queries - we'll handle it via posts_where filter
                    add_filter('posts_where', array($this, 'filter_posts_by_conflict_status'));
                    break;
                case 'grouped':
                    // Posts that have groups and are duplicates
                    $query->set('tax_query', array(
                        array(
                            'taxonomy' => 'tag_groups',
                            'operator' => 'EXISTS'
                        )
                    ));
                    break;
                case 'ok':
                    // Posts without conflicts - handled via posts_where
                    add_filter('posts_where', array($this, 'filter_posts_by_ok_status'));
                    break;
            }
        }

        // Handle usage filter
        if (isset($_GET['filter_usage']) && !empty($_GET['filter_usage'])) {
            $usage_filter = sanitize_text_field($_GET['filter_usage']);

            switch ($usage_filter) {
                case 'high':
                    $query->set('meta_query', array(
                        array(
                            'key' => '_dt_usage_count',
                            'value' => 10,
                            'compare' => '>='
                        )
                    ));
                    break;
                case 'medium':
                    $query->set('meta_query', array(
                        array(
                            'key' => '_dt_usage_count',
                            'value' => array(5, 9),
                            'compare' => 'BETWEEN'
                        )
                    ));
                    break;
                case 'low':
                    $query->set('meta_query', array(
                        array(
                            'key' => '_dt_usage_count',
                            'value' => array(1, 4),
                            'compare' => 'BETWEEN'
                        )
                    ));
                    break;
                case 'unused':
                    $query->set('meta_query', array(
                        'relation' => 'OR',
                        array(
                            'key' => '_dt_usage_count',
                            'value' => 0,
                            'compare' => '='
                        ),
                        array(
                            'key' => '_dt_usage_count',
                            'compare' => 'NOT EXISTS'
                        )
                    ));
                    break;
            }
        }

        // Handle sorting by custom meta fields
        $orderby = $query->get('orderby');

        if ('usage_count' === $orderby) {
            $query->set('meta_key', '_dt_usage_count');
            $query->set('orderby', 'meta_value_num');
        } elseif ('last_used' === $orderby) {
            $query->set('meta_key', '_dt_last_used');
            $query->set('orderby', 'meta_value');
        } elseif ('priority' === $orderby) {
            $query->set('meta_key', '_dt_priority');
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Filter posts by conflict status (for complex queries)
     */
    public function filter_posts_by_conflict_status($where)
    {
        // This is a simplified implementation - in a real scenario you'd need more complex SQL
        // For now, we'll let the post list show all and rely on the admin column to show status
        remove_filter('posts_where', array($this, 'filter_posts_by_conflict_status'));
        return $where;
    }

    /**
     * Filter posts by OK status (no conflicts)
     */
    public function filter_posts_by_ok_status($where)
    {
        // This is a simplified implementation
        remove_filter('posts_where', array($this, 'filter_posts_by_ok_status'));
        return $where;
    }

    /**
     * Quick create page
     */
    public function quick_create_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Quick Create & Help', 'wp-dynamic-tags'); ?></h1>

            <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Dynamic tag created successfully!', 'wp-dynamic-tags'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 600px;">
                <h2><?php _e('Quick Create Tag', 'wp-dynamic-tags'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('dt_quick_create', 'dt_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="tag_key"><?php _e('Tag Key', 'wp-dynamic-tags'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="tag_key" id="tag_key" class="regular-text" required
                                    placeholder="<?php esc_attr_e('e.g., site_title, company_name', 'wp-dynamic-tags'); ?>">
                                <p class="description">
                                    <?php _e('Use lowercase letters, numbers, underscores, and dashes only.', 'wp-dynamic-tags'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tag_value"><?php _e('Tag Value', 'wp-dynamic-tags'); ?></label>
                            </th>
                            <td>
                                <textarea name="tag_value" id="tag_value" class="large-text" rows="4" required
                                    placeholder="<?php esc_attr_e('Enter the content for this tag...', 'wp-dynamic-tags'); ?>"></textarea>
                                <p class="description">
                                    <?php _e('You can use dynamic placeholders like {current_year}, {user_name}, etc.', 'wp-dynamic-tags'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="tag_category"><?php _e('Category', 'wp-dynamic-tags'); ?></label>
                            </th>
                            <td>
                                <select name="tag_category" id="tag_category">
                                    <option value="general"><?php _e('General', 'wp-dynamic-tags'); ?></option>
                                    <option value="seo"><?php _e('SEO', 'wp-dynamic-tags'); ?></option>
                                    <option value="contact"><?php _e('Contact', 'wp-dynamic-tags'); ?></option>
                                    <option value="branding"><?php _e('Branding', 'wp-dynamic-tags'); ?></option>
                                    <option value="custom"><?php _e('Custom', 'wp-dynamic-tags'); ?></option>
                                </select>
                                <p class="description">
                                    <?php _e('Organize your tags into categories for better management.', 'wp-dynamic-tags'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="create_tag" class="button-primary"
                            value="<?php esc_attr_e('Create Tag', 'wp-dynamic-tags'); ?>">
                    </p>
                </form>
            </div>

            <div class="card" style="margin-top: 20px; background: #e7f5fe; border-left: 4px solid #2271b1;">
                <h2 style="margin-top: 0;">📥 <?php _e('Import Guide - Bulk Create Tags from CSV', 'wp-dynamic-tags'); ?></h2>
                <p class="description">
                    <?php _e('Save time by importing multiple tags at once from a CSV file.', 'wp-dynamic-tags'); ?></p>

                <h3><?php _e('Step 1: Download Sample File', 'wp-dynamic-tags'); ?></h3>
                <p><?php _e('Choose a sample file to get started:', 'wp-dynamic-tags'); ?></p>
                <p>
                    <a href="<?php echo admin_url('admin.php?action=dt_download_sample_csv'); ?>"
                        class="button button-secondary">
                        📄 <?php _e('Download Basic Sample (simple format)', 'wp-dynamic-tags'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?action=dt_download_sample_full_csv'); ?>"
                        class="button button-secondary" style="margin-left: 10px;">
                        📋 <?php _e('Download Full Sample (with metadata)', 'wp-dynamic-tags'); ?>
                    </a>
                </p>

                <h3><?php _e('Step 2: Edit the CSV File', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><?php _e('Open the downloaded file in Excel, Google Sheets, or any text editor', 'wp-dynamic-tags'); ?>
                    </li>
                    <li><?php _e('Add your tags (one per row)', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Keep the header row intact', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Save as CSV format (UTF-8 encoding recommended)', 'wp-dynamic-tags'); ?></li>
                </ul>

                <h3><?php _e('Step 3: Import Your Tags', 'wp-dynamic-tags'); ?></h3>
                <p>
                    <a href="<?php echo admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-import-export'); ?>"
                        class="button button-primary button-large">
                        ⬆️ <?php _e('Go to Import/Export Page', 'wp-dynamic-tags'); ?>
                    </a>
                </p>

                <h3><?php _e('CSV Format Guide', 'wp-dynamic-tags'); ?></h3>
                <table class="widefat" style="max-width: 600px; background: white;">
                    <thead>
                        <tr>
                            <th><?php _e('Column', 'wp-dynamic-tags'); ?></th>
                            <th><?php _e('Description', 'wp-dynamic-tags'); ?></th>
                            <th><?php _e('Example', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>tag_key</strong></td>
                            <td><?php _e('Unique tag name (lowercase, underscores)', 'wp-dynamic-tags'); ?></td>
                            <td><code>company_name</code></td>
                        </tr>
                        <tr>
                            <td><strong>tag_value</strong></td>
                            <td><?php _e('Your content (can use placeholders)', 'wp-dynamic-tags'); ?></td>
                            <td><code>Acme Corp</code></td>
                        </tr>
                        <tr>
                            <td><strong>category</strong></td>
                            <td><?php _e('Category (general, seo, contact, etc.)', 'wp-dynamic-tags'); ?></td>
                            <td><code>general</code></td>
                        </tr>
                        <tr>
                            <td><strong>groups</strong></td>
                            <td><?php _e('Semicolon-separated group names', 'wp-dynamic-tags'); ?></td>
                            <td><code>Branding;Marketing</code></td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="margin-top: 20px;"><?php _e('Tips for Successful Import', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li>✅ <?php _e('Keep tag keys unique to avoid conflicts', 'wp-dynamic-tags'); ?></li>
                    <li>✅ <?php _e('Use UTF-8 encoding when saving your CSV file', 'wp-dynamic-tags'); ?></li>
                    <li>✅ <?php _e('Test with a small file first (2-3 tags)', 'wp-dynamic-tags'); ?></li>
                    <li>✅ <?php _e('Assign groups to tags with duplicate names', 'wp-dynamic-tags'); ?></li>
                    <li>✅ <?php _e('Enclose values with commas in quotes: "value, here"', 'wp-dynamic-tags'); ?></li>
                    <li>✅ <?php _e('You can use dynamic placeholders like {current_year} in tag values', 'wp-dynamic-tags'); ?>
                    </li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('How to Use Dynamic Tags', 'wp-dynamic-tags'); ?></h2>
                <p><?php _e('After creating a tag, you can use it anywhere in WordPress with shortcodes:', 'wp-dynamic-tags'); ?>
                </p>

                <h3><?php _e('Usage Examples:', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><strong><?php _e('Direct shortcode:', 'wp-dynamic-tags'); ?></strong> <code>[your_tag_key]</code></li>
                    <li><strong><?php _e('Fallback shortcode:', 'wp-dynamic-tags'); ?></strong>
                        <code>[dt key="your_tag_key"]</code></li>
                </ul>

                <h3><?php _e('Where You Can Use Them:', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><?php _e('Elementor Text/Heading widgets', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('WordPress posts and pages', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Gutenberg blocks that support shortcodes', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Classic Editor content', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Widgets (if shortcodes are enabled)', 'wp-dynamic-tags'); ?></li>
                </ul>

                <h3><?php _e('Dynamic Placeholders (NEW!):', 'wp-dynamic-tags'); ?></h3>
                <p><?php _e('You can now use dynamic placeholders in your tag values:', 'wp-dynamic-tags'); ?></p>
                <ul>
                    <li><code>{user_display_name}</code> - <?php _e('Current user name', 'wp-dynamic-tags'); ?></li>
                    <li><code>{current_year}</code> - <?php _e('Current year', 'wp-dynamic-tags'); ?></li>
                    <li><code>{site_name}</code> - <?php _e('Site title', 'wp-dynamic-tags'); ?></li>
                    <li><code>{if:user_logged_in}Welcome back!{else}Please login{/if}</code> -
                        <?php _e('Conditional content', 'wp-dynamic-tags'); ?></li>
                </ul>

                <h3><?php _e('Tips:', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><?php _e('Tag keys are automatically sanitized (lowercase, underscores replace spaces)', 'wp-dynamic-tags'); ?>
                    </li>
                    <li><?php _e('Tags are cached for better performance', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('You can use HTML in tag values', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Dynamic placeholders are processed in real-time', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Use the Bulk Manager for editing multiple tags', 'wp-dynamic-tags'); ?></li>
                    <li><?php _e('Import/Export feature available for backup and migration', 'wp-dynamic-tags'); ?></li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Current Tags', 'wp-dynamic-tags'); ?>
                    <small>(<?php echo $this->get_dynamic_tags_count(); ?> total)</small>
                </h2>
                <?php
                // Optimized pagination for admin interface
                $page = isset($_GET['dtag_page']) ? max(1, intval($_GET['dtag_page'])) : 1;
                $per_page = 20; // Show 20 tags per page for better performance
                $offset = ($page - 1) * $per_page;

                $tags = $this->get_dynamic_tags($per_page, $offset);
                $total_tags = $this->get_dynamic_tags_count();
                $total_pages = ceil($total_tags / $per_page);

                if (!empty($tags)):
                    ?>
                    <div class="tablenav tablenav-top">
                        <div class="alignleft">
                            <?php
                            if ($total_pages > 1) {
                                echo '<div class="tablenav-pages">';
                                echo '<span class="displaying-num">' . sprintf(__('%d items'), $total_tags) . '</span>';

                                if ($page > 1) {
                                    echo '<a class="prev-page button" href="' . esc_url(add_query_arg('dtag_page', $page - 1)) . '">‹</a> ';
                                }

                                echo '<span class="paging-input">Page ' . $page . ' of ' . $total_pages . '</span>';

                                if ($page < $total_pages) {
                                    echo ' <a class="next-page button" href="' . esc_url(add_query_arg('dtag_page', $page + 1)) . '">›</a>';
                                }
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </div>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Tag Key', 'wp-dynamic-tags'); ?></th>
                                <th><?php _e('Shortcode', 'wp-dynamic-tags'); ?></th>
                                <th><?php _e('Value Preview', 'wp-dynamic-tags'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tags as $unique_key => $tag_data): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($tag_data['tag_key']); ?></strong></td>
                                    <td><code>[<?php echo esc_html($tag_data['tag_key']); ?>]</code></td>
                                    <td><?php echo wp_trim_words(wp_strip_all_tags($tag_data['content']), 8, '...'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No dynamic tags created yet. Use the form above to create your first tag!', 'wp-dynamic-tags'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Conflict Resolution page
     */
    public function conflict_resolution_page()
    {
        if (isset($_POST['resolve_conflicts'])) {
            $this->handle_conflict_resolution();
        }

        $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();
        $duplicate_conflicts = $this->check_for_duplicate_tag_names();

        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Conflict Resolution', 'wp-dynamic-tags'); ?></h1>

            <?php if (isset($_GET['resolved']) && $_GET['resolved'] === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Conflicts resolved successfully!', 'wp-dynamic-tags'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (empty($ungrouped_duplicates)): ?>
                <div class="notice notice-success">
                    <p><?php _e('Great! No duplicate tag name conflicts found.', 'wp-dynamic-tags'); ?></p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><?php _e('The following tags have duplicate names and need group assignments to prevent shortcode conflicts:', 'wp-dynamic-tags'); ?>
                    </p>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('dt_resolve_conflicts', 'dt_resolve_nonce'); ?>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php _e('Tag Name', 'wp-dynamic-tags'); ?></th>
                                <th scope="col"><?php _e('Conflicting Tags', 'wp-dynamic-tags'); ?></th>
                                <th scope="col"><?php _e('Suggested Groups', 'wp-dynamic-tags'); ?></th>
                                <th scope="col"><?php _e('Action', 'wp-dynamic-tags'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ungrouped_duplicates as $tag_key => $posts): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($tag_key); ?></strong></td>
                                    <td>
                                        <?php foreach ($posts as $post_data): ?>
                                            <div>
                                                <a href="<?php echo get_edit_post_link($post_data['post_id']); ?>">
                                                    ID: <?php echo $post_data['post_id']; ?> - <?php echo esc_html($post_data['title']); ?>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php $suggested_groups = $this->suggest_groups_for_duplicates($tag_key); ?>
                                        <?php if (!empty($suggested_groups)): ?>
                                            <ul>
                                                <?php foreach ($suggested_groups as $group): ?>
                                                    <li><?php echo esc_html($group); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <em><?php _e('Create groups manually', 'wp-dynamic-tags'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="resolve_tags[]" value="<?php echo esc_attr($tag_key); ?>">
                                        <?php _e('Auto-resolve', 'wp-dynamic-tags'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="submit">
                        <input type="submit" name="resolve_conflicts" class="button-primary"
                            value="<?php esc_attr_e('Auto-Resolve Selected Conflicts', 'wp-dynamic-tags'); ?>">
                        <a href="<?php echo admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-group-manager'); ?>"
                            class="button">
                            <?php _e('Manage Groups', 'wp-dynamic-tags'); ?>
                        </a>
                    </p>
                </form>
            <?php endif; ?>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Migration & Cleanup Tools', 'wp-dynamic-tags'); ?></h2>

                <h3><?php _e('Automatic Migration Options', 'wp-dynamic-tags'); ?></h3>
                <p><?php _e('Choose how to handle duplicate tag conflicts:', 'wp-dynamic-tags'); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('dt_bulk_migrate', 'dt_bulk_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Migration Strategy', 'wp-dynamic-tags'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="migration_strategy" value="auto_group" checked>
                                    <?php _e('Auto-create groups based on content similarity', 'wp-dynamic-tags'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="migration_strategy" value="prefix_id">
                                    <?php _e('Add post ID prefix to duplicate names', 'wp-dynamic-tags'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="migration_strategy" value="create_default">
                                    <?php _e('Assign all to "Migrated" group', 'wp-dynamic-tags'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="bulk_migrate" class="button"
                            value="<?php esc_attr_e('Run Migration', 'wp-dynamic-tags'); ?>"
                            onclick="return confirm('<?php esc_attr_e('This will modify existing tags. Continue?', 'wp-dynamic-tags'); ?>')">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle conflict resolution form submission
     */
    private function handle_conflict_resolution()
    {
        if (!wp_verify_nonce($_POST['dt_resolve_nonce'], 'dt_resolve_conflicts')) {
            wp_die(__('Security check failed', 'wp-dynamic-tags'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-dynamic-tags'));
        }

        $resolve_tags = isset($_POST['resolve_tags']) ? (array) $_POST['resolve_tags'] : array();
        $resolved_count = 0;

        foreach ($resolve_tags as $tag_key) {
            if ($this->auto_resolve_tag_conflict($tag_key)) {
                $resolved_count++;
            }
        }

        // Clear cache after resolution
        $this->clear_cache();

        // Redirect with success message
        wp_redirect(add_query_arg('resolved', '1', $_SERVER['REQUEST_URI']));
        exit;
    }

    /**
     * Auto-resolve tag conflict by creating/assigning groups
     */
    private function auto_resolve_tag_conflict($tag_key)
    {
        $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();

        if (!isset($ungrouped_duplicates[$tag_key])) {
            return false;
        }

        $posts = $ungrouped_duplicates[$tag_key];
        $group_assignments = array();

        // Create or find appropriate groups for each duplicate
        foreach ($posts as $index => $post_data) {
            $post_id = $post_data['post_id'];

            // Try to create a meaningful group name
            $group_name = $this->generate_group_name_for_post($post_id, $tag_key, $index);

            // Create or get group
            $group_term = get_term_by('name', $group_name, 'tag_groups');
            if (!$group_term) {
                $result = wp_insert_term($group_name, 'tag_groups', array(
                    'description' => sprintf(__('Auto-created group for duplicate tag "%s"', 'wp-dynamic-tags'), $tag_key)
                ));

                if (!is_wp_error($result)) {
                    $group_id = $result['term_id'];
                } else {
                    continue; // Skip if group creation failed
                }
            } else {
                $group_id = $group_term->term_id;
            }

            // Assign post to group
            wp_set_post_terms($post_id, array($group_id), 'tag_groups');
        }

        return true;
    }

    /**
     * Generate meaningful group name for a post
     */
    private function generate_group_name_for_post($post_id, $tag_key, $index)
    {
        // Try to generate meaningful group names based on content or context
        $content = get_post_field('post_content', $post_id);

        // Simple heuristics for common tag types
        if (stripos($content, 'phone') !== false || stripos($content, 'email') !== false) {
            return 'Contact';
        } elseif (stripos($content, 'address') !== false) {
            return 'Location';
        } elseif (stripos($content, 'title') !== false || stripos($content, 'name') !== false) {
            return 'Branding';
        } elseif (stripos($content, 'seo') !== false || stripos($content, 'meta') !== false) {
            return 'SEO';
        } else {
            // Fallback to numbered groups
            return sprintf(__('Group %d', 'wp-dynamic-tags'), $index + 1);
        }
    }

    /**
     * Suggest group names for duplicate resolution
     */
    private function suggest_groups_for_duplicates($tag_key)
    {
        $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();

        if (!isset($ungrouped_duplicates[$tag_key])) {
            return array();
        }

        $suggestions = array();
        $posts = $ungrouped_duplicates[$tag_key];

        foreach ($posts as $index => $post_data) {
            $suggestion = $this->generate_group_name_for_post($post_data['post_id'], $tag_key, $index);
            if (!in_array($suggestion, $suggestions)) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Handle conflict resolution actions from admin_init
     */
    public function handle_conflict_resolution_actions()
    {
        // Handle bulk migration
        if (isset($_POST['bulk_migrate']) && wp_verify_nonce($_POST['dt_bulk_nonce'], 'dt_bulk_migrate')) {
            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'wp-dynamic-tags'));
            }

            $migration_strategy = sanitize_text_field($_POST['migration_strategy']);
            $this->run_bulk_migration($migration_strategy);

            // Redirect to prevent re-submission
            wp_redirect(add_query_arg('migrated', '1', remove_query_arg('migrated')));
            exit;
        }
    }

    /**
     * Run bulk migration based on strategy
     */
    private function run_bulk_migration($strategy)
    {
        $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();

        foreach ($ungrouped_duplicates as $tag_key => $posts) {
            switch ($strategy) {
                case 'auto_group':
                    $this->auto_resolve_tag_conflict($tag_key);
                    break;

                case 'prefix_id':
                    $this->resolve_with_id_prefix($tag_key, $posts);
                    break;

                case 'create_default':
                    $this->resolve_with_default_group($tag_key, $posts);
                    break;
            }
        }

        // Clear cache after migration
        $this->clear_cache();
    }

    /**
     * Resolve conflicts by adding ID prefix to tag names
     */
    private function resolve_with_id_prefix($tag_key, $posts)
    {
        foreach ($posts as $post_data) {
            $post_id = $post_data['post_id'];
            $new_title = $post_id . '_' . $post_data['title'];

            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $new_title
            ));
        }
    }

    /**
     * Resolve conflicts by assigning to default "Migrated" group
     */
    private function resolve_with_default_group($tag_key, $posts)
    {
        // Create or get the "Migrated" group
        $group_term = get_term_by('name', 'Migrated', 'tag_groups');
        if (!$group_term) {
            $result = wp_insert_term('Migrated', 'tag_groups', array(
                'description' => __('Auto-created group for migrated duplicate tags', 'wp-dynamic-tags')
            ));

            if (is_wp_error($result)) {
                return false;
            }

            $group_id = $result['term_id'];
        } else {
            $group_id = $group_term->term_id;
        }

        // Assign all posts to the group
        foreach ($posts as $post_data) {
            wp_set_post_terms($post_data['post_id'], array($group_id), 'tag_groups');
        }

        return true;
    }

    /**
     * Handle quick create form submission
     */
    public function handle_quick_create()
    {
        if (!isset($_POST['create_tag']) || !current_user_can('manage_options')) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['dt_nonce'], 'dt_quick_create')) {
            wp_die(__('Security check failed', 'wp-dynamic-tags'));
        }

        $tag_key = sanitize_text_field($_POST['tag_key']);
        $tag_value = wp_kses_post($_POST['tag_value']);
        $tag_category = isset($_POST['tag_category']) ? sanitize_text_field($_POST['tag_category']) : 'general';

        if (empty($tag_key) || empty($tag_value)) {
            return;
        }

        // Create the post
        $post_id = wp_insert_post(array(
            'post_title' => $tag_key,
            'post_content' => $tag_value,
            'post_status' => 'publish',
            'post_type' => $this->post_type,
            'post_author' => get_current_user_id(),
        ));

        if ($post_id) {
            // Set default category if not set
            if (empty($tag_category)) {
                update_post_meta($post_id, '_dt_category', 'general');
            } else {
                update_post_meta($post_id, '_dt_category', $tag_category);
            }

            $this->clear_cache();
            wp_redirect(add_query_arg('created', '1', $_SERVER['REQUEST_URI']));
            exit;
        }
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
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
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

            // Force shortcode registration on next page load
            delete_transient($this->transient_key);
            delete_transient('dt_registration_lock');
            delete_transient('dt_table_registration_lock');
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Clear cache
        $this->clear_cache();

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
        return $this->post_table_bridge;
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

        // Load new table system classes first
        if (file_exists($includes_dir . 'class-database-manager.php')) {
            require_once $includes_dir . 'class-database-manager.php';
        }

        if (file_exists($includes_dir . 'class-migration-manager.php')) {
            require_once $includes_dir . 'class-migration-manager.php';
        }

        if (file_exists($includes_dir . 'class-table-manager.php')) {
            require_once $includes_dir . 'class-table-manager.php';
        }

        if (file_exists($includes_dir . 'class-post-table-bridge.php')) {
            require_once $includes_dir . 'class-post-table-bridge.php';
        }

        if (file_exists($includes_dir . 'class-table-shortcode-processor.php')) {
            require_once $includes_dir . 'class-table-shortcode-processor.php';
        }

        if (file_exists($includes_dir . 'class-table-import-export.php')) {
            require_once $includes_dir . 'class-table-import-export.php';
        }

        // Load existing classes
        if (file_exists($includes_dir . 'class-bulk-manager.php')) {
            require_once $includes_dir . 'class-bulk-manager.php';
        }

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

        if (file_exists($includes_dir . 'class-tag-groups.php')) {
            require_once $includes_dir . 'class-tag-groups.php';
        }

        if (file_exists($includes_dir . 'class-conflict-resolver.php')) {
            require_once $includes_dir . 'class-conflict-resolver.php';
        }

        if (file_exists(plugin_dir_path(__FILE__) . 'admin/conflict-manager.php')) {
            require_once plugin_dir_path(__FILE__) . 'admin/conflict-manager.php';
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

        if (class_exists('WP_Dynamic_Tags_Table_Manager')) {
            $this->table_manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        }

        if (class_exists('WP_Dynamic_Tags_Post_Table_Bridge')) {
            $this->post_table_bridge = WP_Dynamic_Tags_Post_Table_Bridge::get_instance();
        }

        if (class_exists('WP_Dynamic_Tags_Table_Shortcode_Processor')) {
            $this->table_shortcode_processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        }

        if (class_exists('WP_Dynamic_Tags_Table_Import_Export')) {
            $this->table_import_export = WP_Dynamic_Tags_Table_Import_Export::get_instance();
        }

        // Initialize bulk manager
        if (class_exists('WP_Dynamic_Tags_Bulk_Manager')) {
            $this->bulk_manager = new WP_Dynamic_Tags_Bulk_Manager();
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

        // Initialize conflict resolver
        if (class_exists('WP_Dynamic_Tags_Conflict_Resolver')) {
            $this->conflict_resolver = new WP_Dynamic_Tags_Conflict_Resolver();
        }

        // Initialize conflict manager admin interface
        if (is_admin() && class_exists('WP_Dynamic_Tags_Conflict_Manager_Admin')) {
            $this->conflict_manager_admin = new WP_Dynamic_Tags_Conflict_Manager_Admin();
        }

        // Initialize table manager admin interface
        if (is_admin() && class_exists('WP_Dynamic_Tags_Table_Manager_Admin')) {
            $this->table_manager_admin = new WP_Dynamic_Tags_Table_Manager_Admin();
        }

        // Ensure shortcodes are registered from existing data (important for reinstalls)
        $this->ensure_existing_shortcodes_registered();
    }

    /**
     * Ensure existing shortcodes from database are registered
     * Important for when plugin is reinstalled with existing data
     */
    private function ensure_existing_shortcodes_registered()
    {
        global $wpdb;

        // Check if we have existing data in custom table
        $table_name = $wpdb->prefix . 'dynamic_tags';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return; // Table doesn't exist, nothing to restore
        }

        // Count active tags in table
        $tag_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'active'");

        if ($tag_count > 0) {
            // We have existing data - ensure shortcodes will be registered
            // The table_shortcode_processor will handle this automatically via its 'init' hook
            // But we'll clear any locks to ensure registration happens
            delete_transient('dt_registration_lock');
            delete_transient('dt_table_registration_lock');

            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                error_log("WP Dynamic Tags: Found {$tag_count} existing tags in database, shortcodes will be auto-registered");
            }
        }
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
        $existing_tags = $this->get_dynamic_tags();
        $existing_keys = array();
        foreach ($existing_tags as $tag_data) {
            $existing_keys[] = $tag_data['tag_key'];
        }

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
            ),
            'existingKeys' => $existing_keys
        ));
    }

    /**
     * Debug information page
     */
    public function debug_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Debug Information', 'wp-dynamic-tags'); ?></h1>

            <div class="card">
                <h2><?php _e('Registered Shortcodes', 'wp-dynamic-tags'); ?></h2>
                <?php
                global $shortcode_tags;
                $dt_shortcodes = array();

                // Find all dynamic tag shortcodes
                foreach ($shortcode_tags as $tag => $callback) {
                    if (strpos($tag, '_') !== false || in_array($tag, ['dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list'])) {
                        $dt_shortcodes[$tag] = $callback;
                    }
                }
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Shortcode', 'wp-dynamic-tags'); ?></th>
                            <th><?php _e('Type', 'wp-dynamic-tags'); ?></th>
                            <th><?php _e('Test', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dt_shortcodes as $shortcode => $callback): ?>
                            <tr>
                                <td><code>[<?php echo esc_html($shortcode); ?>]</code></td>
                                <td>
                                    <?php
                                    if (in_array($shortcode, ['dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list'])) {
                                        echo 'System';
                                    } elseif (strpos($shortcode, '_') !== false) {
                                        echo 'Group-aware';
                                    } else {
                                        echo 'Basic';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button onclick="testShortcode('<?php echo esc_js($shortcode); ?>')"
                                        class="button button-small">Test</button>
                                    <span id="test-result-<?php echo esc_attr($shortcode); ?>" style="margin-left: 10px;"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Tag Cache Information', 'wp-dynamic-tags'); ?></h2>
                <?php
                $cached_tags = $this->get_dynamic_tags();
                ?>
                <p><strong><?php _e('Cached Tags Count:', 'wp-dynamic-tags'); ?></strong> <?php echo count($cached_tags); ?></p>
                <details>
                    <summary><?php _e('View Cached Tags Data', 'wp-dynamic-tags'); ?></summary>
                    <pre
                        style="background: #f1f1f1; padding: 15px; margin-top: 10px; overflow: auto; max-height: 400px;"><?php echo esc_html(print_r($cached_tags, true)); ?></pre>
                </details>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Duplicate Tags Detection', 'wp-dynamic-tags'); ?></h2>
                <?php
                $duplicate_tags = $this->check_for_duplicate_tag_names();
                $ungrouped_duplicates = $this->get_ungrouped_duplicate_tags();
                ?>
                <p><strong><?php _e('Duplicate Tag Groups:', 'wp-dynamic-tags'); ?></strong>
                    <?php echo count($duplicate_tags); ?></p>
                <p><strong><?php _e('Ungrouped Duplicates:', 'wp-dynamic-tags'); ?></strong>
                    <?php echo count($ungrouped_duplicates); ?></p>

                <?php if (!empty($duplicate_tags)): ?>
                    <details>
                        <summary><?php _e('View Duplicate Tags', 'wp-dynamic-tags'); ?></summary>
                        <pre
                            style="background: #f1f1f1; padding: 15px; margin-top: 10px; overflow: auto; max-height: 400px;"><?php echo esc_html(print_r($duplicate_tags, true)); ?></pre>
                    </details>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Shortcode Testing', 'wp-dynamic-tags'); ?></h2>
                <p><?php _e('Test specific shortcodes:', 'wp-dynamic-tags'); ?></p>
                <input type="text" id="test-shortcode-input" placeholder="Enter shortcode (e.g., math_olympiad_date3)"
                    style="width: 300px;">
                <button onclick="testCustomShortcode()"
                    class="button button-primary"><?php _e('Test Shortcode', 'wp-dynamic-tags'); ?></button>
                <div id="custom-test-result"
                    style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; display: none;"></div>
            </div>
        </div>

        <script>
            function testShortcode(shortcode) {
                var resultElement = document.getElementById('test-result-' + shortcode);
                resultElement.innerHTML = 'Testing...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=dt_test_shortcode&shortcode=' + encodeURIComponent(shortcode) + '&nonce=<?php echo wp_create_nonce('dt_test_shortcode'); ?>'
                })
                    .then(response => response.text())
                    .then(data => {
                        resultElement.innerHTML = data ? 'Result: ' + data : 'Empty result';
                    })
                    .catch(error => {
                        resultElement.innerHTML = 'Error: ' + error;
                    });
            }

            function testCustomShortcode() {
                var shortcode = document.getElementById('test-shortcode-input').value;
                var resultElement = document.getElementById('custom-test-result');

                if (!shortcode) {
                    alert('Please enter a shortcode to test');
                    return;
                }

                resultElement.style.display = 'block';
                resultElement.innerHTML = 'Testing [' + shortcode + ']...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=dt_test_shortcode&shortcode=' + encodeURIComponent(shortcode) + '&nonce=<?php echo wp_create_nonce('dt_test_shortcode'); ?>'
                })
                    .then(response => response.text())
                    .then(data => {
                        resultElement.innerHTML = '<strong>Shortcode:</strong> [' + shortcode + ']<br><strong>Result:</strong> ' + (data || 'Empty/No result');
                    })
                    .catch(error => {
                        resultElement.innerHTML = '<strong>Error:</strong> ' + error;
                    });
            }
        </script>
        <?php
    }

    /**
     * Check if admin page is functioning correctly (for debugging)
     */
    public function admin_page_health_check()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $issues = array();

        // Check if post type is registered
        if (!post_type_exists($this->post_type)) {
            $issues[] = 'Post type not registered';
        }

        // Check if taxonomy exists
        if (!taxonomy_exists('tag_groups')) {
            $issues[] = 'Tag groups taxonomy not registered';
        }

        // Check for critical methods
        if (!method_exists($this, 'get_posts_count_by_group')) {
            $issues[] = 'Missing get_posts_count_by_group method';
        }

        // If issues found and debugging is enabled, show notice
        if (!empty($issues) && defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_notices', function () use ($issues) {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>WP Dynamic Tags Health Check:</strong></p>';
                echo '<ul>';
                foreach ($issues as $issue) {
                    echo '<li>' . esc_html($issue) . '</li>';
                }
                echo '</ul>';
                echo '</div>';
            });
        }
    }

    /**
     * Performance monitoring and statistics
     */
    public function get_performance_stats()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return array();
        }

        return array(
            'cache_hits' => count(self::$static_cache),
            'query_count' => self::$query_count,
            'registered_shortcodes' => count(self::$registered_shortcodes),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'optimization_version' => $this->cache_version
        );
    }

    /**
     * Display performance debug info (only in debug mode)
     */
    public function show_performance_debug()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG || !current_user_can('manage_options')) {
            return;
        }

        $stats = $this->get_performance_stats();
        echo '<!-- WP Dynamic Tags Performance Stats: ';
        echo 'Cache Hits: ' . $stats['cache_hits'] . ', ';
        echo 'Queries: ' . $stats['query_count'] . ', ';
        echo 'Shortcodes: ' . $stats['registered_shortcodes'] . ', ';
        echo 'Memory: ' . size_format($stats['memory_usage']) . ' -->';
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    WP_Dynamic_Tags_Plugin::get_instance();
});

// Clear cache when dynamic tag posts are saved or deleted
add_action('save_post', function ($post_id) {
    if (get_post_type($post_id) === 'dynamic_tag') {
        delete_transient('wp_dynamic_tags_cache');
    }
});

add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) === 'dynamic_tag') {
        delete_transient('wp_dynamic_tags_cache');
    }
});