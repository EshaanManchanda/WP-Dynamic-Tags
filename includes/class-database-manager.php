<?php
/**
 * Database Manager for WP Dynamic Tags
 *
 * Handles custom table creation, schema management, and database operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Database_Manager {

    private static $instance = null;
    private $table_name;
    private $table_version = '1.0.0';
    private $charset_collate;

    /** @var bool|null The answer of the one SHOW TABLES this request made; null until asked. */
    private $table_found = null;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dynamic_tags';
        $this->charset_collate = $wpdb->get_charset_collate();

        // Check if WordPress is fully loaded before attempting table creation
        if (did_action('plugins_loaded')) {
            // WordPress is loaded, safe to create table immediately
            $this->ensure_table_exists();
        } else {
            // WordPress not fully loaded yet, defer to plugins_loaded hook
            add_action('plugins_loaded', array($this, 'ensure_table_exists'), 20);
        }
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
     * Ensure table exists (called during initialization)
     */
    public function ensure_table_exists() {
        // Check if we've already verified table exists in this request
        static $table_verified = false;
        if ($table_verified) {
            return;
        }

        if (!$this->table_exists()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Table does not exist, attempting to create: ' . $this->table_name);
            }

            $result = $this->create_table();

            if (is_wp_error($result)) {
                // Store error for admin interface
                update_option('wp_dynamic_tags_table_creation_error', $result->get_error_message());

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Table creation failed in ensure_table_exists: ' . $result->get_error_message());
                }
            } else {
                // Clear any previous errors
                delete_option('wp_dynamic_tags_table_creation_error');

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Table created successfully in ensure_table_exists');
                }
                $table_verified = true;
            }
        } else {
            // Table exists - mark as verified without logging (reduces noise)
            $table_verified = true;
        }
    }

    /**
     * Create the dynamic tags table
     */
    public function create_table() {
        global $wpdb;

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tag_name varchar(255) NOT NULL,
            shortcode varchar(255) NOT NULL,
            content longtext,
            description text,
            category_id bigint(20) unsigned DEFAULT 0,
            priority int(11) DEFAULT 0,
            usage_count bigint(20) unsigned DEFAULT 0,
            created_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modified_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_used_date datetime NULL,
            status varchar(20) DEFAULT 'active',
            meta_data longtext,
            post_id bigint(20) unsigned DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_tag_name (tag_name),
            UNIQUE KEY unique_shortcode (shortcode),
            KEY idx_category_id (category_id),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_usage_count (usage_count),
            KEY idx_created_date (created_date),
            KEY idx_post_id (post_id)
        ) {$this->charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        try {
            $result = dbDelta($sql);

            // Verify table was created successfully (a real check, not the stored version)
            if ($this->table_exists(true)) {
                // Update table version
                update_option('wp_dynamic_tags_table_version', $this->table_version);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Table created successfully - ' . $this->table_name);
                }

                return $result;
            } else {
                $error_msg = 'Table creation appeared to succeed but table does not exist';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Table creation failed - ' . $error_msg);
                }
                return new WP_Error('table_creation_failed', $error_msg);
            }
        } catch (Exception $e) {
            $error_msg = 'Table creation exception: ' . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: ' . $error_msg);
            }
            return new WP_Error('table_creation_exception', $error_msg);
        }
    }

    /**
     * Check if table exists
     *
     * @phpstan-impure
     */
    public function table_exists($verify = false) {
        global $wpdb;

        // The schema version that create_table() stores after it saw the table is trusted: a normal request never asks
        // the database (it did, with SHOW TABLES, on every facade call: 26 times per request). Without a stored version
        // the table is checked once per request. $verify = true always asks (admin health check, create_table()).
        if (!$verify && get_option('wp_dynamic_tags_table_version') === $this->table_version) {
            return true;
        }

        if ($verify || $this->table_found === null) {
            $table_name = $wpdb->prefix . 'dynamic_tags';
            $this->table_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name;
        }
        return $this->table_found;
    }

    /**
     * Get table name
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Get table version
     */
    public function get_table_version() {
        return $this->table_version;
    }

    /**
     * Drop table (use with caution)
     */
    public function drop_table() {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        delete_option('wp_dynamic_tags_table_version');
        $this->table_found = null;
    }

    /**
     * Insert new dynamic tag
     */
    public function insert_tag($data) {
        global $wpdb;

        $defaults = array(
            'tag_name' => '',
            'shortcode' => '',
            'content' => '',
            'description' => '',
            'category_id' => 0,
            'priority' => 0,
            'usage_count' => 0,
            'status' => 'active',
            'meta_data' => '',
            'post_id' => 0
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        if (empty($data['tag_name']) || empty($data['shortcode'])) {
            return new WP_Error('missing_required_fields', 'Tag name and shortcode are required.');
        }

        // Check for duplicates
        if ($this->tag_name_exists($data['tag_name'])) {
            return new WP_Error('duplicate_tag_name', 'Tag name already exists.');
        }

        if ($this->shortcode_exists($data['shortcode'])) {
            return new WP_Error('duplicate_shortcode', 'Shortcode already exists.');
        }

        // Serialize meta_data if it's an array
        if (is_array($data['meta_data'])) {
            $data['meta_data'] = maybe_serialize($data['meta_data']);
        }

        $result = $wpdb->insert($this->table_name, $data);

        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to insert tag: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * Update existing dynamic tag
     */
    public function update_tag($id, $data) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return new WP_Error('invalid_id', 'Invalid tag ID.');
        }

        // Remove ID from data if present
        unset($data['id']);

        // Check for duplicate names/shortcodes (excluding current record)
        if (isset($data['tag_name']) && $this->tag_name_exists($data['tag_name'], $id)) {
            return new WP_Error('duplicate_tag_name', 'Tag name already exists.');
        }

        if (isset($data['shortcode']) && $this->shortcode_exists($data['shortcode'], $id)) {
            return new WP_Error('duplicate_shortcode', 'Shortcode already exists.');
        }

        // Serialize meta_data if it's an array
        if (isset($data['meta_data']) && is_array($data['meta_data'])) {
            $data['meta_data'] = maybe_serialize($data['meta_data']);
        }

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $id),
            null,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update tag: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Delete dynamic tag
     */
    public function delete_tag($id) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return new WP_Error('invalid_id', 'Invalid tag ID.');
        }

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete tag: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Get dynamic tag by ID
     */
    public function get_tag($id) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return false;
        }

        $tag = $wpdb->get_row(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id)
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($tag && !empty($tag->meta_data)) {
            $tag->meta_data = self::decode_meta($tag->meta_data);
        }

        return $tag;
    }

    /**
     * Decodes a stored meta_data value (S9). Rows hold either a PHP-serialized array (written by the facade) or JSON.
     * maybe_unserialize() would instantiate any class named in the string, so a serialized object smuggled into a row by an
     * import or a direct write could run code through its magic methods; here objects are never created
     * (allowed_classes is false) and only arrays are returned for serialized/JSON data. A plain string that is neither
     * serialized nor JSON is returned unchanged, exactly as maybe_unserialize() did.
     *
     * Pure (no WordPress calls) so it is unit tested directly.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function decode_meta($raw) {
        if (!is_string($raw) || $raw === '') {
            return $raw;
        }

        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Serialized values look like a:1:{...}, O:8:"stdClass":0:{}, s:3:"abc";, b:1; i:5; d:1.5; N;
        if (preg_match('/^(?:[aOCsbidE]:|N;)/', $trimmed) === 1) {
            $value = @unserialize($trimmed, array('allowed_classes' => false));
            if ($value === false && $trimmed !== 'b:0;') {
                return array();
            }
            return is_array($value) ? $value : array();
        }

        return $raw;
    }

    /**
     * Get dynamic tag by name
     */
    public function get_tag_by_name($tag_name) {
        global $wpdb;

        $tag = $wpdb->get_row(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE tag_name = %s", $tag_name)
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($tag && !empty($tag->meta_data)) {
            $tag->meta_data = self::decode_meta($tag->meta_data);
        }

        return $tag;
    }

    /**
     * Get dynamic tag by shortcode
     */
    public function get_tag_by_shortcode($shortcode) {
        global $wpdb;

        $tag = $wpdb->get_row(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE shortcode = %s", $shortcode)
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($tag && !empty($tag->meta_data)) {
            $tag->meta_data = self::decode_meta($tag->meta_data);
        }

        return $tag;
    }

    /**
     * Get all dynamic tags with optional filters
     */
    public function get_tags($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => 'active',
            'category_id' => null,
            'orderby' => 'priority',
            'order' => 'DESC',
            'limit' => null,
            'offset' => 0,
            'search' => ''
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array();
        $where_values = array();

        // Status filter
        if (!empty($args['status'])) {
            $where_clauses[] = "status = %s";
            $where_values[] = $args['status'];
        }

        // Category filter
        if (!is_null($args['category_id'])) {
            $where_clauses[] = "category_id = %d";
            $where_values[] = intval($args['category_id']);
        }

        // Search filter
        if (!empty($args['search'])) {
            // The shortcode is searchable too (B3): that is what people copy into content.
            $where_clauses[] = "(tag_name LIKE %s OR shortcode LIKE %s OR content LIKE %s OR description LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Build WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Build ORDER BY clause
        $allowed_orderby = array('id', 'tag_name', 'priority', 'usage_count', 'created_date', 'modified_date');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'priority';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql = "ORDER BY {$orderby} {$order}";

        // Build LIMIT clause
        $limit_sql = '';
        // A limit of 0 (or less) means "no limit" (B2): LIMIT 0,0 used to return nothing at all.
        if (!is_null($args['limit']) && intval($args['limit']) > 0) {
            $limit = intval($args['limit']);
            $offset = max(0, intval($args['offset']));
            $limit_sql = "LIMIT {$offset}, {$limit}";
        }

        // Execute query
        $sql = "SELECT * FROM {$this->table_name} {$where_sql} {$order_sql} {$limit_sql}";

        if (!empty($where_values)) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $tags = $wpdb->get_results($wpdb->prepare($sql, $where_values));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $tags = $wpdb->get_results($sql);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        }

        // Unserialize meta_data for each tag
        if ($tags) {
            foreach ($tags as $tag) {
                if (!empty($tag->meta_data)) {
                    $tag->meta_data = self::decode_meta($tag->meta_data);
                }
            }
        }

        return $tags;
    }

    /**
     * Get tags count with optional filters
     */
    public function get_tags_count($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => 'active',
            'category_id' => null,
            'search' => ''
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array();
        $where_values = array();

        // Status filter
        if (!empty($args['status'])) {
            $where_clauses[] = "status = %s";
            $where_values[] = $args['status'];
        }

        // Category filter
        if (!is_null($args['category_id'])) {
            $where_clauses[] = "category_id = %d";
            $where_values[] = intval($args['category_id']);
        }

        // Search filter
        if (!empty($args['search'])) {
            // The shortcode is searchable too (B3): that is what people copy into content.
            $where_clauses[] = "(tag_name LIKE %s OR shortcode LIKE %s OR content LIKE %s OR description LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Build WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Execute query
        $sql = "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}";

        if (!empty($where_values)) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            return $wpdb->get_var($wpdb->prepare($sql, $where_values));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            return $wpdb->get_var($sql);
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * Check if tag name exists
     */
    public function tag_name_exists($tag_name, $exclude_id = 0) {
        global $wpdb;

        $sql = "SELECT id FROM {$this->table_name} WHERE tag_name = %s";
        $values = array($tag_name);

        if ($exclude_id > 0) {
            $sql .= " AND id != %d";
            $values[] = $exclude_id;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        return $wpdb->get_var($wpdb->prepare($sql, $values)) ? true : false;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Check if shortcode exists
     */
    public function shortcode_exists($shortcode, $exclude_id = 0) {
        global $wpdb;

        $sql = "SELECT id FROM {$this->table_name} WHERE shortcode = %s";
        $values = array($shortcode);

        if ($exclude_id > 0) {
            $sql .= " AND id != %d";
            $values[] = $exclude_id;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        return $wpdb->get_var($wpdb->prepare($sql, $values)) ? true : false;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Increment usage count for a tag
     */
    public function increment_usage_count($id) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return false;
        }

        return $wpdb->query(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare(
                "UPDATE {$this->table_name} SET usage_count = usage_count + 1, last_used_date = NOW() WHERE id = %d",
                $id
            )
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get table statistics
     */
    public function get_statistics() {
        global $wpdb;

        $stats = array();

        // Total tags
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        $stats['total_tags'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Active tags
        $stats['active_tags'] = $wpdb->get_var(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s", 'active')
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Inactive tags
        $stats['inactive_tags'] = $wpdb->get_var(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s", 'inactive')
        );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Most used tag
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        $most_used = $wpdb->get_row(
            "SELECT tag_name, usage_count FROM {$this->table_name} ORDER BY usage_count DESC LIMIT 1"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats['most_used_tag'] = $most_used;

        // Tags by category
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        $stats['tags_by_category'] = $wpdb->get_results(
            "SELECT category_id, COUNT(*) as count FROM {$this->table_name} GROUP BY category_id"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $stats;
    }

    /**
     * Backup table data
     */
    public function backup_table() {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
        $tags = $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($tags) {
            $backup_data = array(
                'version' => $this->table_version,
                'timestamp' => current_time('mysql'),
                'tags' => $tags
            );

            return $backup_data;
        }

        return false;
    }

    /**
     * Restore table data from backup
     */
    public function restore_from_backup($backup_data) {
        global $wpdb;

        if (!is_array($backup_data) || !isset($backup_data['tags'])) {
            return new WP_Error('invalid_backup', 'Invalid backup data.');
        }

        // Begin transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Clear existing data. DELETE, not TRUNCATE: TRUNCATE is DDL and commits implicitly in MySQL,
            // which would leave an empty or half-restored table behind if an insert below failed.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the other fragments are fixed text, a whitelisted ORDER BY column, ASC or DESC and integer limits; every value is a placeholder
            $wpdb->query("DELETE FROM {$this->table_name}");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // Restore data
            foreach ($backup_data['tags'] as $tag) {
                $result = $wpdb->insert($this->table_name, $tag);
                if ($result === false) {
                    throw new Exception('Failed to restore tag: ' . $wpdb->last_error);
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            // Rollback transaction
            $wpdb->query('ROLLBACK');
            return new WP_Error('restore_failed', $e->getMessage());
        }
    }
}