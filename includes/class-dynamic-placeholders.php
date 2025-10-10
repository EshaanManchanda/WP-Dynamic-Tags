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
    
    public function __construct() {
        $this->register_default_placeholders();
        add_filter('dt_process_value', array($this, 'process_placeholders'), 10, 1);
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
        
        // WordPress info
        $this->register_placeholder('wp_version', array($this, 'get_wp_version'));
        $this->register_placeholder('theme_name', array($this, 'get_theme_name'));
        $this->register_placeholder('theme_version', array($this, 'get_theme_version'));
        
        // Conditional placeholders
        $this->register_placeholder('if_logged_in', array($this, 'process_conditional'));
        $this->register_placeholder('if_user_role', array($this, 'process_conditional'));
        $this->register_placeholder('if_mobile', array($this, 'process_conditional'));
    }
    
    /**
     * Register a placeholder
     */
    public function register_placeholder($key, $callback) {
        $this->placeholders[$key] = $callback;
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
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Processing placeholders in content: " . $content);
        }

        // First, handle simple placeholders with curly braces
        $content = preg_replace_callback('/\{([a-z_]+)\}/', array($this, 'replace_simple_placeholder'), $content);

        // Handle square bracket placeholders (for backward compatibility)
        $original_content = $content;
        $content = preg_replace_callback('/\[([a-z_]+[0-9]*)\]/', array($this, 'replace_square_bracket_placeholder'), $content);

        // Debug logging for square bracket replacements
        if (defined('WP_DEBUG') && WP_DEBUG && $original_content !== $content) {
            error_log("WP Dynamic Tags: Square bracket replacement - Before: " . $original_content . " | After: " . $content);
        }

        // Then handle conditional placeholders
        $content = $this->process_conditionals($content);

        // Handle date format placeholders
        $content = $this->process_date_formats($content);

        return $content;
    }
    
    /**
     * Replace simple placeholder
     */
    private function replace_simple_placeholder($matches) {
        $placeholder = $matches[1];
        
        if (isset($this->placeholders[$placeholder])) {
            $callback = $this->placeholders[$placeholder];
            if (is_callable($callback)) {
                return call_user_func($callback);
            }
        }
        
        // Return original if not found
        return $matches[0];
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
            ),
            'wordpress' => array(
                'wp_version' => __('WordPress version', 'wp-dynamic-tags'),
                'theme_name' => __('Active theme name', 'wp-dynamic-tags'),
                'theme_version' => __('Active theme version', 'wp-dynamic-tags'),
            ),
            'conditional' => array(
                'if_logged_in' => __('Conditional content for logged-in users', 'wp-dynamic-tags'),
                'if_user_role:admin' => __('Conditional content for specific user role', 'wp-dynamic-tags'),
                'if_mobile' => __('Conditional content for mobile devices', 'wp-dynamic-tags'),
                'if_front_page' => __('Conditional content for front page', 'wp-dynamic-tags'),
            ),
            'advanced' => array(
                'date:Y-m-d' => __('Custom date format (replace Y-m-d with desired format)', 'wp-dynamic-tags'),
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