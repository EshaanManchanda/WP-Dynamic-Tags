<?php
/**
 * Dynamic Placeholders System for WP Dynamic Tags
 * 
 * Handles dynamic content like {user_name}, {current_year}, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Placeholders {

    private $placeholders = array();

    /** Parameterized placeholders, e.g. {meta:key} / {acf:field}. Keyed by prefix. */
    private $parameterized_placeholders = array();

    /** Tracks the active instance so external code can call get_instance(). */
    private static $_instance = null;

    public function __construct() {
        self::$_instance = $this; // Register self so get_instance() works
        $this->register_default_placeholders();
        add_filter('dt_process_value', array($this, 'process_placeholders'), 10, 1);
    }

    /**
     * Return the active instance (set during construction by the main plugin).
     * External plugins use this after the main plugin boots.
     */
    public static function get_instance(): ?self {
        return self::$_instance;
    }
    
    /**
     * Register default placeholders
     */
    private function register_default_placeholders() {
        // User placeholders
        $this->register_placeholder('user_name', array($this, 'get_user_name'));
        $this->register_placeholder('user_email', array($this, 'get_user_email'));
        $this->register_placeholder('user_role', array($this, 'get_user_role'));
        $this->register_placeholder('user_display_name', array($this, 'get_user_display_name'));
        $this->register_placeholder('user_first_name', array($this, 'get_user_first_name'));
        $this->register_placeholder('user_last_name', array($this, 'get_user_last_name'));
        
        // Date/Time placeholders
        $this->register_placeholder('current_year', array($this, 'get_current_year'));
        $this->register_placeholder('current_month', array($this, 'get_current_month'));
        $this->register_placeholder('current_day', array($this, 'get_current_day'));
        $this->register_placeholder('current_date', array($this, 'get_current_date'));
        $this->register_placeholder('current_time', array($this, 'get_current_time'));
        $this->register_placeholder('current_datetime', array($this, 'get_current_datetime'));
        
        // Site placeholders
        $this->register_placeholder('site_name', array($this, 'get_site_name'));
        $this->register_placeholder('site_url', array($this, 'get_site_url'));
        $this->register_placeholder('site_description', array($this, 'get_site_description'));
        $this->register_placeholder('admin_email', array($this, 'get_admin_email'));
        $this->register_placeholder('site_language', array($this, 'get_site_language'));
        $this->register_placeholder('site_charset', array($this, 'get_site_charset'));
        
        // Post context placeholders (when available)
        $this->register_placeholder('post_title', array($this, 'get_post_title'));
        $this->register_placeholder('post_author', array($this, 'get_post_author'));
        $this->register_placeholder('post_date', array($this, 'get_post_date'));
        $this->register_placeholder('post_excerpt', array($this, 'get_post_excerpt'));
        $this->register_placeholder('post_url', array($this, 'get_post_url'));
        $this->register_placeholder('post_id', array($this, 'get_post_id'));
        $this->register_placeholder('post_slug', array($this, 'get_post_slug'));
        $this->register_placeholder('post_content', array($this, 'get_post_content'));
        $this->register_placeholder('post_status', array($this, 'get_post_status'));
        $this->register_placeholder('post_type', array($this, 'get_post_type'));
        $this->register_placeholder('post_modified_date', array($this, 'get_post_modified_date'));
        $this->register_placeholder('post_comment_count', array($this, 'get_post_comment_count'));
        $this->register_placeholder('post_comment_status', array($this, 'get_post_comment_status'));
        $this->register_placeholder('post_shortlink', array($this, 'get_post_shortlink'));
        $this->register_placeholder('post_featured_image', array($this, 'get_post_featured_image'));
        $this->register_placeholder('post_featured_image_id', array($this, 'get_post_featured_image_id'));
        $this->register_placeholder('post_featured_image_alt', array($this, 'get_post_featured_image_alt'));
        $this->register_placeholder('post_featured_image_caption', array($this, 'get_post_featured_image_caption'));
        $this->register_placeholder('post_parent_title', array($this, 'get_post_parent_title'));
        $this->register_placeholder('post_parent_url', array($this, 'get_post_parent_url'));

        // Taxonomy / term placeholders (based on the current post's primary category)
        $this->register_placeholder('term_name', array($this, 'get_term_name'));
        $this->register_placeholder('term_id', array($this, 'get_term_id'));
        $this->register_placeholder('term_slug', array($this, 'get_term_slug'));
        $this->register_placeholder('term_description', array($this, 'get_term_description'));
        $this->register_placeholder('term_url', array($this, 'get_term_url'));
        $this->register_placeholder('term_list', array($this, 'get_term_list'));
        $this->register_placeholder('tag_list', array($this, 'get_tag_list'));

        // Author placeholders (the current post's author — distinct from the logged-in user_* set above)
        $this->register_placeholder('author_name', array($this, 'get_author_name'));
        $this->register_placeholder('author_id', array($this, 'get_author_id'));
        $this->register_placeholder('author_email', array($this, 'get_author_email'));
        $this->register_placeholder('author_bio', array($this, 'get_author_bio'));
        $this->register_placeholder('author_url', array($this, 'get_author_url'));
        $this->register_placeholder('author_website', array($this, 'get_author_website'));
        $this->register_placeholder('author_avatar', array($this, 'get_author_avatar'));

        // Site placeholders (additional)
        $this->register_placeholder('site_logo', array($this, 'get_site_logo'));
        $this->register_placeholder('site_icon', array($this, 'get_site_icon'));
        $this->register_placeholder('php_version', array($this, 'get_php_version'));
        $this->register_placeholder('site_timezone', array($this, 'get_site_timezone'));

        // URL / request placeholders
        $this->register_placeholder('url_current', array($this, 'get_url_current'));
        $this->register_placeholder('url_path', array($this, 'get_url_path'));
        $this->register_placeholder('url_query_string', array($this, 'get_url_query_string'));
        $this->register_placeholder('url_host', array($this, 'get_url_host'));
        $this->register_placeholder('url_referrer', array($this, 'get_url_referrer'));
        $this->register_placeholder('url_user_agent', array($this, 'get_url_user_agent'));

        // WordPress info
        $this->register_placeholder('wp_version', array($this, 'get_wp_version'));
        $this->register_placeholder('theme_name', array($this, 'get_theme_name'));
        $this->register_placeholder('theme_version', array($this, 'get_theme_version'));

        // Conditional placeholders
        $this->register_placeholder('if_logged_in', array($this, 'process_conditional'));
        $this->register_placeholder('if_user_role', array($this, 'process_conditional'));
        $this->register_placeholder('if_mobile', array($this, 'process_conditional'));

        // Parameterized placeholders: {meta:post_meta_key}
        $this->register_parameterized_placeholder('meta', array($this, 'get_meta_value'));
    }
    
    /**
     * Register a placeholder
     */
    public function register_placeholder($key, $callback) {
        $this->placeholders[$key] = $callback;
    }

    /**
     * Register a parameterized placeholder, e.g. {meta:key} or {acf:field}.
     *
     * @param string   $prefix   Prefix used before the colon, e.g. 'meta' for {meta:key}.
     * @param callable $callback Callback invoked with the argument string after the colon.
     */
    public function register_parameterized_placeholder($prefix, $callback) {
        $this->parameterized_placeholders[$prefix] = $callback;
    }

    /**
     * Public static helper for external plugins to register custom {placeholder} syntax.
     *
     * Usage (from any plugin):
     *   WP_Dynamic_Tags_Placeholders::register_external_placeholder(
     *       'my_key',
     *       function() { return 'my value'; },
     *       'Description shown in UI'
     *   );
     *
     * @param string   $key         Placeholder key (used as {key} in content).
     * @param callable $callback    Callback that returns the replacement value.
     * @param string   $description Optional human-readable description.
     */
    public static function register_external_placeholder($key, $callback, $description = '') {
        add_action('init', function() use ($key, $callback) {
            if (!class_exists('WP_Dynamic_Tags_Placeholders')
                || !method_exists('WP_Dynamic_Tags_Placeholders', 'get_instance')) {
                return;
            }
            $instance = WP_Dynamic_Tags_Placeholders::get_instance();
            if ($instance && method_exists($instance, 'register_placeholder')) {
                $instance->register_placeholder($key, $callback);
            }
        }, 25); // Priority 25 — after the class is instantiated (priority 20 in chatbot)
    }

    /**
     * Get all registered placeholders with their current values
     *
     * @return array Associative array of placeholder keys and their values
     */
    public function get_all_placeholders() {
        $result = array();

        foreach ($this->placeholders as $key => $callback) {
            // Call the callback to get current value
            if (is_callable($callback)) {
                try {
                    $result[$key] = call_user_func($callback);
                } catch (Exception $e) {
                    // Return empty string if callback fails
                    $result[$key] = '';
                }
            } else {
                $result[$key] = '';
            }
        }

        return $result;
    }

    /**
     * Process placeholders in content
     */
    public function process_placeholders($content) {
        // Conditionals and {date:FORMAT} run first — they have their own dedicated
        // colon-argument regexes and must claim their tokens before the generic
        // {key:arg|formatter??fallback} pass below would otherwise swallow them.
        $content = $this->process_conditionals($content);
        $content = $this->process_date_formats($content);

        // Generic placeholder pass: {key}, {key:arg}, {key|formatter}, {key??fallback}, etc.
        $content = preg_replace_callback('/\{([a-z_][^{}]*)\}/i', array($this, 'replace_generic_token'), $content);

        // Handle square bracket placeholders (for backward compatibility)
        $original_content = $content;
        $content = preg_replace_callback('/\[([a-z_]+[0-9]*)\]/', array($this, 'replace_square_bracket_placeholder'), $content);

        // Debug logging for square bracket replacements
        if (defined('WP_DEBUG') && WP_DEBUG && $original_content !== $content) {
            error_log("WP Dynamic Tags: Square bracket replacement - Before: " . $original_content . " | After: " . $content);
        }

        return $content;
    }

    /**
     * Split a token body ("key", "key:arg", "key|fmt:farg??fallback", ...) into its parts.
     * Pure function — no WordPress calls — so it can be unit tested without a WP bootstrap.
     *
     * @param string $body The text between "{" and "}", without the braces.
     * @return array{key: string, arg: ?string, formatters: string[], fallback: ?string}
     */
    public static function parse_token_body($body) {
        $fallback = null;
        $pos = strpos($body, '??');
        if ($pos !== false) {
            $fallback = substr($body, $pos + 2);
            $body = substr($body, 0, $pos);
        }

        $parts = explode('|', $body);
        $key_part = trim(array_shift($parts));
        $formatters = array_map('trim', $parts);

        $key = $key_part;
        $arg = null;
        if (strpos($key_part, ':') !== false) {
            list($key, $arg) = explode(':', $key_part, 2);
        }

        return array(
            'key' => trim($key),
            'arg' => $arg,
            'formatters' => $formatters,
            'fallback' => $fallback,
        );
    }

    /**
     * Replace a generic {key}/{key:arg}/{key|formatter}/{key??fallback} token.
     */
    private function replace_generic_token($matches) {
        $parsed = self::parse_token_body($matches[1]);

        $value = $this->resolve_token($parsed['key'], $parsed['arg']);
        if ($value === false) {
            // Unknown key: leave the original token text unchanged, same as before.
            return $matches[0];
        }

        // Fallback is checked against the raw resolved value, before formatting —
        // otherwise a numeric formatter (e.g. currency) would turn an empty value
        // into a non-empty "$0.00" and the fallback would never get a chance to fire.
        // The fallback text itself is returned as-is, not run through the formatters.
        if (($value === '' || $value === null) && $parsed['fallback'] !== null) {
            return $parsed['fallback'];
        }

        if (class_exists('WP_Dynamic_Tags_Formatters')) {
            foreach ($parsed['formatters'] as $formatter) {
                if ($formatter !== '') {
                    $value = WP_Dynamic_Tags_Formatters::format($value, $formatter);
                }
            }
        }

        return $value;
    }

    /**
     * Resolve a parsed key/arg pair against the flat or parameterized registries.
     *
     * @return string|false The resolved value, or false if the key is unregistered.
     */
    private function resolve_token($key, $arg) {
        if ($arg === null) {
            if (isset($this->placeholders[$key]) && is_callable($this->placeholders[$key])) {
                return call_user_func($this->placeholders[$key]);
            }
            return false;
        }

        if (isset($this->parameterized_placeholders[$key]) && is_callable($this->parameterized_placeholders[$key])) {
            return call_user_func($this->parameterized_placeholders[$key], $arg);
        }
        return false;
    }

    /**
     * Get a post meta value for the {meta:key} placeholder.
     */
    private function get_meta_value($key) {
        $post_id = get_the_ID();
        if (!$post_id) {
            return '';
        }
        return (string) get_post_meta($post_id, $key, true);
    }

    /**
     * Replace square bracket placeholder
     */
    private function replace_square_bracket_placeholder($matches) {
        $placeholder = $matches[1];

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Attempting to replace square bracket placeholder: [$placeholder]");
        }

        // Handle specific date placeholders
        $date_mappings = array(
            'date1' => '25th Aug 2056',
            'date2' => '26 Aug 2025',
            'tagname' => 'dynamic_tag_name' // This could be dynamically replaced
        );

        if (isset($date_mappings[$placeholder])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Found mapping for [$placeholder] -> " . $date_mappings[$placeholder]);
            }
            return $date_mappings[$placeholder];
        }

        // Check if it exists in the regular placeholder system
        if (isset($this->placeholders[$placeholder])) {
            $callback = $this->placeholders[$placeholder];
            if (is_callable($callback)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Dynamic Tags: Using callback for placeholder: $placeholder");
                }
                return call_user_func($callback);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: No replacement found for placeholder: [$placeholder], returning original");
        }

        // Return original if not found
        return $matches[0];
    }

    /**
     * Process conditional placeholders
     */
    private function process_conditionals($content) {
        // Pattern: {if:condition}content{else}alt_content{/if}
        $pattern = '/\{if:([^}]+)\}(.*?)\{else\}(.*?)\{\/if\}/s';
        $content = preg_replace_callback($pattern, array($this, 'process_conditional_with_else'), $content);
        
        // Pattern: {if:condition}content{/if}
        $pattern = '/\{if:([^}]+)\}(.*?)\{\/if\}/s';
        $content = preg_replace_callback($pattern, array($this, 'process_conditional_simple'), $content);
        
        return $content;
    }
    
    /**
     * Process conditional with else clause
     */
    private function process_conditional_with_else($matches) {
        $condition = $matches[1];
        $true_content = $matches[2];
        $false_content = $matches[3];
        
        if ($this->evaluate_condition($condition)) {
            return $true_content;
        } else {
            return $false_content;
        }
    }
    
    /**
     * Process simple conditional
     */
    private function process_conditional_simple($matches) {
        $condition = $matches[1];
        $content = $matches[2];
        
        if ($this->evaluate_condition($condition)) {
            return $content;
        } else {
            return '';
        }
    }
    
    /**
     * Evaluate condition
     */
    private function evaluate_condition($condition) {
        switch ($condition) {
            case 'user_logged_in':
                return is_user_logged_in();
                
            case 'user_not_logged_in':
                return !is_user_logged_in();
                
            case 'is_admin':
                return current_user_can('manage_options');
                
            case 'is_mobile':
                return wp_is_mobile();
                
            case 'is_front_page':
                return is_front_page();
                
            case 'is_home':
                return is_home();
                
            case 'is_single':
                return is_single();
                
            case 'is_page':
                return is_page();
                
            default:
                // Check for user role conditions
                if (strpos($condition, 'user_role:') === 0) {
                    $role = substr($condition, 10);
                    return current_user_can($role);
                }
                
                // Allow custom conditions via filter
                return apply_filters('dt_evaluate_condition', false, $condition);
        }
    }
    
    /**
     * Process date format placeholders
     */
    private function process_date_formats($content) {
        // Pattern: {date:format}
        $pattern = '/\{date:([^}]+)\}/';
        $content = preg_replace_callback($pattern, function($matches) {
            $format = $matches[1];
            return wp_date($format);
        }, $content);
        
        return $content;
    }
    
    // User placeholder methods
    private function get_user_name() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return $user->user_login;
    }
    
    private function get_user_email() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return $user->user_email;
    }
    
    private function get_user_role() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return implode(', ', $user->roles);
    }
    
    private function get_user_display_name() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return $user->display_name;
    }
    
    private function get_user_first_name() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return get_user_meta($user->ID, 'first_name', true);
    }
    
    private function get_user_last_name() {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        return get_user_meta($user->ID, 'last_name', true);
    }
    
    // Date/Time placeholder methods
    private function get_current_year() {
        return wp_date('Y');
    }
    
    private function get_current_month() {
        return wp_date('F');
    }
    
    private function get_current_day() {
        return wp_date('j');
    }
    
    private function get_current_date() {
        return wp_date(get_option('date_format'));
    }
    
    private function get_current_time() {
        return wp_date(get_option('time_format'));
    }
    
    private function get_current_datetime() {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'));
    }
    
    // Site placeholder methods
    private function get_site_name() {
        return get_bloginfo('name');
    }
    
    private function get_site_url() {
        return get_site_url();
    }
    
    private function get_site_description() {
        return get_bloginfo('description');
    }
    
    private function get_admin_email() {
        return get_option('admin_email');
    }
    
    private function get_site_language() {
        return get_locale();
    }
    
    private function get_site_charset() {
        return get_bloginfo('charset');
    }
    
    // Post placeholder methods
    private function get_post_title() {
        global $post;
        return $post ? get_the_title($post) : '';
    }
    
    private function get_post_author() {
        global $post;
        if (!$post) return '';
        $author = get_userdata($post->post_author);
        return $author ? $author->display_name : '';
    }
    
    private function get_post_date() {
        global $post;
        return $post ? get_the_date('', $post) : '';
    }
    
    private function get_post_excerpt() {
        global $post;
        return $post ? get_the_excerpt($post) : '';
    }
    
    private function get_post_url() {
        global $post;
        return $post ? get_permalink($post) : '';
    }

    private function get_post_id() {
        global $post;
        return $post ? $post->ID : '';
    }

    private function get_post_slug() {
        global $post;
        return $post ? $post->post_name : '';
    }

    private function get_post_content() {
        global $post;
        return $post ? $post->post_content : '';
    }

    private function get_post_status() {
        global $post;
        return $post ? get_post_status($post) : '';
    }

    private function get_post_type() {
        global $post;
        return $post ? get_post_type($post) : '';
    }

    private function get_post_modified_date() {
        global $post;
        return $post ? get_the_modified_date('', $post) : '';
    }

    private function get_post_comment_count() {
        global $post;
        return $post ? get_comments_number($post) : '';
    }

    private function get_post_comment_status() {
        global $post;
        return $post ? $post->comment_status : '';
    }

    private function get_post_shortlink() {
        global $post;
        return $post ? wp_get_shortlink($post->ID) : '';
    }

    private function get_post_featured_image() {
        global $post;
        if (!$post) return '';
        $url = get_the_post_thumbnail_url($post, 'full');
        return $url ? $url : '';
    }

    private function get_post_featured_image_id() {
        global $post;
        return $post ? get_post_thumbnail_id($post) : '';
    }

    private function get_post_featured_image_alt() {
        global $post;
        if (!$post) return '';
        $id = get_post_thumbnail_id($post);
        return $id ? get_post_meta($id, '_wp_attachment_image_alt', true) : '';
    }

    private function get_post_featured_image_caption() {
        global $post;
        if (!$post) return '';
        $id = get_post_thumbnail_id($post);
        return $id ? wp_get_attachment_caption($id) : '';
    }

    private function get_post_parent_title() {
        global $post;
        if (!$post || !$post->post_parent) return '';
        return get_the_title($post->post_parent);
    }

    private function get_post_parent_url() {
        global $post;
        if (!$post || !$post->post_parent) return '';
        return get_permalink($post->post_parent);
    }

    // Taxonomy / term placeholder methods (based on the current post's primary category)
    private function get_primary_term() {
        global $post;
        if (!$post) return null;
        $terms = get_the_category($post->ID);
        return !empty($terms) ? $terms[0] : null;
    }

    private function get_term_name() {
        $term = $this->get_primary_term();
        return $term ? $term->name : '';
    }

    private function get_term_id() {
        $term = $this->get_primary_term();
        return $term ? $term->term_id : '';
    }

    private function get_term_slug() {
        $term = $this->get_primary_term();
        return $term ? $term->slug : '';
    }

    private function get_term_description() {
        $term = $this->get_primary_term();
        return $term ? term_description($term->term_id) : '';
    }

    private function get_term_url() {
        $term = $this->get_primary_term();
        return $term ? get_category_link($term->term_id) : '';
    }

    private function get_term_list() {
        global $post;
        if (!$post) return '';
        return implode(', ', wp_list_pluck(get_the_category($post->ID), 'name'));
    }

    private function get_tag_list() {
        global $post;
        if (!$post) return '';
        $tags = get_the_tags($post->ID);
        return $tags ? implode(', ', wp_list_pluck($tags, 'name')) : '';
    }

    // Author placeholder methods (current post's author)
    private function get_author_name() {
        global $post;
        if (!$post) return '';
        $author = get_userdata($post->post_author);
        return $author ? $author->display_name : '';
    }

    private function get_author_id() {
        global $post;
        return $post ? $post->post_author : '';
    }

    private function get_author_email() {
        global $post;
        if (!$post) return '';
        $author = get_userdata($post->post_author);
        return $author ? $author->user_email : '';
    }

    private function get_author_bio() {
        global $post;
        return $post ? get_the_author_meta('description', $post->post_author) : '';
    }

    private function get_author_url() {
        global $post;
        return $post ? get_author_posts_url($post->post_author) : '';
    }

    private function get_author_website() {
        global $post;
        return $post ? get_the_author_meta('url', $post->post_author) : '';
    }

    private function get_author_avatar() {
        global $post;
        return $post ? get_avatar_url($post->post_author) : '';
    }

    // Additional site placeholder methods
    private function get_site_logo() {
        $id = get_theme_mod('custom_logo');
        if (!$id) return '';
        $image = wp_get_attachment_image_src($id, 'full');
        return $image ? $image[0] : '';
    }

    private function get_site_icon() {
        return get_site_icon_url();
    }

    private function get_php_version() {
        return PHP_VERSION;
    }

    private function get_site_timezone() {
        return wp_timezone_string();
    }

    // URL / request placeholder methods.
    // Request data is attacker-controlled, so these are escaped for safe HTML output
    // (unlike author-controlled fields above, which the rest of this class doesn't escape).
    private function get_url_current() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        return esc_url(home_url($request_uri));
    }

    private function get_url_path() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        return esc_html($path ? $path : '');
    }

    private function get_url_query_string() {
        $query = isset($_SERVER['QUERY_STRING']) ? wp_unslash($_SERVER['QUERY_STRING']) : '';
        return esc_html($query);
    }

    private function get_url_host() {
        $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '';
        return esc_html($host);
    }

    private function get_url_referrer() {
        $referrer = isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';
        return esc_url($referrer);
    }

    private function get_url_user_agent() {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        return esc_html($agent);
    }

    // WordPress info methods
    private function get_wp_version() {
        global $wp_version;
        return $wp_version;
    }
    
    private function get_theme_name() {
        $theme = wp_get_theme();
        return $theme->get('Name');
    }
    
    private function get_theme_version() {
        $theme = wp_get_theme();
        return $theme->get('Version');
    }
    
    /**
     * Get available placeholders for documentation
     */
    public function get_available_placeholders() {
        $placeholders = array(
            'user' => array(
                'user_name' => __('Current user login name', 'wp-dynamic-tags'),
                'user_email' => __('Current user email address', 'wp-dynamic-tags'),
                'user_display_name' => __('Current user display name', 'wp-dynamic-tags'),
                'user_first_name' => __('Current user first name', 'wp-dynamic-tags'),
                'user_last_name' => __('Current user last name', 'wp-dynamic-tags'),
                'user_role' => __('Current user role(s)', 'wp-dynamic-tags'),
            ),
            'date_time' => array(
                'current_year' => __('Current year (e.g., 2024)', 'wp-dynamic-tags'),
                'current_month' => __('Current month name (e.g., January)', 'wp-dynamic-tags'),
                'current_day' => __('Current day of month (e.g., 15)', 'wp-dynamic-tags'),
                'current_date' => __('Current date in site format', 'wp-dynamic-tags'),
                'current_time' => __('Current time in site format', 'wp-dynamic-tags'),
                'current_datetime' => __('Current date and time', 'wp-dynamic-tags'),
            ),
            'site' => array(
                'site_name' => __('Site title', 'wp-dynamic-tags'),
                'site_url' => __('Site URL', 'wp-dynamic-tags'),
                'site_description' => __('Site tagline/description', 'wp-dynamic-tags'),
                'admin_email' => __('Site admin email', 'wp-dynamic-tags'),
                'site_language' => __('Site language code', 'wp-dynamic-tags'),
            ),
            'post' => array(
                'post_title' => __('Current post title', 'wp-dynamic-tags'),
                'post_author' => __('Current post author name', 'wp-dynamic-tags'),
                'post_date' => __('Current post publication date', 'wp-dynamic-tags'),
                'post_excerpt' => __('Current post excerpt', 'wp-dynamic-tags'),
                'post_url' => __('Current post URL', 'wp-dynamic-tags'),
                'post_id' => __('Current post ID', 'wp-dynamic-tags'),
                'post_slug' => __('Current post slug', 'wp-dynamic-tags'),
                'post_content' => __('Current post content', 'wp-dynamic-tags'),
                'post_status' => __('Current post status', 'wp-dynamic-tags'),
                'post_type' => __('Current post type', 'wp-dynamic-tags'),
                'post_modified_date' => __('Current post last-modified date', 'wp-dynamic-tags'),
                'post_comment_count' => __('Current post comment count', 'wp-dynamic-tags'),
                'post_comment_status' => __('Current post comment status', 'wp-dynamic-tags'),
                'post_shortlink' => __('Current post shortlink', 'wp-dynamic-tags'),
                'post_featured_image' => __('Current post featured image URL', 'wp-dynamic-tags'),
                'post_featured_image_id' => __('Current post featured image ID', 'wp-dynamic-tags'),
                'post_featured_image_alt' => __('Current post featured image alt text', 'wp-dynamic-tags'),
                'post_featured_image_caption' => __('Current post featured image caption', 'wp-dynamic-tags'),
                'post_parent_title' => __('Current post\'s parent post title', 'wp-dynamic-tags'),
                'post_parent_url' => __('Current post\'s parent post URL', 'wp-dynamic-tags'),
            ),
            'taxonomy' => array(
                'term_name' => __('Current post\'s primary category name', 'wp-dynamic-tags'),
                'term_id' => __('Current post\'s primary category ID', 'wp-dynamic-tags'),
                'term_slug' => __('Current post\'s primary category slug', 'wp-dynamic-tags'),
                'term_description' => __('Current post\'s primary category description', 'wp-dynamic-tags'),
                'term_url' => __('Current post\'s primary category URL', 'wp-dynamic-tags'),
                'term_list' => __('All of the current post\'s categories, comma-separated', 'wp-dynamic-tags'),
                'tag_list' => __('All of the current post\'s tags, comma-separated', 'wp-dynamic-tags'),
            ),
            'author' => array(
                'author_name' => __('Current post author\'s display name', 'wp-dynamic-tags'),
                'author_id' => __('Current post author\'s user ID', 'wp-dynamic-tags'),
                'author_email' => __('Current post author\'s email address', 'wp-dynamic-tags'),
                'author_bio' => __('Current post author\'s biography', 'wp-dynamic-tags'),
                'author_url' => __('Current post author\'s archive URL', 'wp-dynamic-tags'),
                'author_website' => __('Current post author\'s website', 'wp-dynamic-tags'),
                'author_avatar' => __('Current post author\'s avatar URL', 'wp-dynamic-tags'),
            ),
            'url' => array(
                'url_current' => __('Current page URL', 'wp-dynamic-tags'),
                'url_path' => __('Current page path', 'wp-dynamic-tags'),
                'url_query_string' => __('Current query string', 'wp-dynamic-tags'),
                'url_host' => __('Current HTTP host', 'wp-dynamic-tags'),
                'url_referrer' => __('Referring URL', 'wp-dynamic-tags'),
                'url_user_agent' => __('Visitor user agent', 'wp-dynamic-tags'),
            ),
            'wordpress' => array(
                'wp_version' => __('WordPress version', 'wp-dynamic-tags'),
                'theme_name' => __('Active theme name', 'wp-dynamic-tags'),
                'theme_version' => __('Active theme version', 'wp-dynamic-tags'),
                'site_logo' => __('Site logo URL', 'wp-dynamic-tags'),
                'site_icon' => __('Site icon URL', 'wp-dynamic-tags'),
                'php_version' => __('PHP version', 'wp-dynamic-tags'),
                'site_timezone' => __('Site timezone', 'wp-dynamic-tags'),
            ),
            'conditional' => array(
                'if_logged_in' => __('Conditional content for logged-in users', 'wp-dynamic-tags'),
                'if_user_role:admin' => __('Conditional content for specific user role', 'wp-dynamic-tags'),
                'if_mobile' => __('Conditional content for mobile devices', 'wp-dynamic-tags'),
                'if_front_page' => __('Conditional content for front page', 'wp-dynamic-tags'),
            ),
            'meta_and_fields' => array(
                'meta:key' => __('Post meta value for the given key, e.g. {meta:price}', 'wp-dynamic-tags'),
                'acf:field' => __('ACF field value for the given field name, e.g. {acf:subtitle} (requires ACF)', 'wp-dynamic-tags'),
            ),
            'advanced' => array(
                'date:Y-m-d' => __('Custom date format (replace Y-m-d with desired format)', 'wp-dynamic-tags'),
                'token|formatter' => __('Apply a formatter, e.g. {post_title|upper} or {meta:price|currency}', 'wp-dynamic-tags'),
                'token??fallback' => __('Fallback text shown when the token resolves empty, e.g. {meta:subtitle??Coming soon}', 'wp-dynamic-tags'),
            )
        );
        
        return apply_filters('dt_available_placeholders', $placeholders);
    }
    
    /**
     * Get placeholder examples
     */
    public function get_placeholder_examples() {
        return array(
            'simple' => array(
                'Welcome, {user_display_name}!' => __('Personal greeting with user name', 'wp-dynamic-tags'),
                'Copyright {current_year} {site_name}' => __('Dynamic copyright notice', 'wp-dynamic-tags'),
                'Contact us at {admin_email}' => __('Site contact information', 'wp-dynamic-tags'),
                'Published on {post_date} by {post_author}' => __('Post metadata', 'wp-dynamic-tags'),
            ),
            'conditional' => array(
                '{if:user_logged_in}Welcome back!{else}Please log in{/if}' => __('Different content based on login status', 'wp-dynamic-tags'),
                '{if:user_role:administrator}Admin Panel{/if}' => __('Content only for specific user roles', 'wp-dynamic-tags'),
                '{if:is_mobile}Mobile version{else}Desktop version{/if}' => __('Device-specific content', 'wp-dynamic-tags'),
            ),
            'advanced' => array(
                'Today is {date:l, F j, Y}' => __('Custom date format', 'wp-dynamic-tags'),
                '{if:is_front_page}{site_description}{else}{post_title}{/if}' => __('Complex conditional logic', 'wp-dynamic-tags'),
            )
        );
    }
}