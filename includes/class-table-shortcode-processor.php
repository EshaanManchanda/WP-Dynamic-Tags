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

        // Initialize performance stats counters
        $this->performance_stats = array(
            'cache_hits' => 0,
            'cache_misses' => 0,
            'shortcode_executions' => array(),
            'usage_tracking' => array()
        );

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
        // REST requests render content too (L4); see WP_Dynamic_Tags_Plugin::should_register_shortcodes().
        if (!WP_Dynamic_Tags_Plugin::should_register_shortcodes()) {
            return;
        }

        // No lock: registering twice is harmless (a name this class registered is skipped) and the lock cost two database
        // writes per request. The tags are the one registry query of the request; the order does not matter (the shortcode
        // column is unique), so the primary key is used.
        $start_time = microtime(true);
        $this->tag_index = null; // the registry is read again, so the lookup index of the core shortcodes is rebuilt too

        try {
            $tags = $this->all_active_tags();

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

    /** @var array<string, array<string, object>>|null Lookup index of the core shortcodes, built from the registry on first use. */
    private $tag_index = null;

    /**
     * Every active tag: the registry of the request. Registration and the core shortcodes ask with exactly these
     * arguments, so the table is read once (the facade caches by arguments).
     *
     * @return object[]
     */
    public function all_active_tags() {
        return $this->table_manager->get_tags(array('status' => 'active', 'orderby' => 'id', 'order' => 'ASC'));
    }

    /**
     * Active tags for the list shortcodes ([dt_group], [dt_list], [dt_random]).
     *
     * @param int|null $category_id Group (term id), null for every group.
     * @param string   $orderby     title|name, date, modified, id, priority, usage_count (the old post-based names are accepted).
     * @param string   $order       ASC or DESC.
     * @param int      $limit       0 or less: no limit.
     * @return object[]
     */
    public function active_tags($category_id = null, $orderby = 'title', $order = 'ASC', $limit = 0) {
        $columns = array(
            'title' => 'tag_name', 'name' => 'tag_name', 'date' => 'created_date', 'modified' => 'modified_date',
            'id' => 'id', 'priority' => 'priority', 'usage_count' => 'usage_count',
        );
        $orderby = $columns[strtolower((string) $orderby)] ?? 'tag_name';
        $order = strtoupper((string) $order) === 'DESC' ? 'DESC' : 'ASC';

        if ($category_id === null && $orderby === 'id' && $order === 'ASC' && (int) $limit <= 0) {
            return $this->all_active_tags();
        }

        $args = array('status' => 'active', 'orderby' => $orderby, 'order' => $order, 'limit' => (int) $limit);
        if ($category_id !== null) {
            $args['category_id'] = (int) $category_id;
        }
        return $this->table_manager->get_tags($args);
    }

    /**
     * The active tag a [dt tag="..."] style key points at: its shortcode as written, the key normalized the way tag keys
     * always were (lower case, other characters to underscores), its name, or a legacy alias recorded by the migration.
     * Read from the registry, no extra query.
     *
     * @return object|null
     */
    public function find_tag($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        if ($this->tag_index === null) {
            $this->tag_index = array('shortcode' => array(), 'name' => array(), 'alias' => array());
            foreach ($this->all_active_tags() as $tag) {
                $this->tag_index['shortcode'][$tag->shortcode] = $tag;
                $this->tag_index['name'][strtolower($tag->tag_name)] = $tag;
                $aliases = (is_array($tag->meta_data) && isset($tag->meta_data['legacy']['aliases'])) ? (array) $tag->meta_data['legacy']['aliases'] : array();
                foreach ($aliases as $alias) {
                    $this->tag_index['alias'][strtolower((string) $alias)] = $tag;
                }
            }
        }

        $normalized = trim((string) preg_replace('/_{2,}/', '_', (string) preg_replace('/[^a-z0-9]/', '_', strtolower($key))), '_');
        $lower = strtolower($key);

        return $this->tag_index['shortcode'][$key]
            ?? $this->tag_index['shortcode'][$normalized]
            ?? $this->tag_index['name'][$lower]
            ?? $this->tag_index['alias'][$lower]
            ?? $this->tag_index['alias'][$normalized]
            ?? null;
    }

    /**
     * Renders one tag the way its own shortcode does (render stack, depth and size guard, the unified pipeline). The core
     * shortcodes use this, so [dt tag="x"] and [x] cannot drift apart.
     *
     * @param object              $tag
     * @param array<string,mixed> $atts Shortcode attributes (format, default, cache, processing).
     * @return string
     */
    public function render_row($tag, $atts = array()) {
        return (string) $this->process_shortcode($tag, $atts);
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

        // Register shortcode. A name that another plugin (or WordPress) owns is skipped and recorded, never removed (F1);
        // filter dt_allow_shortcode_override restores the old "last one wins" on purpose.
        if (shortcode_exists($tag->shortcode)) {
            if (!WP_Dynamic_Tags_Plugin::shortcode_is_ours($tag->shortcode) && !apply_filters('dt_allow_shortcode_override', false, $tag->shortcode)) {
                WP_Dynamic_Tags_Plugin::note_shortcode_conflict($tag->shortcode);
                return false;
            }
            remove_shortcode($tag->shortcode);
        }

        add_shortcode($tag->shortcode, $callback);
        $this->registered_shortcodes[$tag->shortcode] = $tag->id;

        return true;
    }

    /**
     * Tags currently being rendered in this request, keyed by tag id (L8). A tag whose content refers to itself, or two
     * tags that refer to each other, used to render until PHP ran out of memory and took the page down.
     *
     * @var array<int|string, true>
     */
    private static $render_stack = array();

    /** Incremented every time a guard trips; a result produced while it changed is context-dependent and not cached. */
    private static $guard_trips = 0;

    /**
     * Process shortcode with enhanced features.
     *
     * Wrapper that applies the recursion guard (cycle detection by tag id, maximum depth, maximum output size, all
     * filterable) and delegates to render_tag().
     */
    private function process_shortcode($tag, $atts = array()) {
        $fallback = (is_array($atts) && isset($atts['default'])) ? (string) $atts['default'] : '';

        if (isset(self::$render_stack[$tag->id])) {
            self::$guard_trips++;
            return $fallback; // cycle: this tag is already being rendered further up
        }

        $max_depth = max(1, (int) apply_filters('dt_max_render_depth', 10));
        if (count(self::$render_stack) >= $max_depth) {
            self::$guard_trips++;
            return $fallback;
        }

        self::$render_stack[$tag->id] = true;
        try {
            return $this->render_tag($tag, $atts);
        } finally {
            unset(self::$render_stack[$tag->id]);
        }
    }

    /**
     * Render one tag (the body process_shortcode() used to have).
     */
    private function render_tag($tag, $atts) {
        $start_time = microtime(true);
        $trips_before = self::$guard_trips;

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

            // Track cache miss
            if ($atts['cache'] === 'true') {
                $this->performance_stats['cache_misses']++;
            }

            // Process content
            $content = $tag->content;

            // Apply processing if enabled
            if ($atts['processing'] === 'true') {
                $content = $this->process_content($content, $tag, $atts);
            }

            // S10: the output of a tag is not run through wp_kses_post by default (an administrator writes tags and may
            // add scripts or iframes on purpose); the filter turns it on, per tag.
            if (apply_filters('dt_kses_tag_output', false, $tag)) {
                $content = wp_kses_post($content);
            }

            // Apply formatting
            $content = $this->apply_formatting($content, $atts['format'], $tag);

            // Output size guard (L8): nested expansion can multiply output; drop anything beyond the limit.
            $max_size = (int) apply_filters('dt_max_render_size', 1048576);
            if ($max_size > 0 && strlen($content) > $max_size) {
                self::$guard_trips++;
                return $atts['default'];
            }

            // Cache result, unless a guard tripped while rendering it: such a result depends on where the tag was
            // reached from (the render stack), so serving it from the cache elsewhere would be wrong.
            if ($atts['cache'] === 'true' && self::$guard_trips === $trips_before) {
                $this->cache[$cache_key] = $content;
            }

            // Performance tracking: keep only the most recent entries, the list used to grow with every render (L9).
            $execution_time = microtime(true) - $start_time;
            if (count($this->performance_stats['shortcode_executions']) >= 50) {
                array_shift($this->performance_stats['shortcode_executions']);
            }
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
        // Tokens only a table row can answer: the placeholder engine has no tag context. Everything else ({site_name},
        // {user_name}, {meta:}, {acf:}, {wc:}, formatters, {if}{else}{/if} blocks, ...) belongs to the engine, so a tag
        // renders the same whatever it came from. (Table-native tags never went through the engine before: {meta:} and
        // formatters stayed raw in them, and this class kept its own, smaller list of tokens and conditions.)
        $content = str_replace(
            array('{tag_name}', '{tag_id}', '{usage_count}', '{user_id}'),
            array($tag->tag_name, $tag->id, $tag->usage_count, get_current_user_id()),
            (string) $content
        );

        // Placeholders, formatters and conditional blocks (filter dt_process_value, hooked by WP_Dynamic_Tags_Placeholders)
        $content = apply_filters('dt_process_value', $content);

        // Nested shortcodes
        return do_shortcode($content);
    }

    /**
     * Whether a time of day (minutes since midnight) lies inside [start, end], both inclusive. A window whose end is
     * before its start runs through midnight (22:00-06:00); the old string comparison could never be true for it (L2).
     * Pure, so it is unit tested without WordPress.
     */
    public static function time_in_window(int $now, int $start, int $end): bool {
        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }
        return $now >= $start || $now <= $end;
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
        // The output depends on where and for whom a tag is rendered ({post_title}, {meta:}, {user_role}, ...): the same tag
        // shown in several posts of one archive page, or after the current user changed, must not reuse the first output.
        return 'dt_cache_' . $tag->id . '_' . md5(serialize($atts)) . '_' . (int) get_the_ID() . '_' . get_current_user_id();
    }

    /**
     * Handle tag creation
     */
    public function on_tag_created($tag_id, $data) {
        $this->tag_index = null;

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
            if (shortcode_exists($old_data->shortcode) && WP_Dynamic_Tags_Plugin::shortcode_is_ours($old_data->shortcode)) {
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
        if (!empty($old_data->shortcode) && shortcode_exists($old_data->shortcode) && WP_Dynamic_Tags_Plugin::shortcode_is_ours($old_data->shortcode)) {
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
        $this->tag_index = null;
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
        $this->tag_index = null;
    }

    /**
     * Force re-registration of all shortcodes
     */
    public function force_reregister() {
        // Remove existing shortcodes
        foreach ($this->registered_shortcodes as $shortcode => $tag_id) {
            if (shortcode_exists($shortcode) && WP_Dynamic_Tags_Plugin::shortcode_is_ours($shortcode)) {
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