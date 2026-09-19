<?php
/**
 * Post-Table Bridge for WP Dynamic Tags
 *
 * Handles synchronization between post type and custom table
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Post_Table_Bridge {

    private static $instance = null;
    private $post_type = 'dynamic_tag';
    private $taxonomy = 'tag_groups';
    private $table_manager;
    private $db_manager;
    private $sync_enabled = true;

    public function __construct() {
        $this->table_manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();

        // Hook into post operations
        add_action('save_post', array($this, 'sync_post_to_table'), 20, 2);
        add_action('before_delete_post', array($this, 'sync_post_deletion'), 10, 1);
        add_action('set_object_terms', array($this, 'sync_post_terms'), 10, 6);

        // Hook into table operations for reverse sync
        add_action('wp_dynamic_tags_tag_created', array($this, 'sync_table_to_post_create'), 10, 2);
        add_action('wp_dynamic_tags_tag_updated', array($this, 'sync_table_to_post_update'), 10, 3);
        add_action('wp_dynamic_tags_tag_deleted', array($this, 'sync_table_to_post_delete'), 10, 2);

        // Admin settings for sync control
        add_action('admin_init', array($this, 'register_sync_settings'));
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
     * Sync post to table on save
     */
    public function sync_post_to_table($post_id, $post) {
        // Skip if sync is disabled or not our post type
        if (!$this->sync_enabled || $post->post_type !== $this->post_type) {
            return;
        }

        // Skip autosave and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Check if table record exists
        $existing_record = $this->get_table_record_by_post_id($post_id);

        // Get post meta
        $tag_name = get_post_meta($post_id, '_dt_tag_name', true);
        $shortcode = get_post_meta($post_id, '_dt_shortcode', true);
        $priority = get_post_meta($post_id, '_dt_priority', true);
        $usage_count = get_post_meta($post_id, '_dt_usage_count', true);
        $last_used = get_post_meta($post_id, '_dt_last_used', true);

        // Get groups (categories)
        $groups = wp_get_post_terms($post_id, $this->taxonomy, array('fields' => 'ids'));
        $category_id = !empty($groups) ? $groups[0] : 0;

        // Prepare data for table
        $data = array(
            'tag_name' => !empty($tag_name) ? $tag_name : $post->post_title,
            'shortcode' => !empty($shortcode) ? $shortcode : $this->generate_shortcode($post->post_title),
            'content' => $post->post_content,
            'description' => $post->post_excerpt,
            'category_id' => $category_id,
            'priority' => !empty($priority) ? intval($priority) : 0,
            'usage_count' => !empty($usage_count) ? intval($usage_count) : 0,
            'created_date' => $post->post_date,
            'modified_date' => $post->post_modified,
            'last_used_date' => !empty($last_used) ? $last_used : null,
            'status' => $post->post_status === 'publish' ? 'active' : 'inactive',
            'post_id' => $post_id,
            'meta_data' => $this->collect_additional_meta($post_id)
        );

        // Temporarily disable sync to prevent infinite loop
        $this->sync_enabled = false;

        if ($existing_record) {
            // Update existing record
            $result = $this->table_manager->update_tag($existing_record->id, $data);
        } else {
            // Create new record
            $result = $this->table_manager->create_tag($data);
        }

        // Re-enable sync
        $this->sync_enabled = true;

        // Log any errors
        if (is_wp_error($result)) {
            error_log('WP Dynamic Tags Bridge Error: ' . $result->get_error_message());
        }
    }

    /**
     * Sync post deletion to table
     */
    public function sync_post_deletion($post_id) {
        if (!$this->sync_enabled) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== $this->post_type) {
            return;
        }

        // Find corresponding table record
        $table_record = $this->get_table_record_by_post_id($post_id);

        if ($table_record) {
            // Temporarily disable sync
            $this->sync_enabled = false;

            // Delete from table or mark as inactive based on settings
            if (get_option('wp_dynamic_tags_hard_delete_on_post_delete', false)) {
                $this->table_manager->delete_tag($table_record->id);
            } else {
                $this->table_manager->update_tag($table_record->id, array(
                    'status' => 'inactive',
                    'post_id' => 0
                ));
            }

            // Re-enable sync
            $this->sync_enabled = true;
        }
    }

    /**
     * Sync post terms changes
     */
    public function sync_post_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if (!$this->sync_enabled || $taxonomy !== $this->taxonomy) {
            return;
        }

        $post = get_post($object_id);
        if (!$post || $post->post_type !== $this->post_type) {
            return;
        }

        // Find table record
        $table_record = $this->get_table_record_by_post_id($object_id);
        if ($table_record) {
            $category_id = !empty($terms) ? $terms[0] : 0;

            // Temporarily disable sync
            $this->sync_enabled = false;

            $this->table_manager->update_tag($table_record->id, array(
                'category_id' => $category_id
            ));

            // Re-enable sync
            $this->sync_enabled = true;
        }
    }

    /**
     * Sync table record creation to post
     */
    public function sync_table_to_post_create($tag_id, $data) {
        if (!$this->sync_enabled || !empty($data['post_id'])) {
            return; // Already has a post or sync disabled
        }

        // Create corresponding post
        $post_data = array(
            'post_title' => $data['tag_name'],
            'post_content' => $data['content'],
            'post_excerpt' => $data['description'] ?? '',
            'post_type' => $this->post_type,
            'post_status' => $data['status'] === 'active' ? 'publish' : 'draft',
            'post_date' => isset($data['created_date']) ? $data['created_date'] : current_time('mysql'),
            'post_modified' => isset($data['modified_date']) ? $data['modified_date'] : current_time('mysql')
        );

        // Temporarily disable sync
        $this->sync_enabled = false;

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            // Update table record with post_id
            $this->table_manager->update_tag($tag_id, array('post_id' => $post_id));

            // Add meta data
            update_post_meta($post_id, '_dt_tag_name', $data['tag_name']);
            update_post_meta($post_id, '_dt_shortcode', $data['shortcode']);
            update_post_meta($post_id, '_dt_priority', $data['priority']);
            update_post_meta($post_id, '_dt_usage_count', $data['usage_count']);

            if (!empty($data['last_used_date'])) {
                update_post_meta($post_id, '_dt_last_used', $data['last_used_date']);
            }

            // Set category
            if (!empty($data['category_id'])) {
                wp_set_post_terms($post_id, array($data['category_id']), $this->taxonomy);
            }

            // Restore additional meta data
            if (!empty($data['meta_data']) && is_array($data['meta_data'])) {
                foreach ($data['meta_data'] as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }
            }
        }

        // Re-enable sync
        $this->sync_enabled = true;
    }

    /**
     * Sync table record update to post
     */
    public function sync_table_to_post_update($tag_id, $data, $old_data) {
        if (!$this->sync_enabled || empty($old_data->post_id)) {
            return;
        }

        $post_id = $old_data->post_id;
        $post = get_post($post_id);

        if (!$post) {
            return;
        }

        // Prepare post update data
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

        // Temporarily disable sync
        $this->sync_enabled = false;

        // Update post if there are changes
        if (count($post_data) > 1) {
            wp_update_post($post_data);
        }

        // Update meta
        if (isset($data['tag_name'])) {
            update_post_meta($post_id, '_dt_tag_name', $data['tag_name']);
        }

        if (isset($data['shortcode'])) {
            update_post_meta($post_id, '_dt_shortcode', $data['shortcode']);
        }

        if (isset($data['priority'])) {
            update_post_meta($post_id, '_dt_priority', $data['priority']);
        }

        if (isset($data['usage_count'])) {
            update_post_meta($post_id, '_dt_usage_count', $data['usage_count']);
        }

        if (isset($data['last_used_date'])) {
            update_post_meta($post_id, '_dt_last_used', $data['last_used_date']);
        }

        // Update category
        if (isset($data['category_id'])) {
            wp_set_post_terms($post_id, array($data['category_id']), $this->taxonomy);
        }

        // Re-enable sync
        $this->sync_enabled = true;
    }

    /**
     * Sync table record deletion to post
     */
    public function sync_table_to_post_delete($tag_id, $old_data) {
        if (!$this->sync_enabled || empty($old_data->post_id)) {
            return;
        }

        // Temporarily disable sync
        $this->sync_enabled = false;

        // Delete post or mark as draft based on settings
        if (get_option('wp_dynamic_tags_hard_delete_on_table_delete', false)) {
            wp_delete_post($old_data->post_id, true);
        } else {
            wp_update_post(array(
                'ID' => $old_data->post_id,
                'post_status' => 'draft'
            ));
        }

        // Re-enable sync
        $this->sync_enabled = true;
    }

    /**
     * Get table record by post ID
     */
    private function get_table_record_by_post_id($post_id) {
        global $wpdb;
        $table_name = $this->db_manager->get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE post_id = %d", $post_id)
        );
    }

    /**
     * Generate shortcode from title
     */
    private function generate_shortcode($title) {
        $shortcode = sanitize_title($title);
        $shortcode = str_replace('-', '_', $shortcode);
        $shortcode = preg_replace('/[^a-zA-Z0-9_]/', '', $shortcode);

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
     * Collect additional post meta
     */
    private function collect_additional_meta($post_id) {
        $meta_data = array();
        $all_meta = get_post_meta($post_id);

        // Exclude keys that are already mapped to table columns
        $exclude_keys = array(
            '_dt_tag_name', '_dt_shortcode', '_dt_priority',
            '_dt_usage_count', '_dt_last_used', '_edit_lock', '_edit_last'
        );

        foreach ($all_meta as $key => $value) {
            if (!in_array($key, $exclude_keys) && strpos($key, '_') === 0) {
                $meta_data[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
            }
        }

        return $meta_data;
    }

    /**
     * Enable/disable sync temporarily
     */
    public function set_sync_enabled($enabled) {
        $this->sync_enabled = $enabled;
    }

    /**
     * Check if sync is enabled
     */
    public function is_sync_enabled() {
        return $this->sync_enabled;
    }

    /**
     * Force sync all posts to table
     */
    public function force_sync_posts_to_table() {
        $posts = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => -1
        ));

        $synced = 0;
        $errors = 0;

        foreach ($posts as $post) {
            try {
                $this->sync_post_to_table($post->ID, $post);
                $synced++;
            } catch (Exception $e) {
                $errors++;
                error_log('Sync error for post ' . $post->ID . ': ' . $e->getMessage());
            }
        }

        return array(
            'synced' => $synced,
            'errors' => $errors
        );
    }

    /**
     * Force sync all table records to posts
     */
    public function force_sync_table_to_posts() {
        $tags = $this->table_manager->get_tags(array('status' => ''));
        $synced = 0;
        $errors = 0;

        foreach ($tags as $tag) {
            try {
                if (empty($tag->post_id) || !get_post($tag->post_id)) {
                    $this->sync_table_to_post_create($tag->id, (array) $tag);
                    $synced++;
                }
            } catch (Exception $e) {
                $errors++;
                error_log('Sync error for tag ' . $tag->id . ': ' . $e->getMessage());
            }
        }

        return array(
            'synced' => $synced,
            'errors' => $errors
        );
    }

    /**
     * Register sync settings
     */
    public function register_sync_settings() {
        register_setting('wp_dynamic_tags_sync_settings', 'wp_dynamic_tags_hard_delete_on_post_delete');
        register_setting('wp_dynamic_tags_sync_settings', 'wp_dynamic_tags_hard_delete_on_table_delete');
        register_setting('wp_dynamic_tags_sync_settings', 'wp_dynamic_tags_auto_sync_enabled');
    }

    /**
     * Get sync statistics
     */
    public function get_sync_statistics() {
        global $wpdb;

        $stats = array();

        // Count posts with corresponding table records
        $table_name = $this->db_manager->get_table_name();

        $posts_with_table_records = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$table_name} t ON p.ID = t.post_id
            WHERE p.post_type = %s
        ", $this->post_type));

        // Count posts without table records
        $total_posts = wp_count_posts($this->post_type);
        $total_published_posts = $total_posts->publish;
        $posts_without_table_records = $total_published_posts - $posts_with_table_records;

        // Count table records without posts
        $table_records_without_posts = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table_name} t
            LEFT JOIN {$wpdb->posts} p ON t.post_id = p.ID
            WHERE t.post_id = 0 OR p.ID IS NULL
        ");

        $stats['posts_with_table_records'] = (int) $posts_with_table_records;
        $stats['posts_without_table_records'] = (int) $posts_without_table_records;
        $stats['table_records_without_posts'] = (int) $table_records_without_posts;
        $stats['sync_enabled'] = $this->sync_enabled;
        $stats['total_posts'] = (int) $total_published_posts;
        $stats['total_table_records'] = $this->table_manager->get_statistics()['total_tags'];

        return $stats;
    }
}