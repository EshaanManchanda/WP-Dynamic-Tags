<?php
/**
 * Table-based Shortcode Processor for WP Dynamic Tags
 *
 * High-performance shortcode processing using custom table
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Table_Shortcode_Processor {

    private static $instance = null;
    private $table_manager;
    private $db_manager;
    private $cache = array();
    private $registered_shortcodes = array();
    private $performance_stats = array();

    public function __construct() {
        $this->table_manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();

        // Hook into WordPress
        add_action('init', array($this, 'register_table_shortcodes'), 20);
        add_action('wp_dynamic_tags_tag_created', array($this, 'on_tag_created'), 10, 2);
        add_action('wp_dynamic_tags_tag_updated', array($this, 'on_tag_updated'), 10, 3);
        add_action('wp_dynamic_tags_tag_deleted', array($this, 'on_tag_deleted'), 10, 2);

        // Performance monitoring
        add_action('wp_footer', array($this, 'output_performance_stats'));
        add_action('admin_footer', array($this, 'output_performance_stats'));
    }

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register all shortcodes from table
     */
    public function register_table_shortcodes() {
        // Skip registration during REST API requests to prevent timeouts
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Lock mechanism to prevent duplicate registration calls
        $lock_key = 'dt_table_registration_lock';
        if (get_transient($lock_key)) {
            return; // Registration already in progress or recently completed
        }

        // Set lock for 3 seconds
        set_transient($lock_key, true, 3);

        $start_time = microtime(true);

        try {
            // Get active tags from table
            $tags = $this->table_manager->get_tags(array(
                'status' => 'active',
                'orderby' => 'usage_count',
                'order' => 'DESC'
            ));

            $registered_count = 0;
            $failed_count = 0;

            foreach ($tags as $tag) {
                if ($this->register_single_shortcode($tag)) {
                    $registered_count++;
                } else {
                    $failed_count++;
                }
            }

            // Register special shortcodes
            $this->register_special_shortcodes();

            $execution_time = microtime(true) - $start_time;

            $this->performance_stats['shortcode_registration'] = array(
                'execution_time' => $execution_time,
                'tags_processed' => count($tags),
                'registered_count' => $registered_count,
                'failed_count' => $failed_count,
                'memory_usage' => memory_get_usage()
            );

            if (defined('WP_DEBUG') && WP_DEBUG && defined('DT_DEBUG_VERBOSE')) {
                error_log(sprintf(
                    'WP Dynamic Tags Table: Registered %d shortcodes in %.4f seconds (%d failed)',
                    $registered_count,
                    $execution_time,
                    $failed_count
                ));
            }

        } catch (Exception $e) {
            error_log('WP Dynamic Tags Table: Shortcode registration error: ' . $e->getMessage());
        }
    }

    /**
     * Register a single shortcode from table data
     */
    private function register_single_shortcode($tag) {
        if (empty($tag->shortcode)) {
            return false;
        }

        // Skip if already registered
        if (isset($this->registered_shortcodes[$tag->shortcode])) {
            return true;
        }

        // Create optimized callback
        $callback = function($atts) use ($tag) {
            return $this->process_shortcode($tag, $atts);
        };

        // Register shortcode
        if (shortcode_exists($tag->shortcode)) {
            remove_shortcode($tag->shortcode);
        }

        add_shortcode($tag->shortcode, $callback);
        $this->registered_shortcodes[$tag->shortcode] = $tag->id;

        return true;
    }

    /**
     * Process shortcode with enhanced features
     */
    private function process_shortcode($tag, $atts = array()) {
        $start_time = microtime(true);

        try {
            // Track usage
            $this->track_usage($tag->id);

            // Parse attributes
            $atts = shortcode_atts(array(
                'format' => 'default',
                'default' => '',
                'cache' => 'true',
                'processing' => 'true'
            ), $atts, $tag->shortcode);

            // Check cache first
            $cache_key = $this->get_cache_key($tag, $atts);
            if ($atts['cache'] === 'true' && isset($this->cache[$cache_key])) {
                $this->performance_stats['cache_hits']++;
                return $this->cache[$cache_key];
            }

            // Process content
            $content = $tag->content;

            // Apply processing if enabled
            if ($atts['processing'] === 'true') {
                $content = $this->process_content($content, $tag, $atts);
            }

            // Apply formatting
            $content = $this->apply_formatting($content, $atts['format'], $tag);

            // Cache result
            if ($atts['cache'] === 'true') {
                $this->cache[$cache_key] = $content;
            }

            // Performance tracking
            $execution_time = microtime(true) - $start_time;
            $this->performance_stats['shortcode_executions'][] = array(
                'shortcode' => $tag->shortcode,
                'execution_time' => $execution_time,
                'content_length' => strlen($content)
            );

            return $content;

        } catch (Exception $e) {
            error_log('WP Dynamic Tags: Error processing shortcode [' . $tag->shortcode . ']: ' . $e->getMessage());
            return !empty($atts['default']) ? $atts['default'] : '';
        }
    }

    /**
     * Process content with placeholders and dynamic features
     */
    private function process_content($content, $tag, $atts) {
        // Basic placeholder processing
        $placeholders = array(
            '{current_date}' => current_time('Y-m-d'),
            '{current_time}' => current_time('H:i:s'),
            '{current_year}' => current_time('Y'),
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => get_site_url(),
            '{admin_email}' => get_option('admin_email'),
            '{tag_name}' => $tag->tag_name,
            '{tag_id}' => $tag->id,
            '{usage_count}' => $tag->usage_count
        );

        // User-specific placeholders (if user is logged in)
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $placeholders['{user_name}'] = $current_user->display_name;
            $placeholders['{user_email}'] = $current_user->user_email;
            $placeholders['{user_id}'] = $current_user->ID;
        } else {
            $placeholders['{user_name}'] = 'Guest';
            $placeholders['{user_email}'] = '';
            $placeholders['{user_id}'] = '0';
        }

        // Post-specific placeholders (if in post context)
        if (is_singular()) {
            global $post;
            if ($post) {
                $placeholders['{post_title}'] = $post->post_title;
                $placeholders['{post_id}'] = $post->ID;
                $placeholders['{post_url}'] = get_permalink($post->ID);
                $placeholders['{post_author}'] = get_the_author_meta('display_name', $post->post_author);
            }
        }

        // Apply placeholders
        $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);

        // Process conditional content
        $content = $this->process_conditionals($content);

        // Process nested shortcodes
        $content = do_shortcode($content);

        return $content;
    }

    /**
     * Process conditional content blocks
     */
    private function process_conditionals($content) {
        // Pattern: {if:condition}content{/if}
        $pattern = '/\{if:([^}]+)\}(.*?)\{\/if\}/s';

        return preg_replace_callback($pattern, function($matches) {
            $condition = trim($matches[1]);
            $conditional_content = $matches[2];

            if ($this->evaluate_condition($condition)) {
                return $conditional_content;
            }

            return '';
        }, $content);
    }

    /**
     * Evaluate conditional expressions
     */
    private function evaluate_condition($condition) {
        // Simple condition evaluation
        if (strpos($condition, 'user_logged_in') !== false) {
            return is_user_logged_in();
        }

        if (strpos($condition, 'is_admin') !== false) {
            return current_user_can('manage_options');
        }

        if (strpos($condition, 'is_mobile') !== false) {
            return wp_is_mobile();
        }

        if (preg_match('/time_between\((\d{1,2}):(\d{2}),(\d{1,2}):(\d{2})\)/', $condition, $matches)) {
            $start_hour = intval($matches[1]);
            $start_min = intval($matches[2]);
            $end_hour = intval($matches[3]);
            $end_min = intval($matches[4]);

            $current_time = current_time('H:i');
            $start_time = sprintf('%02d:%02d', $start_hour, $start_min);
            $end_time = sprintf('%02d:%02d', $end_hour, $end_min);

            return ($current_time >= $start_time && $current_time <= $end_time);
        }

        // Default to false for unknown conditions
        return false;
    }

    /**
     * Apply formatting to content
     */
    private function apply_formatting($content, $format, $tag) {
        switch ($format) {
            case 'uppercase':
                return strtoupper($content);

            case 'lowercase':
                return strtolower($content);

            case 'title':
                return ucwords($content);

            case 'excerpt':
                return wp_trim_words($content, 55, '...');

            case 'no_html':
                return strip_tags($content);

            case 'html_entities':
                return htmlentities($content);

            case 'url_encode':
                return urlencode($content);

            case 'json':
                return json_encode(array(
                    'id' => $tag->id,
                    'name' => $tag->tag_name,
                    'content' => $content,
                    'usage_count' => $tag->usage_count
                ));

            case 'default':
            default:
                return $content;
        }
    }

    /**
     * Register special shortcodes
     */
    private function register_special_shortcodes() {
        // Table-based group shortcode
        add_shortcode('dt_table_group', array($this, 'group_shortcode'));

        // Table-based random shortcode
        add_shortcode('dt_table_random', array($this, 'random_shortcode'));

        // Table-based list shortcode
        add_shortcode('dt_table_list', array($this, 'list_shortcode'));

        // Table-based search shortcode
        add_shortcode('dt_table_search', array($this, 'search_shortcode'));

        // Performance stats shortcode
        add_shortcode('dt_table_stats', array($this, 'stats_shortcode'));
    }

    /**
     * Group shortcode handler
     */
    public function group_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'format' => 'list',
            'separator' => ', ',
            'limit' => -1,
            'orderby' => 'priority',
            'order' => 'DESC'
        ), $atts, 'dt_table_group');

        if (empty($atts['category'])) {
            return '';
        }

        // Get category ID
        $category = get_term_by('slug', $atts['category'], 'tag_groups');
        if (!$category) {
            return '';
        }

        // Get tags from table
        $args = array(
            'category_id' => $category->term_id,
            'status' => 'active',
            'orderby' => $atts['orderby'],
            'order' => $atts['order']
        );

        if ($atts['limit'] > 0) {
            $args['limit'] = intval($atts['limit']);
        }

        $tags = $this->table_manager->get_tags($args);

        if (empty($tags)) {
            return '';
        }

        $output = array();
        foreach ($tags as $tag) {
            switch ($atts['format']) {
                case 'links':
                    $output[] = '<a href="#" data-shortcode="' . esc_attr($tag->shortcode) . '">' . esc_html($tag->tag_name) . '</a>';
                    break;
                case 'shortcodes':
                    $output[] = '[' . $tag->shortcode . ']';
                    break;
                case 'list':
                default:
                    $output[] = esc_html($tag->tag_name);
                    break;
            }
        }

        return implode($atts['separator'], $output);
    }

    /**
     * Random shortcode handler
     */
    public function random_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'count' => 1,
            'format' => 'content'
        ), $atts, 'dt_table_random');

        $args = array(
            'status' => 'active',
            'orderby' => 'id',
            'order' => 'ASC'
        );

        if (!empty($atts['category'])) {
            $category = get_term_by('slug', $atts['category'], 'tag_groups');
            if ($category) {
                $args['category_id'] = $category->term_id;
            }
        }

        $tags = $this->table_manager->get_tags($args);

        if (empty($tags)) {
            return '';
        }

        // Shuffle and limit
        shuffle($tags);
        $selected_tags = array_slice($tags, 0, intval($atts['count']));

        $output = array();
        foreach ($selected_tags as $tag) {
            switch ($atts['format']) {
                case 'name':
                    $output[] = $tag->tag_name;
                    break;
                case 'shortcode':
                    $output[] = '[' . $tag->shortcode . ']';
                    break;
                case 'content':
                default:
                    $output[] = $this->process_content($tag->content, $tag, array());
                    break;
            }
        }

        return implode('<br>', $output);
    }

    /**
     * List shortcode handler
     */
    public function list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'template' => '{name}: {content}',
            'wrapper' => 'ul',
            'item_wrapper' => 'li',
            'limit' => -1,
            'orderby' => 'priority',
            'order' => 'DESC'
        ), $atts, 'dt_table_list');

        $args = array(
            'status' => 'active',
            'orderby' => $atts['orderby'],
            'order' => $atts['order']
        );

        if (!empty($atts['category'])) {
            $category = get_term_by('slug', $atts['category'], 'tag_groups');
            if ($category) {
                $args['category_id'] = $category->term_id;
            }
        }

        if ($atts['limit'] > 0) {
            $args['limit'] = intval($atts['limit']);
        }

        $tags = $this->table_manager->get_tags($args);

        if (empty($tags)) {
            return '';
        }

        $items = array();
        foreach ($tags as $tag) {
            $item_content = str_replace(
                array('{name}', '{content}', '{shortcode}', '{id}', '{usage_count}'),
                array(
                    esc_html($tag->tag_name),
                    $this->process_content($tag->content, $tag, array()),
                    $tag->shortcode,
                    $tag->id,
                    $tag->usage_count
                ),
                $atts['template']
            );

            if ($atts['item_wrapper']) {
                $item_content = "<{$atts['item_wrapper']}>{$item_content}</{$atts['item_wrapper']}>";
            }

            $items[] = $item_content;
        }

        $output = implode("\n", $items);

        if ($atts['wrapper']) {
            $output = "<{$atts['wrapper']}>\n{$output}\n</{$atts['wrapper']}>";
        }

        return $output;
    }

    /**
     * Search shortcode handler
     */
    public function search_shortcode($atts) {
        $atts = shortcode_atts(array(
            'query' => '',
            'limit' => 10,
            'format' => 'list'
        ), $atts, 'dt_table_search');

        if (empty($atts['query'])) {
            return '';
        }

        $tags = $this->table_manager->search_tags($atts['query'], array(
            'status' => 'active',
            'limit' => intval($atts['limit'])
        ));

        if (empty($tags)) {
            return '<p>No tags found for: ' . esc_html($atts['query']) . '</p>';
        }

        $output = array();
        foreach ($tags as $tag) {
            switch ($atts['format']) {
                case 'detailed':
                    $output[] = sprintf(
                        '<div class="dt-search-result"><h4>%s</h4><p>%s</p><small>Shortcode: [%s]</small></div>',
                        esc_html($tag->tag_name),
                        wp_trim_words($tag->content, 20),
                        esc_html($tag->shortcode)
                    );
                    break;
                case 'list':
                default:
                    $output[] = esc_html($tag->tag_name) . ' ([' . $tag->shortcode . '])';
                    break;
            }
        }

        return implode('<br>', $output);
    }

    /**
     * Stats shortcode handler
     */
    public function stats_shortcode($atts) {
        $atts = shortcode_atts(array(
            'type' => 'summary',
            'format' => 'html'
        ), $atts, 'dt_table_stats');

        $stats = $this->table_manager->get_statistics();

        switch ($atts['type']) {
            case 'performance':
                if (empty($this->performance_stats)) {
                    return 'No performance data available';
                }

                $output = array();
                foreach ($this->performance_stats as $key => $value) {
                    if (is_array($value)) {
                        $output[] = $key . ': ' . json_encode($value);
                    } else {
                        $output[] = $key . ': ' . $value;
                    }
                }

                return $atts['format'] === 'json' ? json_encode($this->performance_stats) : implode('<br>', $output);

            case 'summary':
            default:
                if ($atts['format'] === 'json') {
                    return json_encode($stats);
                }

                return sprintf(
                    'Total Tags: %d | Active: %d | Inactive: %d | Most Used: %s (%d uses)',
                    $stats['total_tags'],
                    $stats['active_tags'],
                    $stats['inactive_tags'],
                    $stats['most_used_tag'] ? $stats['most_used_tag']->tag_name : 'None',
                    $stats['most_used_tag'] ? $stats['most_used_tag']->usage_count : 0
                );
        }
    }

    /**
     * Track shortcode usage
     */
    private function track_usage($tag_id) {
        // Update usage count in table
        $this->table_manager->track_usage($tag_id);

        // Track in performance stats
        if (!isset($this->performance_stats['usage_tracking'])) {
            $this->performance_stats['usage_tracking'] = array();
        }

        if (!isset($this->performance_stats['usage_tracking'][$tag_id])) {
            $this->performance_stats['usage_tracking'][$tag_id] = 0;
        }

        $this->performance_stats['usage_tracking'][$tag_id]++;
    }

    /**
     * Generate cache key
     */
    private function get_cache_key($tag, $atts) {
        return 'dt_cache_' . $tag->id . '_' . md5(serialize($atts));
    }

    /**
     * Handle tag creation
     */
    public function on_tag_created($tag_id, $data) {
        // Register new shortcode if it has one
        if (!empty($data['shortcode'])) {
            $tag = $this->table_manager->get_tag($tag_id);
            if ($tag) {
                $this->register_single_shortcode($tag);
            }
        }
    }

    /**
     * Handle tag update
     */
    public function on_tag_updated($tag_id, $data, $old_data) {
        // If shortcode changed, update registration
        if (isset($data['shortcode']) && $data['shortcode'] !== $old_data->shortcode) {
            // Remove old shortcode
            if (shortcode_exists($old_data->shortcode)) {
                remove_shortcode($old_data->shortcode);
                unset($this->registered_shortcodes[$old_data->shortcode]);
            }

            // Register new shortcode
            $tag = $this->table_manager->get_tag($tag_id);
            if ($tag) {
                $this->register_single_shortcode($tag);
            }
        }

        // Clear cache for this tag
        $this->clear_tag_cache($tag_id);
    }

    /**
     * Handle tag deletion
     */
    public function on_tag_deleted($tag_id, $old_data) {
        // Remove shortcode
        if (!empty($old_data->shortcode) && shortcode_exists($old_data->shortcode)) {
            remove_shortcode($old_data->shortcode);
            unset($this->registered_shortcodes[$old_data->shortcode]);
        }

        // Clear cache
        $this->clear_tag_cache($tag_id);
    }

    /**
     * Clear cache for specific tag
     */
    private function clear_tag_cache($tag_id) {
        $keys_to_remove = array();

        foreach ($this->cache as $key => $value) {
            if (strpos($key, 'dt_cache_' . $tag_id . '_') === 0) {
                $keys_to_remove[] = $key;
            }
        }

        foreach ($keys_to_remove as $key) {
            unset($this->cache[$key]);
        }
    }

    /**
     * Output performance statistics (debug mode only)
     */
    public function output_performance_stats() {
        if (!defined('WP_DEBUG') || !WP_DEBUG || empty($this->performance_stats)) {
            return;
        }

        echo "\n<!-- WP Dynamic Tags Table Performance Stats:\n";
        echo json_encode($this->performance_stats, JSON_PRETTY_PRINT);
        echo "\n-->\n";
    }

    /**
     * Get registered shortcodes list
     */
    public function get_registered_shortcodes() {
        return $this->registered_shortcodes;
    }

    /**
     * Get performance statistics
     */
    public function get_performance_stats() {
        return $this->performance_stats;
    }

    /**
     * Clear all caches
     */
    public function clear_all_caches() {
        $this->cache = array();
    }

    /**
     * Force re-registration of all shortcodes
     */
    public function force_reregister() {
        // Remove existing shortcodes
        foreach ($this->registered_shortcodes as $shortcode => $tag_id) {
            if (shortcode_exists($shortcode)) {
                remove_shortcode($shortcode);
            }
        }

        // Clear tracking
        $this->registered_shortcodes = array();
        $this->cache = array();

        // Re-register
        $this->register_table_shortcodes();
    }
}