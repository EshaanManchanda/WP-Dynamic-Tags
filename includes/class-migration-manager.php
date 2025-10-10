<?php
/**
 * Migration Manager for WP Dynamic Tags
 *
 * Handles data migration between post type system and custom table
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Migration_Manager {

    private static $instance = null;
    private $post_type = 'dynamic_tag';
    private $taxonomy = 'tag_groups';
    private $db_manager;
    private $migration_log = array();

    public function __construct() {
        $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();
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
     * Check if migration is needed
     */
    public function is_migration_needed() {
        // Check if we have posts but empty table
        $post_count = wp_count_posts($this->post_type)->publish;
        $table_count = $this->db_manager->get_tags_count(array('status' => ''));

        return ($post_count > 0 && $table_count == 0);
    }

    /**
     * Check migration status
     */
    public function get_migration_status() {
        return get_option('wp_dynamic_tags_migration_status', 'not_started');
    }

    /**
     * Set migration status
     */
    private function set_migration_status($status) {
        update_option('wp_dynamic_tags_migration_status', $status);
    }

    /**
     * Get migration log
     */
    public function get_migration_log() {
        return get_option('wp_dynamic_tags_migration_log', array());
    }

    /**
     * Add entry to migration log
     */
    private function log_migration($message, $type = 'info') {
        $this->migration_log[] = array(
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'message' => $message
        );

        // Keep only last 1000 entries
        if (count($this->migration_log) > 1000) {
            $this->migration_log = array_slice($this->migration_log, -1000);
        }

        update_option('wp_dynamic_tags_migration_log', $this->migration_log);
    }

    /**
     * Clear migration log
     */
    public function clear_migration_log() {
        delete_option('wp_dynamic_tags_migration_log');
        $this->migration_log = array();
    }

    /**
     * Full migration from posts to table
     */
    public function migrate_posts_to_table($batch_size = 50) {
        $this->log_migration('Starting migration from posts to table', 'info');
        $this->set_migration_status('in_progress');

        try {
            // Create table if it doesn't exist
            if (!$this->db_manager->table_exists()) {
                $this->db_manager->create_table();
                $this->log_migration('Created dynamic tags table', 'info');
            }

            $offset = 0;
            $total_migrated = 0;
            $errors = 0;

            do {
                // Get batch of posts
                $posts = get_posts(array(
                    'post_type' => $this->post_type,
                    'post_status' => 'publish',
                    'posts_per_page' => $batch_size,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => 'ASC'
                ));

                foreach ($posts as $post) {
                    $result = $this->migrate_single_post($post);
                    if (is_wp_error($result)) {
                        $errors++;
                        $this->log_migration(
                            "Failed to migrate post {$post->ID}: " . $result->get_error_message(),
                            'error'
                        );
                    } else {
                        $total_migrated++;
                    }
                }

                $offset += $batch_size;

            } while (count($posts) === $batch_size);

            // Update migration status
            if ($errors === 0) {
                $this->set_migration_status('completed');
                $this->log_migration(
                    "Migration completed successfully. {$total_migrated} posts migrated.",
                    'success'
                );
            } else {
                $this->set_migration_status('completed_with_errors');
                $this->log_migration(
                    "Migration completed with {$errors} errors. {$total_migrated} posts migrated successfully.",
                    'warning'
                );
            }

            return array(
                'success' => true,
                'migrated' => $total_migrated,
                'errors' => $errors
            );

        } catch (Exception $e) {
            $this->set_migration_status('failed');
            $this->log_migration('Migration failed: ' . $e->getMessage(), 'error');

            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Migrate single post to table
     */
    private function migrate_single_post($post) {
        // Get post meta
        $tag_name = get_post_meta($post->ID, '_dt_tag_name', true);
        $shortcode = get_post_meta($post->ID, '_dt_shortcode', true);
        $priority = get_post_meta($post->ID, '_dt_priority', true);
        $usage_count = get_post_meta($post->ID, '_dt_usage_count', true);
        $last_used = get_post_meta($post->ID, '_dt_last_used', true);

        // Get groups (categories)
        $groups = wp_get_post_terms($post->ID, $this->taxonomy, array('fields' => 'ids'));
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
            'status' => 'active',
            'post_id' => $post->ID,
            'meta_data' => $this->collect_post_meta($post->ID)
        );

        // Insert into table
        $result = $this->db_manager->insert_tag($data);

        if (is_wp_error($result)) {
            return $result;
        }

        // Log successful migration
        $this->log_migration("Migrated post {$post->ID} to table record {$result}", 'info');

        return $result;
    }

    /**
     * Collect all relevant post meta
     */
    private function collect_post_meta($post_id) {
        $meta_data = array();

        // Get all meta
        $all_meta = get_post_meta($post_id);

        // Collect relevant meta (exclude those already mapped to table columns)
        $exclude_keys = array('_dt_tag_name', '_dt_shortcode', '_dt_priority', '_dt_usage_count', '_dt_last_used');

        foreach ($all_meta as $key => $value) {
            if (!in_array($key, $exclude_keys)) {
                $meta_data[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
            }
        }

        return $meta_data;
    }

    /**
     * Generate shortcode from title
     */
    private function generate_shortcode($title) {
        $shortcode = sanitize_title($title);
        $shortcode = str_replace('-', '_', $shortcode);

        // Ensure it doesn't already exist
        $counter = 1;
        $original_shortcode = $shortcode;

        while ($this->db_manager->shortcode_exists($shortcode)) {
            $shortcode = $original_shortcode . '_' . $counter;
            $counter++;
        }

        return $shortcode;
    }

    /**
     * Reverse migration: from table to posts
     */
    public function migrate_table_to_posts($batch_size = 50) {
        $this->log_migration('Starting reverse migration from table to posts', 'info');

        try {
            $offset = 0;
            $total_migrated = 0;
            $errors = 0;

            do {
                // Get batch of table records
                $tags = $this->db_manager->get_tags(array(
                    'status' => '',
                    'limit' => $batch_size,
                    'offset' => $offset,
                    'orderby' => 'id',
                    'order' => 'ASC'
                ));

                foreach ($tags as $tag) {
                    $result = $this->migrate_single_tag_to_post($tag);
                    if (is_wp_error($result)) {
                        $errors++;
                        $this->log_migration(
                            "Failed to migrate tag {$tag->id}: " . $result->get_error_message(),
                            'error'
                        );
                    } else {
                        $total_migrated++;
                    }
                }

                $offset += $batch_size;

            } while (count($tags) === $batch_size);

            $this->log_migration(
                "Reverse migration completed. {$total_migrated} tags migrated, {$errors} errors.",
                $errors === 0 ? 'success' : 'warning'
            );

            return array(
                'success' => true,
                'migrated' => $total_migrated,
                'errors' => $errors
            );

        } catch (Exception $e) {
            $this->log_migration('Reverse migration failed: ' . $e->getMessage(), 'error');

            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Migrate single tag to post
     */
    private function migrate_single_tag_to_post($tag) {
        // Check if post already exists
        if ($tag->post_id > 0 && get_post($tag->post_id)) {
            // Update existing post
            $post_data = array(
                'ID' => $tag->post_id,
                'post_title' => $tag->tag_name,
                'post_content' => $tag->content,
                'post_excerpt' => $tag->description,
                'post_date' => $tag->created_date,
                'post_modified' => $tag->modified_date,
                'post_status' => $tag->status === 'active' ? 'publish' : 'draft'
            );

            $post_id = wp_update_post($post_data);
        } else {
            // Create new post
            $post_data = array(
                'post_title' => $tag->tag_name,
                'post_content' => $tag->content,
                'post_excerpt' => $tag->description,
                'post_type' => $this->post_type,
                'post_status' => $tag->status === 'active' ? 'publish' : 'draft',
                'post_date' => $tag->created_date,
                'post_modified' => $tag->modified_date
            );

            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Update post meta
        update_post_meta($post_id, '_dt_tag_name', $tag->tag_name);
        update_post_meta($post_id, '_dt_shortcode', $tag->shortcode);
        update_post_meta($post_id, '_dt_priority', $tag->priority);
        update_post_meta($post_id, '_dt_usage_count', $tag->usage_count);

        if ($tag->last_used_date) {
            update_post_meta($post_id, '_dt_last_used', $tag->last_used_date);
        }

        // Set category/group
        if ($tag->category_id > 0) {
            wp_set_post_terms($post_id, array($tag->category_id), $this->taxonomy);
        }

        // Restore additional meta data
        if (!empty($tag->meta_data) && is_array($tag->meta_data)) {
            foreach ($tag->meta_data as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }

        // Update table record with post_id if it wasn't set
        if ($tag->post_id != $post_id) {
            $this->db_manager->update_tag($tag->id, array('post_id' => $post_id));
        }

        return $post_id;
    }

    /**
     * Sync data between posts and table
     */
    public function sync_posts_and_table() {
        $this->log_migration('Starting sync between posts and table', 'info');

        $synced = 0;
        $errors = 0;

        // Get all posts
        $posts = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => -1
        ));

        foreach ($posts as $post) {
            // Check if corresponding table record exists
            $existing_tag = $this->db_manager->get_tag_by_name($post->post_title);

            if ($existing_tag) {
                // Update table record
                $data = array(
                    'content' => $post->post_content,
                    'description' => $post->post_excerpt,
                    'modified_date' => $post->post_modified,
                    'status' => $post->post_status === 'publish' ? 'active' : 'inactive',
                    'post_id' => $post->ID
                );

                $result = $this->db_manager->update_tag($existing_tag->id, $data);
                if (!is_wp_error($result)) {
                    $synced++;
                } else {
                    $errors++;
                }
            } else {
                // Create new table record
                $result = $this->migrate_single_post($post);
                if (!is_wp_error($result)) {
                    $synced++;
                } else {
                    $errors++;
                }
            }
        }

        $this->log_migration(
            "Sync completed. {$synced} records synced, {$errors} errors.",
            $errors === 0 ? 'success' : 'warning'
        );

        return array(
            'synced' => $synced,
            'errors' => $errors
        );
    }

    /**
     * Validate data integrity between posts and table
     */
    public function validate_data_integrity() {
        $issues = array();

        // Check for posts without table records
        $posts = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1
        ));

        $posts_without_table_records = 0;
        foreach ($posts as $post) {
            $table_record = $this->db_manager->get_tag_by_name($post->post_title);
            if (!$table_record) {
                $posts_without_table_records++;
            }
        }

        if ($posts_without_table_records > 0) {
            $issues[] = "{$posts_without_table_records} posts found without corresponding table records";
        }

        // Check for table records without posts
        $tags = $this->db_manager->get_tags(array('status' => ''));
        $table_records_without_posts = 0;

        foreach ($tags as $tag) {
            if ($tag->post_id > 0) {
                $post = get_post($tag->post_id);
                if (!$post || $post->post_type !== $this->post_type) {
                    $table_records_without_posts++;
                }
            } else {
                $table_records_without_posts++;
            }
        }

        if ($table_records_without_posts > 0) {
            $issues[] = "{$table_records_without_posts} table records found without corresponding posts";
        }

        // Check for duplicate shortcodes
        $duplicate_shortcodes = $this->find_duplicate_shortcodes();
        if (!empty($duplicate_shortcodes)) {
            $issues[] = count($duplicate_shortcodes) . " duplicate shortcodes found";
        }

        return $issues;
    }

    /**
     * Find duplicate shortcodes
     */
    private function find_duplicate_shortcodes() {
        global $wpdb;
        $table_name = $this->db_manager->get_table_name();

        $duplicates = $wpdb->get_results(
            "SELECT shortcode, COUNT(*) as count
             FROM {$table_name}
             GROUP BY shortcode
             HAVING count > 1"
        );

        return $duplicates;
    }

    /**
     * Fix duplicate shortcodes
     */
    public function fix_duplicate_shortcodes() {
        $duplicates = $this->find_duplicate_shortcodes();
        $fixed = 0;

        foreach ($duplicates as $duplicate) {
            $tags = $this->db_manager->get_tags(array(
                'status' => '',
                'orderby' => 'id',
                'order' => 'ASC'
            ));

            $counter = 1;
            foreach ($tags as $tag) {
                if ($tag->shortcode === $duplicate->shortcode && $counter > 1) {
                    $new_shortcode = $duplicate->shortcode . '_' . $counter;

                    // Make sure the new shortcode doesn't exist
                    while ($this->db_manager->shortcode_exists($new_shortcode)) {
                        $counter++;
                        $new_shortcode = $duplicate->shortcode . '_' . $counter;
                    }

                    $result = $this->db_manager->update_tag($tag->id, array('shortcode' => $new_shortcode));
                    if (!is_wp_error($result)) {
                        $fixed++;
                        $this->log_migration("Fixed duplicate shortcode: {$duplicate->shortcode} -> {$new_shortcode}", 'info');
                    }
                }
                $counter++;
            }
        }

        return $fixed;
    }

    /**
     * Get migration statistics
     */
    public function get_migration_statistics() {
        $stats = array();

        // Post counts
        $post_counts = wp_count_posts($this->post_type);
        $stats['total_posts'] = $post_counts->publish + $post_counts->draft;
        $stats['published_posts'] = $post_counts->publish;
        $stats['draft_posts'] = $post_counts->draft;

        // Table counts
        $stats['total_table_records'] = $this->db_manager->get_tags_count(array('status' => ''));
        $stats['active_table_records'] = $this->db_manager->get_tags_count(array('status' => 'active'));
        $stats['inactive_table_records'] = $this->db_manager->get_tags_count(array('status' => 'inactive'));

        // Migration status
        $stats['migration_status'] = $this->get_migration_status();
        $stats['migration_needed'] = $this->is_migration_needed();

        // Data integrity
        $stats['integrity_issues'] = $this->validate_data_integrity();

        return $stats;
    }

    /**
     * Reset migration (clears table and migration status)
     */
    public function reset_migration() {
        global $wpdb;

        $table_name = $this->db_manager->get_table_name();
        $wpdb->query("TRUNCATE TABLE {$table_name}");

        delete_option('wp_dynamic_tags_migration_status');
        $this->clear_migration_log();

        $this->log_migration('Migration reset completed', 'info');
    }
}