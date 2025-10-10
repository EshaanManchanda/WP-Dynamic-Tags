<?php
/**
 * Table Manager for WP Dynamic Tags
 *
 * High-level interface for managing dynamic tags in custom table
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Table_Manager {

    private static $instance = null;
    private $db_manager;
    private $migration_manager;
    private $cache = array();
    private $cache_expiry = 3600; // 1 hour

    public function __construct() {
        $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();
        $this->migration_manager = WP_Dynamic_Tags_Migration_Manager::get_instance();

        // Hook into WordPress actions
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_dt_table_create_tag', array($this, 'ajax_create_tag'));
        add_action('wp_ajax_dt_table_update_tag', array($this, 'ajax_update_tag'));
        add_action('wp_ajax_dt_table_delete_tag', array($this, 'ajax_delete_tag'));
        add_action('wp_ajax_dt_table_get_tags', array($this, 'ajax_get_tags'));
        add_action('wp_ajax_dt_table_bulk_action', array($this, 'ajax_bulk_action'));
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
     * Initialize the table manager
     */
    public function init() {
        // Ensure table exists
        if (!$this->db_manager->table_exists()) {
            $result = $this->db_manager->create_table();
            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags Table Manager: Failed to create table - ' . $result->get_error_message());
                }
                // Store error for admin notice
                update_option('wp_dynamic_tags_table_error', $result->get_error_message());
                return; // Don't proceed if table creation failed
            }
        }

        // Run migration if needed
        if ($this->migration_manager->is_migration_needed()) {
            // Don't run automatically in admin, let user trigger it
            if (!is_admin()) {
                $this->migration_manager->migrate_posts_to_table();
            }
        }
    }

    /**
     * Check if table is available for operations
     */
    private function ensure_table_available() {
        if (!$this->db_manager->table_exists()) {
            return new WP_Error('table_not_available', 'Dynamic tags table is not available. Please check the Table Manager for setup instructions.');
        }
        return true;
    }

    /**
     * Create a new dynamic tag
     */
    public function create_tag($data) {
        // Check table availability first
        $table_check = $this->ensure_table_available();
        if (is_wp_error($table_check)) {
            return $table_check;
        }
        // Validate input
        $validation = $this->validate_tag_data($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Auto-generate shortcode if not provided
        if (empty($data['shortcode'])) {
            $data['shortcode'] = $this->generate_unique_shortcode($data['tag_name']);
        }

        // Set defaults
        $defaults = array(
            'priority' => 0,
            'usage_count' => 0,
            'status' => 'active',
            'meta_data' => array()
        );

        $data = wp_parse_args($data, $defaults);

        // Insert into database
        $result = $this->db_manager->insert_tag($data);

        if (!is_wp_error($result)) {
            // Clear cache
            $this->clear_cache();

            // Create corresponding post if dual mode is enabled
            if ($this->is_dual_mode_enabled()) {
                $this->create_corresponding_post($result, $data);
            }

            // Log the creation
            do_action('wp_dynamic_tags_tag_created', $result, $data);
        }

        return $result;
    }

    /**
     * Update an existing dynamic tag
     */
    public function update_tag($id, $data) {
        // Check table availability first
        $table_check = $this->ensure_table_available();
        if (is_wp_error($table_check)) {
            return $table_check;
        }

        $id = intval($id);
        if ($id <= 0) {
            return new WP_Error('invalid_id', 'Invalid tag ID.');
        }

        // Get existing tag
        $existing_tag = $this->db_manager->get_tag($id);
        if (!$existing_tag) {
            return new WP_Error('tag_not_found', 'Tag not found.');
        }

        // Validate input
        $validation = $this->validate_tag_data($data, $id);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Update in database
        $result = $this->db_manager->update_tag($id, $data);

        if (!is_wp_error($result)) {
            // Clear cache
            $this->clear_cache();

            // Update corresponding post if dual mode is enabled
            if ($this->is_dual_mode_enabled() && $existing_tag->post_id > 0) {
                $this->update_corresponding_post($existing_tag->post_id, $data);
            }

            // Log the update
            do_action('wp_dynamic_tags_tag_updated', $id, $data, $existing_tag);
        }

        return $result;
    }

    /**
     * Delete a dynamic tag
     */
    public function delete_tag($id) {
        $id = intval($id);
        if ($id <= 0) {
            return new WP_Error('invalid_id', 'Invalid tag ID.');
        }

        // Get existing tag for logging
        $existing_tag = $this->db_manager->get_tag($id);
        if (!$existing_tag) {
            return new WP_Error('tag_not_found', 'Tag not found.');
        }

        // Delete from database
        $result = $this->db_manager->delete_tag($id);

        if (!is_wp_error($result)) {
            // Clear cache
            $this->clear_cache();

            // Delete corresponding post if dual mode is enabled
            if ($this->is_dual_mode_enabled() && $existing_tag->post_id > 0) {
                wp_delete_post($existing_tag->post_id, true);
            }

            // Log the deletion
            do_action('wp_dynamic_tags_tag_deleted', $id, $existing_tag);
        }

        return $result;
    }

    /**
     * Get dynamic tags with caching
     */
    public function get_tags($args = array()) {
        // Check table availability first
        $table_check = $this->ensure_table_available();
        if (is_wp_error($table_check)) {
            // Return empty array instead of error for get operations
            return array();
        }

        $cache_key = 'tags_' . md5(serialize($args));

        // Check cache first
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        // Get from database
        $tags = $this->db_manager->get_tags($args);

        // Cache the result
        $this->cache[$cache_key] = $tags;

        return $tags;
    }

    /**
     * Get a single tag by ID with caching
     */
    public function get_tag($id) {
        $cache_key = 'tag_' . $id;

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $tag = $this->db_manager->get_tag($id);

        if ($tag) {
            $this->cache[$cache_key] = $tag;
        }

        return $tag;
    }

    /**
     * Get tag by name with caching
     */
    public function get_tag_by_name($tag_name) {
        $cache_key = 'tag_name_' . $tag_name;

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $tag = $this->db_manager->get_tag_by_name($tag_name);

        if ($tag) {
            $this->cache[$cache_key] = $tag;
        }

        return $tag;
    }

    /**
     * Get tag by shortcode with caching
     */
    public function get_tag_by_shortcode($shortcode) {
        $cache_key = 'tag_shortcode_' . $shortcode;

        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $tag = $this->db_manager->get_tag_by_shortcode($shortcode);

        if ($tag) {
            $this->cache[$cache_key] = $tag;
        }

        return $tag;
    }

    /**
     * Search tags
     */
    public function search_tags($search_term, $args = array()) {
        $args['search'] = $search_term;
        return $this->get_tags($args);
    }

    /**
     * Get tags by category
     */
    public function get_tags_by_category($category_id, $args = array()) {
        $args['category_id'] = $category_id;
        return $this->get_tags($args);
    }

    /**
     * Get popular tags (by usage count)
     */
    public function get_popular_tags($limit = 10) {
        return $this->get_tags(array(
            'orderby' => 'usage_count',
            'order' => 'DESC',
            'limit' => $limit,
            'status' => 'active'
        ));
    }

    /**
     * Get recent tags
     */
    public function get_recent_tags($limit = 10) {
        return $this->get_tags(array(
            'orderby' => 'created_date',
            'order' => 'DESC',
            'limit' => $limit,
            'status' => 'active'
        ));
    }

    /**
     * Bulk operations on tags
     */
    public function bulk_action($action, $tag_ids, $data = array()) {
        $tag_ids = array_map('intval', (array) $tag_ids);
        $results = array();

        foreach ($tag_ids as $tag_id) {
            switch ($action) {
                case 'delete':
                    $results[$tag_id] = $this->delete_tag($tag_id);
                    break;

                case 'activate':
                    $results[$tag_id] = $this->update_tag($tag_id, array('status' => 'active'));
                    break;

                case 'deactivate':
                    $results[$tag_id] = $this->update_tag($tag_id, array('status' => 'inactive'));
                    break;

                case 'update_category':
                    if (isset($data['category_id'])) {
                        $results[$tag_id] = $this->update_tag($tag_id, array('category_id' => $data['category_id']));
                    }
                    break;

                case 'update_priority':
                    if (isset($data['priority'])) {
                        $results[$tag_id] = $this->update_tag($tag_id, array('priority' => $data['priority']));
                    }
                    break;

                default:
                    $results[$tag_id] = new WP_Error('invalid_action', 'Invalid bulk action.');
            }
        }

        return $results;
    }

    /**
     * Increment usage count for a tag
     */
    public function track_usage($tag_id) {
        $result = $this->db_manager->increment_usage_count($tag_id);

        if ($result) {
            // Clear cache for this tag
            $this->clear_tag_cache($tag_id);

            // Log usage
            do_action('wp_dynamic_tags_tag_used', $tag_id);
        }

        return $result;
    }

    /**
     * Validate tag data
     */
    private function validate_tag_data($data, $exclude_id = 0) {
        $errors = array();

        // Required fields
        if (empty($data['tag_name'])) {
            $errors[] = 'Tag name is required.';
        }

        // Validate tag name format
        if (!empty($data['tag_name']) && !preg_match('/^[a-zA-Z0-9_\-\s]+$/', $data['tag_name'])) {
            $errors[] = 'Tag name can only contain letters, numbers, spaces, hyphens and underscores.';
        }

        // Validate shortcode format
        if (!empty($data['shortcode']) && !preg_match('/^[a-zA-Z0-9_\-]+$/', $data['shortcode'])) {
            $errors[] = 'Shortcode can only contain letters, numbers, hyphens and underscores.';
        }

        // Check for duplicates
        if (!empty($data['tag_name']) && $this->db_manager->tag_name_exists($data['tag_name'], $exclude_id)) {
            $errors[] = 'Tag name already exists.';
        }

        if (!empty($data['shortcode']) && $this->db_manager->shortcode_exists($data['shortcode'], $exclude_id)) {
            $errors[] = 'Shortcode already exists.';
        }

        // Validate priority
        if (isset($data['priority']) && (!is_numeric($data['priority']) || $data['priority'] < 0 || $data['priority'] > 100)) {
            $errors[] = 'Priority must be a number between 0 and 100.';
        }

        // Validate status
        if (isset($data['status']) && !in_array($data['status'], array('active', 'inactive'))) {
            $errors[] = 'Status must be either "active" or "inactive".';
        }

        if (!empty($errors)) {
            return new WP_Error('validation_failed', implode(' ', $errors));
        }

        return true;
    }

    /**
     * Generate unique shortcode
     */
    private function generate_unique_shortcode($tag_name) {
        $shortcode = sanitize_title($tag_name);
        $shortcode = str_replace('-', '_', $shortcode);
        $shortcode = preg_replace('/[^a-zA-Z0-9_]/', '', $shortcode);

        // Ensure minimum length
        if (strlen($shortcode) < 3) {
            $shortcode = 'tag_' . $shortcode;
        }

        // Ensure uniqueness
        $counter = 1;
        $original_shortcode = $shortcode;

        while ($this->db_manager->shortcode_exists($shortcode)) {
            $shortcode = $original_shortcode . '_' . $counter;
            $counter++;
        }

        return $shortcode;
    }

    /**
     * Check if dual mode is enabled (both table and posts)
     */
    private function is_dual_mode_enabled() {
        return get_option('wp_dynamic_tags_dual_mode', false);
    }

    /**
     * Create corresponding post for dual mode
     */
    private function create_corresponding_post($tag_id, $data) {
        $post_data = array(
            'post_title' => $data['tag_name'],
            'post_content' => $data['content'],
            'post_excerpt' => $data['description'],
            'post_type' => 'dynamic_tag',
            'post_status' => $data['status'] === 'active' ? 'publish' : 'draft'
        );

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            // Update table record with post_id
            $this->db_manager->update_tag($tag_id, array('post_id' => $post_id));

            // Add meta data
            update_post_meta($post_id, '_dt_tag_name', $data['tag_name']);
            update_post_meta($post_id, '_dt_shortcode', $data['shortcode']);
            update_post_meta($post_id, '_dt_priority', $data['priority']);

            // Set category if specified
            if (!empty($data['category_id'])) {
                wp_set_post_terms($post_id, array($data['category_id']), 'tag_groups');
            }
        }

        return $post_id;
    }

    /**
     * Update corresponding post for dual mode
     */
    private function update_corresponding_post($post_id, $data) {
        $post_data = array('ID' => $post_id);

        if (isset($data['tag_name'])) {
            $post_data['post_title'] = $data['tag_name'];
        }

        if (isset($data['content'])) {
            $post_data['post_content'] = $data['content'];
        }

        if (isset($data['description'])) {
            $post_data['post_excerpt'] = $data['description'];
        }

        if (isset($data['status'])) {
            $post_data['post_status'] = $data['status'] === 'active' ? 'publish' : 'draft';
        }

        if (count($post_data) > 1) {
            wp_update_post($post_data);
        }

        // Update meta data
        if (isset($data['tag_name'])) {
            update_post_meta($post_id, '_dt_tag_name', $data['tag_name']);
        }

        if (isset($data['shortcode'])) {
            update_post_meta($post_id, '_dt_shortcode', $data['shortcode']);
        }

        if (isset($data['priority'])) {
            update_post_meta($post_id, '_dt_priority', $data['priority']);
        }

        // Update category
        if (isset($data['category_id'])) {
            wp_set_post_terms($post_id, array($data['category_id']), 'tag_groups');
        }
    }

    /**
     * Clear all cache
     */
    private function clear_cache() {
        $this->cache = array();
    }

    /**
     * Clear cache for specific tag
     */
    private function clear_tag_cache($tag_id) {
        $keys_to_remove = array();

        foreach ($this->cache as $key => $value) {
            if (strpos($key, 'tag_' . $tag_id) !== false) {
                $keys_to_remove[] = $key;
            }
        }

        foreach ($keys_to_remove as $key) {
            unset($this->cache[$key]);
        }
    }

    /**
     * Get table statistics
     */
    public function get_statistics() {
        return $this->db_manager->get_statistics();
    }

    /**
     * Export tags data
     */
    public function export_tags($format = 'json') {
        $tags = $this->get_tags(array('status' => ''));

        switch ($format) {
            case 'csv':
                return $this->export_to_csv($tags);
            case 'xml':
                return $this->export_to_xml($tags);
            case 'json':
            default:
                return json_encode($tags, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Export to CSV format
     */
    private function export_to_csv($tags) {
        $output = "ID,Tag Name,Shortcode,Content,Description,Category ID,Priority,Usage Count,Status,Created Date,Modified Date\n";

        foreach ($tags as $tag) {
            $row = array(
                $tag->id,
                '"' . str_replace('"', '""', $tag->tag_name) . '"',
                $tag->shortcode,
                '"' . str_replace('"', '""', $tag->content) . '"',
                '"' . str_replace('"', '""', $tag->description) . '"',
                $tag->category_id,
                $tag->priority,
                $tag->usage_count,
                $tag->status,
                $tag->created_date,
                $tag->modified_date
            );

            $output .= implode(',', $row) . "\n";
        }

        return $output;
    }

    /**
     * Export to XML format
     */
    private function export_to_xml($tags) {
        $xml = new SimpleXMLElement('<dynamic_tags/>');

        foreach ($tags as $tag) {
            $tag_element = $xml->addChild('tag');
            $tag_element->addChild('id', $tag->id);
            $tag_element->addChild('tag_name', htmlspecialchars($tag->tag_name));
            $tag_element->addChild('shortcode', $tag->shortcode);
            $tag_element->addChild('content', htmlspecialchars($tag->content));
            $tag_element->addChild('description', htmlspecialchars($tag->description));
            $tag_element->addChild('category_id', $tag->category_id);
            $tag_element->addChild('priority', $tag->priority);
            $tag_element->addChild('usage_count', $tag->usage_count);
            $tag_element->addChild('status', $tag->status);
            $tag_element->addChild('created_date', $tag->created_date);
            $tag_element->addChild('modified_date', $tag->modified_date);
        }

        return $xml->asXML();
    }

    /**
     * AJAX: Create tag
     */
    public function ajax_create_tag() {
        check_ajax_referer('wp_dynamic_tags_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $data = array(
            'tag_name' => sanitize_text_field($_POST['tag_name']),
            'shortcode' => sanitize_text_field($_POST['shortcode']),
            'content' => wp_kses_post($_POST['content']),
            'description' => sanitize_textarea_field($_POST['description']),
            'category_id' => intval($_POST['category_id']),
            'priority' => intval($_POST['priority'])
        );

        $result = $this->create_tag($data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'id' => $result,
            'message' => 'Tag created successfully.'
        ));
    }

    /**
     * AJAX: Update tag
     */
    public function ajax_update_tag() {
        check_ajax_referer('wp_dynamic_tags_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id = intval($_POST['id']);
        $data = array(
            'tag_name' => sanitize_text_field($_POST['tag_name']),
            'shortcode' => sanitize_text_field($_POST['shortcode']),
            'content' => wp_kses_post($_POST['content']),
            'description' => sanitize_textarea_field($_POST['description']),
            'category_id' => intval($_POST['category_id']),
            'priority' => intval($_POST['priority'])
        );

        $result = $this->update_tag($id, $data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Tag updated successfully.'
        ));
    }

    /**
     * AJAX: Delete tag
     */
    public function ajax_delete_tag() {
        check_ajax_referer('wp_dynamic_tags_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $id = intval($_POST['id']);
        $result = $this->delete_tag($id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Tag deleted successfully.'
        ));
    }

    /**
     * AJAX: Get tags
     */
    public function ajax_get_tags() {
        check_ajax_referer('wp_dynamic_tags_nonce', 'nonce');

        $args = array(
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'orderby' => sanitize_text_field($_POST['orderby'] ?? 'priority'),
            'order' => sanitize_text_field($_POST['order'] ?? 'DESC'),
            'limit' => intval($_POST['limit'] ?? 50),
            'offset' => intval($_POST['offset'] ?? 0)
        );

        if (!empty($_POST['search'])) {
            $args['search'] = sanitize_text_field($_POST['search']);
        }

        if (!empty($_POST['category_id'])) {
            $args['category_id'] = intval($_POST['category_id']);
        }

        $tags = $this->get_tags($args);
        $total = $this->db_manager->get_tags_count($args);

        wp_send_json_success(array(
            'tags' => $tags,
            'total' => $total
        ));
    }

    /**
     * AJAX: Bulk action
     */
    public function ajax_bulk_action() {
        check_ajax_referer('wp_dynamic_tags_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $action = sanitize_text_field($_POST['action']);
        $tag_ids = array_map('intval', $_POST['tag_ids']);
        $data = array();

        if (isset($_POST['category_id'])) {
            $data['category_id'] = intval($_POST['category_id']);
        }

        if (isset($_POST['priority'])) {
            $data['priority'] = intval($_POST['priority']);
        }

        $results = $this->bulk_action($action, $tag_ids, $data);

        $success_count = 0;
        $error_count = 0;

        foreach ($results as $result) {
            if (is_wp_error($result)) {
                $error_count++;
            } else {
                $success_count++;
            }
        }

        wp_send_json_success(array(
            'success_count' => $success_count,
            'error_count' => $error_count,
            'message' => sprintf('Bulk action completed: %d successful, %d errors.', $success_count, $error_count)
        ));
    }
}