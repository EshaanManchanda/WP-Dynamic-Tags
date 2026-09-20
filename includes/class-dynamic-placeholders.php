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

    /**
     * Developer-registered functions for {func:name}/{func:name:arg} - kept
     * as its own registry, separate from $parameterized_placeholders, because
     * it's a deliberate escape hatch: only names a developer explicitly
     * exposes via register_dynamic_tag_function() are callable from tag
     * content. There is no eval() of tag-authored code anywhere in this
     * class - a content editor can never define new executable logic, only
     * invoke logic a developer already wrote and chose to expose by name.
     */
    private $allowed_functions = array();

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
        // {user_meta:key} - post author's user meta; {term_meta:key} - primary term's term meta
        $this->register_parameterized_placeholder('user_meta', array($this, 'get_user_meta_value'));
        $this->register_parameterized_placeholder('term_meta', array($this, 'get_term_meta_value'));
        // {query:post_type=product&posts_per_page=5&...} - reusable query data source (array of post rows)
        $this->register_parameterized_placeholder('query', array($this, 'get_query_value'));
        // {api:https://example.com/data.json::path.to.field} - external JSON data source
        if (apply_filters('dt_enable_api_placeholder', true)) {
            $this->register_parameterized_placeholder('api', array($this, 'get_api_value'));
        }
        // {func:name} / {func:name:arg} - whitelisted developer functions (see register_dynamic_tag_function())
        $this->register_parameterized_placeholder('func', array($this, 'get_func_value'));
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
     * Register a function callable from tag content as {func:name} or
     * {func:name:arg}. This is the plugin's PHP/custom-logic escape hatch -
     * deliberately NOT an eval() of tag-authored text. A content editor can
     * only invoke a function a developer already wrote and chose to expose
     * by name; they can never define new executable logic from a text field.
     * Same registration convention as register_external_placeholder().
     *
     * Example (in a theme's functions.php or a custom plugin):
     *   WP_Dynamic_Tags_Placeholders::register_dynamic_tag_function(
     *       'shipping_estimate',
     *       function ($arg) { return calculate_shipping_days($arg); }
     *   );
     *   // In a Dynamic Tag's content: {func:shipping_estimate:express}
     *
     * @param string   $name     Function name (used as {func:name} in content).
     * @param callable $callback Receives the optional arg string (or null), returns a value.
     */
    public static function register_dynamic_tag_function($name, $callback) {
        add_action('init', function() use ($name, $callback) {
            if (!class_exists('WP_Dynamic_Tags_Placeholders')
                || !method_exists('WP_Dynamic_Tags_Placeholders', 'get_instance')) {
                return;
            }
            $instance = WP_Dynamic_Tags_Placeholders::get_instance();
            if ($instance && method_exists($instance, 'register_allowed_function')) {
                $instance->register_allowed_function($name, $callback);
            }
        }, 25);
    }

    /**
     * Add a function to the {func:...} whitelist. Not called directly by
     * plugin code outside of register_dynamic_tag_function()'s hook.
     */
    public function register_allowed_function($name, $callback) {
        $this->allowed_functions[$name] = $callback;
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
        $formatters = array_map(array(__CLASS__, 'trim_formatter_spec'), $parts);

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
     * Trim only a formatter spec's NAME (before the colon), never its argument
     * ("upper " -> "upper", but "join:; " keeps its trailing space intact).
     * This tolerates a token authored with spaces around "|" (e.g.
     * {token | upper}) without destroying a semantically whitespace-sensitive
     * argument like |join's separator. Pure function — no WordPress calls.
     */
    public static function trim_formatter_spec($spec) {
        $colon_pos = strpos($spec, ':');
        if ($colon_pos === false) {
            return trim($spec);
        }
        return trim(substr($spec, 0, $colon_pos)) . ':' . substr($spec, $colon_pos + 1);
    }

    /**
     * Whether a resolved value counts as "empty" for fallback purposes:
     * empty string/null, or an empty array (a getter returning no rows).
     * Pure function — no WordPress calls.
     */
    public static function is_empty_value($value) {
        if ($value === '' || $value === null) {
            return true;
        }
        return is_array($value) && empty($value);
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
        if (self::is_empty_value($value) && $parsed['fallback'] !== null) {
            return $parsed['fallback'];
        }

        if (class_exists('WP_Dynamic_Tags_Formatters')) {
            foreach ($parsed['formatters'] as $formatter) {
                if ($formatter === '') {
                    continue;
                }
                // Skip formatters that don't understand arrays until one collapses
                // the value to a scalar (e.g. {acf:repeater|first|upper}: "first"
                // collapses it, then "upper" applies normally on the next iteration).
                if (is_array($value)) {
                    $formatter_name = strtok($formatter, ':');
                    if (!WP_Dynamic_Tags_Formatters::is_array_aware($formatter_name)) {
                        continue;
                    }
                }
                $value = WP_Dynamic_Tags_Formatters::format($value, $formatter);
            }

            // Safety net: a value that is still an array at this point (no
            // array-collapsing formatter was used) must never render as the
            // literal string "Array" — collapse it the same way |join would.
            if (is_array($value)) {
                $value = WP_Dynamic_Tags_Formatters::format($value, 'join');
            }
        } elseif (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return $value;
    }

    /**
     * Resolve a parsed key/arg pair against the flat or parameterized registries.
     *
     * @return string|array|false The resolved value, or false if the key is unregistered.
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
     * Public façade over resolve_token() for consumers (e.g. [dt_loop]) that need
     * the raw resolved value — including a real array — without the array-safety-net
     * stringification that replace_generic_token() applies for direct {}-embedding.
     *
     * @return string|array|false
     */
    public function resolve_token_raw($key, $arg) {
        return $this->resolve_token($key, $arg);
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
     * Get the post author's user meta for the {user_meta:key} placeholder.
     * Reads the current record's author (extends {author:*}), not the viewer.
     */
    private function get_user_meta_value($key) {
        global $post;
        if (!$post) {
            return '';
        }
        return (string) get_user_meta($post->post_author, $key, true);
    }

    /**
     * Get the post's primary term's term meta for the {term_meta:key} placeholder.
     */
    private function get_term_meta_value($key) {
        $term = $this->get_primary_term();
        if (!$term) {
            return '';
        }
        return (string) get_term_meta($term->term_id, $key, true);
    }

    /**
     * Get a query-driven array of post rows for {query:...} - a reusable data
     * source usable directly with [dt_loop source="query:..."]{item:title}[/dt_loop].
     * $query_string is a WP_Query-args querystring (wp_parse_str), the same
     * convention [dt_template]'s `query` attribute uses.
     */
    private function get_query_value($query_string) {
        $query_args = array(
            'post_type' => 'post',
            'posts_per_page' => 5,
            'post_status' => 'publish',
        );
        if (!empty($query_string)) {
            wp_parse_str($query_string, $parsed_query);
            $query_args = array_merge($query_args, $parsed_query);
        }

        $query = new WP_Query($query_args);
        $rows = array();
        foreach ($query->posts as $queried_post) {
            $rows[] = array(
                'id' => $queried_post->ID,
                'title' => get_the_title($queried_post),
                'permalink' => get_permalink($queried_post),
                'excerpt' => get_the_excerpt($queried_post),
                'date' => get_the_date('', $queried_post),
                'thumbnail' => get_the_post_thumbnail_url($queried_post) ?: '',
            );
        }
        wp_reset_postdata();

        return $rows;
    }

    /**
     * Get external JSON data for {api:URL::json.path} - fetches URL (cached),
     * decodes JSON, and optionally walks a dot-notation path into the result
     * (same convention as {acf:field.subfield}). With no path, returns the
     * whole decoded payload as an array (usable with [dt_loop]/|join/|count).
     *
     * Uses wp_safe_remote_get() (not wp_remote_get()) specifically because it
     * refuses requests to private/reserved IP ranges - the standard WordPress
     * mitigation against SSRF via a user-suppliable URL. Responses are cached
     * (success and failure) so this can't be used to hammer an external
     * endpoint or a broken one from slowing down every page load.
     */
    private function get_api_value($arg) {
        list($url, $path) = array_pad(explode('::', $arg, 2), 2, null);
        $url = trim($url);
        if (empty($url) || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        $cache_key = 'dt_api_' . md5($url);
        $data = get_transient($cache_key);
        if ($data === false) {
            $response = wp_safe_remote_get($url, array('timeout' => 5));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                set_transient($cache_key, array(), MINUTE_IN_SECONDS);
                return '';
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                $data = array();
            }
            set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        }

        if (empty($data)) {
            return '';
        }

        return self::walk_path($data, $path);
    }

    /**
     * Get a whitelisted function's return value for {func:name} / {func:name:arg}.
     * Only functions a developer explicitly exposed via
     * register_dynamic_tag_function() are callable here - an unrecognized
     * name returns '' rather than erroring, same as every other placeholder.
     */
    private function get_func_value($arg) {
        list($name, $call_arg) = array_pad(explode(':', $arg, 2), 2, null);
        $name = trim($name);

        if (!isset($this->allowed_functions[$name]) || !is_callable($this->allowed_functions[$name])) {
            return '';
        }

        return call_user_func($this->allowed_functions[$name], $call_arg);
    }

    /**
     * Walk a dot-notation path ("data.rates.usd") into a decoded array,
     * returning '' if any segment doesn't exist. Pure function - no
     * WordPress calls - so it can be unit tested directly.
     */
    public static function walk_path($data, $path) {
        if ($path === null || trim($path) === '') {
            return $data;
        }

        $value = $data;
        foreach (explode('.', trim($path)) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return '';
            }
        }
        return $value;
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

                // Field-based conditions: truthy check or comparison against a
                // resolvable token, e.g. {if:acf:featured}, {if:wc:price>50},
                // {if:meta:status=active}. Returns null (not bool) when the
                // condition isn't a resolvable token at all, so a genuinely
                // custom condition name still reaches the filter hook below.
                $field_result = $this->evaluate_field_condition($condition);
                if ($field_result !== null) {
                    return $field_result;
                }

                // Allow custom conditions via filter
                return apply_filters('dt_evaluate_condition', false, $condition);
        }
    }

    /**
     * Public façade over evaluate_condition() for the [dt_if] shortcode.
     */
    public function evaluate_condition_public($condition) {
        return $this->evaluate_condition($condition);
    }

    /**
     * Whether a resolved value should read as "true" in a condition: unlike
     * is_empty_value() (used for {token??fallback}, where "0" is meaningful
     * data), a condition treats "0"/0/false the same as PHP's own empty().
     */
    public static function is_truthy($value) {
        if (is_array($value)) {
            return !empty($value);
        }
        return $value !== '' && $value !== null && $value !== '0' && $value !== 0 && $value !== false;
    }

    /**
     * Split a condition string into its shape: a comparison ("key OP value"),
     * a "key contains value" check, or a bare key/key:arg truthy check.
     * Pure function - no WordPress calls - so it can be unit tested directly.
     *
     * @return array{type: string, key: string, arg: ?string, op: ?string, value: ?string}
     */
    public static function parse_condition_expression($condition) {
        // Accept &lt;/&gt; as aliases for </> : a literal < with no later >
        // inside a [dt_if condition="..."] shortcode attribute value confuses
        // WordPress core's own shortcode-in-HTML-tag detection (do_shortcode()
        // silently fails to match the shortcode at all - a WP core limitation,
        // not something this plugin can fix) - {if:...} tokens aren't affected
        // since they're parsed by this plugin's own regex, not do_shortcode().
        $condition = strtr($condition, array('&lt;' => '<', '&gt;' => '>'));

        // Multi-char operators must be checked before their single-char subset
        // (">=" before ">") or the split would land in the wrong place.
        foreach (array('!=', '>=', '<=', '=', '>', '<') as $op) {
            $pos = strpos($condition, $op);
            if ($pos !== false) {
                return self::split_key_arg(trim(substr($condition, 0, $pos))) + array(
                    'type' => 'comparison',
                    'op' => $op,
                    'value' => trim(substr($condition, $pos + strlen($op))),
                );
            }
        }

        if (preg_match('/^(.+?)\s+contains\s+(.+)$/i', $condition, $m)) {
            return self::split_key_arg(trim($m[1])) + array(
                'type' => 'comparison',
                'op' => 'contains',
                'value' => trim($m[2]),
            );
        }

        return self::split_key_arg(trim($condition)) + array(
            'type' => 'truthy',
            'op' => null,
            'value' => null,
        );
    }

    private static function split_key_arg($key_part) {
        $key = $key_part;
        $arg = null;
        if (strpos($key_part, ':') !== false) {
            list($key, $arg) = explode(':', $key_part, 2);
            $arg = trim($arg);
        }
        return array('key' => trim($key), 'arg' => $arg);
    }

    /**
     * Compare a resolved value against a parsed condition's operator/value.
     * Pure function - no WordPress calls - so it can be unit tested directly.
     *
     * @param string|array $left The already-resolved token value.
     */
    public static function compare_values($left, $op, $value_str) {
        if (is_array($left)) {
            $left = ($op === '=' || $op === '!=' || $op === 'contains')
                ? implode(', ', array_filter($left, 'is_scalar'))
                : count($left);
        }

        switch ($op) {
            case '=':
                return (string) $left === $value_str;
            case '!=':
                return (string) $left !== $value_str;
            case '>':
                return (float) $left > (float) $value_str;
            case '<':
                return (float) $left < (float) $value_str;
            case '>=':
                return (float) $left >= (float) $value_str;
            case '<=':
                return (float) $left <= (float) $value_str;
            case 'contains':
                return stripos((string) $left, $value_str) !== false;
            default:
                return false;
        }
    }

    /**
     * Evaluate a condition as a token reference: a bare key/key:arg for a
     * truthy check, or "key OP value" for a comparison. Returns null (not a
     * bool) when the condition isn't a resolvable token at all, so the caller
     * can fall through to other checks (e.g. the dt_evaluate_condition filter).
     */
    private function evaluate_field_condition($condition) {
        $parsed = self::parse_condition_expression($condition);
        $value = $this->resolve_token($parsed['key'], $parsed['arg']);

        if ($value === false) {
            // Comparisons already committed to being field-based - an
            // unresolvable key just means "no match", not "not a token".
            return $parsed['type'] === 'truthy' ? null : false;
        }

        return $parsed['type'] === 'truthy'
            ? self::is_truthy($value)
            : self::compare_values($value, $parsed['op'], $parsed['value']);
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
                'if:wc:price>50' => __('Condition can also test a field value: truthy check (e.g. {if:acf:featured}) or comparison with =, !=, >, <, >=, <=, contains', 'wp-dynamic-tags'),
                '[dt_if condition="..."][dt_else]' => __('Wrap a whole block of content (not just a token) so it can be hidden/shown by any condition above, e.g. [dt_if condition="wc:price>50"]On sale![dt_else]Regular price[/dt_if]', 'wp-dynamic-tags'),
            ),
            'meta_and_fields' => array(
                'meta:key' => __('Post meta value for the given key, e.g. {meta:price}', 'wp-dynamic-tags'),
                'user_meta:key' => __('Post author\'s user meta value, e.g. {user_meta:phone}', 'wp-dynamic-tags'),
                'term_meta:key' => __('Primary term\'s term meta value, e.g. {term_meta:icon}', 'wp-dynamic-tags'),
                'acf:field' => __('ACF field value; use field.subfield to pull a column out of a repeater/group, e.g. {acf:team_members.name} (requires ACF)', 'wp-dynamic-tags'),
                'wc:field' => __('WooCommerce product field: price, regular_price, sale_price, sale_percent, sku, stock_status, stock_quantity, categories, tags (requires WooCommerce)', 'wp-dynamic-tags'),
                'query:post_type=...&posts_per_page=...' => __('Query posts as an array of rows (id, title, permalink, excerpt, date, thumbnail) for use with [dt_loop]', 'wp-dynamic-tags'),
                'api:https://...::path.to.field' => __('Fetch external JSON and optionally walk a dot-notation path into it, e.g. {api:https://api.example.com/rates::usd|round:2}', 'wp-dynamic-tags'),
                'func:name' => __('Call a developer-registered function (see register_dynamic_tag_function() in code) - not arbitrary PHP, only functions explicitly exposed by name', 'wp-dynamic-tags'),
            ),
            'array' => array(
                'token|join:, ' => __('Join an array value into text with a separator, e.g. {wc:categories|join:", "}', 'wp-dynamic-tags'),
                'token|count' => __('Count the items in an array value, e.g. {acf:gallery|count}', 'wp-dynamic-tags'),
                'token|first' => __('First item of an array value, e.g. {acf:team_members.name|first}', 'wp-dynamic-tags'),
                'token|last' => __('Last item of an array value, e.g. {acf:team_members.name|last}', 'wp-dynamic-tags'),
                '[dt_loop source="..."]{item}[/dt_loop]' => __('Repeat a template once per item of an array source, e.g. [dt_loop source="acf:gallery"]<img src="{item}">[/dt_loop]', 'wp-dynamic-tags'),
            ),
            'advanced' => array(
                'date:Y-m-d' => __('Custom date format (replace Y-m-d with desired format)', 'wp-dynamic-tags'),
                'token|formatter' => __('Apply a formatter, e.g. {post_title|upper} or {meta:price|currency}', 'wp-dynamic-tags'),
                'token??fallback' => __('Fallback text shown when the token resolves empty, e.g. {meta:subtitle??Coming soon}', 'wp-dynamic-tags'),
                '[dt_template tag="..." query="..."]' => __('Render an existing Dynamic Tag once per post in a query, e.g. [dt_template tag="product_card" query="post_type=product&posts_per_page=6"]', 'wp-dynamic-tags'),
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
                '{if:wc:stock_status=instock}In stock{else}Sold out{/if}' => __('Condition based on a field value', 'wp-dynamic-tags'),
                '[dt_if condition="wc:sale_percent>20"]Big sale![dt_else][/dt_if]' => __('Hide/show a whole block of content, not just text inside a tag', 'wp-dynamic-tags'),
            ),
            'advanced' => array(
                'Today is {date:l, F j, Y}' => __('Custom date format', 'wp-dynamic-tags'),
                '{if:is_front_page}{site_description}{else}{post_title}{/if}' => __('Complex conditional logic', 'wp-dynamic-tags'),
            ),
            'array' => array(
                '{acf:team_members.name|join:", "}' => __('Comma-join a repeater column across all rows', 'wp-dynamic-tags'),
                '{wc:price|currency}' => __('Format a WooCommerce product field', 'wp-dynamic-tags'),
                '[dt_loop source="acf:gallery"]<img src="{item}">[/dt_loop]' => __('Render a template once per gallery image', 'wp-dynamic-tags'),
                '[dt_loop source="acf:team_members" separator=", "]{item:name}[/dt_loop]' => __('Render a template once per repeater row', 'wp-dynamic-tags'),
            ),
            'templates' => array(
                '[dt_template tag="product_card" query="post_type=product&posts_per_page=6"]' => __('Render a Dynamic Tag once per product, like a repeating card', 'wp-dynamic-tags'),
                '[dt_template tag="team_member" query="post_type=team&orderby=menu_order&order=ASC"]' => __('Render a Dynamic Tag once per team member, in a custom order', 'wp-dynamic-tags'),
            ),
            'data_sources' => array(
                '[dt_loop source="query:post_type=post&posts_per_page=3"]{item:title}[/dt_loop]' => __('List recent posts without creating a Dynamic Tag first', 'wp-dynamic-tags'),
                '{api:https://api.exchangerate.host/latest::rates.EUR|round:2}' => __('Pull a single value out of an external JSON API', 'wp-dynamic-tags'),
                '{func:shipping_estimate:express}' => __('Call a function a developer registered in code, e.g. WP_Dynamic_Tags_Placeholders::register_dynamic_tag_function()', 'wp-dynamic-tags'),
            )
        );
    }
}