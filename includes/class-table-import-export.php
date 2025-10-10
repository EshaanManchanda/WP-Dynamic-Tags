<?php
/**
 * Table Import/Export Manager for WP Dynamic Tags
 *
 * Handles bulk import/export operations for table data
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Table_Import_Export {

    private static $instance = null;
    private $table_manager;
    private $db_manager;
    private $supported_formats = array('csv', 'json', 'xml', 'sql');

    public function __construct() {
        $this->table_manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $this->db_manager = WP_Dynamic_Tags_Database_Manager::get_instance();

        // AJAX handlers
        add_action('wp_ajax_dt_table_export', array($this, 'ajax_export'));
        add_action('wp_ajax_dt_table_import', array($this, 'ajax_import'));
        add_action('wp_ajax_dt_table_validate_import', array($this, 'ajax_validate_import'));

        // Admin hooks
        add_action('admin_init', array($this, 'handle_file_download'));
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
     * Export data to various formats
     */
    public function export_data($format = 'json', $filters = array()) {
        $start_time = microtime(true);

        try {
            // Get data based on filters
            $tags = $this->get_filtered_data($filters);

            if (empty($tags)) {
                return new WP_Error('no_data', 'No data found to export');
            }

            // Convert to export format
            switch ($format) {
                case 'csv':
                    $result = $this->export_to_csv($tags);
                    break;
                case 'xml':
                    $result = $this->export_to_xml($tags);
                    break;
                case 'sql':
                    $result = $this->export_to_sql($tags);
                    break;
                case 'json':
                default:
                    $result = $this->export_to_json($tags);
                    break;
            }

            $execution_time = microtime(true) - $start_time;

            return array(
                'success' => true,
                'data' => $result['data'],
                'filename' => $result['filename'],
                'stats' => array(
                    'total_records' => count($tags),
                    'execution_time' => $execution_time,
                    'memory_usage' => memory_get_usage(),
                    'file_size' => strlen($result['data'])
                )
            );

        } catch (Exception $e) {
            return new WP_Error('export_failed', 'Export failed: ' . $e->getMessage());
        }
    }

    /**
     * Import data from various formats
     */
    public function import_data($file_data, $format = 'json', $options = array()) {
        $start_time = microtime(true);

        $defaults = array(
            'update_existing' => false,
            'skip_duplicates' => true,
            'batch_size' => 50,
            'validate_only' => false
        );

        $options = wp_parse_args($options, $defaults);

        try {
            // Parse data based on format
            switch ($format) {
                case 'csv':
                    $parsed_data = $this->parse_csv($file_data);
                    break;
                case 'xml':
                    $parsed_data = $this->parse_xml($file_data);
                    break;
                case 'sql':
                    $parsed_data = $this->parse_sql($file_data);
                    break;
                case 'json':
                default:
                    $parsed_data = $this->parse_json($file_data);
                    break;
            }

            if (is_wp_error($parsed_data)) {
                return $parsed_data;
            }

            // Validate data
            $validation_result = $this->validate_import_data($parsed_data);
            if (!$validation_result['valid']) {
                return new WP_Error('validation_failed', 'Validation failed', $validation_result['errors']);
            }

            if ($options['validate_only']) {
                return array(
                    'success' => true,
                    'validation' => $validation_result,
                    'total_records' => count($parsed_data)
                );
            }

            // Process import in batches
            $result = $this->process_import_batches($parsed_data, $options);

            $execution_time = microtime(true) - $start_time;

            $result['stats']['execution_time'] = $execution_time;
            $result['stats']['memory_usage'] = memory_get_usage();

            return $result;

        } catch (Exception $e) {
            return new WP_Error('import_failed', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Get filtered data for export
     */
    private function get_filtered_data($filters) {
        $args = array(
            'status' => isset($filters['status']) ? $filters['status'] : '',
            'orderby' => isset($filters['orderby']) ? $filters['orderby'] : 'id',
            'order' => isset($filters['order']) ? $filters['order'] : 'ASC'
        );

        if (isset($filters['category_id']) && $filters['category_id'] > 0) {
            $args['category_id'] = $filters['category_id'];
        }

        if (isset($filters['date_from']) && !empty($filters['date_from'])) {
            // Add date filtering logic here
        }

        if (isset($filters['date_to']) && !empty($filters['date_to'])) {
            // Add date filtering logic here
        }

        return $this->table_manager->get_tags($args);
    }

    /**
     * Export to JSON format
     */
    private function export_to_json($tags) {
        $export_data = array(
            'metadata' => array(
                'exported_at' => current_time('mysql'),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => WP_DYNAMIC_TAGS_VERSION,
                'total_records' => count($tags),
                'format' => 'json'
            ),
            'tags' => array()
        );

        foreach ($tags as $tag) {
            $export_data['tags'][] = array(
                'tag_name' => $tag->tag_name,
                'shortcode' => $tag->shortcode,
                'content' => $tag->content,
                'description' => $tag->description,
                'category_id' => $tag->category_id,
                'priority' => $tag->priority,
                'usage_count' => $tag->usage_count,
                'status' => $tag->status,
                'meta_data' => is_string($tag->meta_data) ? maybe_unserialize($tag->meta_data) : $tag->meta_data,
                'created_date' => $tag->created_date,
                'modified_date' => $tag->modified_date,
                'last_used_date' => $tag->last_used_date
            );
        }

        return array(
            'data' => json_encode($export_data, JSON_PRETTY_PRINT),
            'filename' => 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.json'
        );
    }

    /**
     * Export to CSV format
     */
    private function export_to_csv($tags) {
        $csv_data = array();

        // Headers
        $headers = array(
            'Tag Name',
            'Shortcode',
            'Content',
            'Description',
            'Category ID',
            'Priority',
            'Usage Count',
            'Status',
            'Meta Data',
            'Created Date',
            'Modified Date',
            'Last Used Date'
        );

        $csv_data[] = $headers;

        // Data rows
        foreach ($tags as $tag) {
            $meta_data = is_string($tag->meta_data) ? $tag->meta_data : json_encode($tag->meta_data);

            $csv_data[] = array(
                $tag->tag_name,
                $tag->shortcode,
                $this->escape_csv_content($tag->content),
                $this->escape_csv_content($tag->description),
                $tag->category_id,
                $tag->priority,
                $tag->usage_count,
                $tag->status,
                $this->escape_csv_content($meta_data),
                $tag->created_date,
                $tag->modified_date,
                $tag->last_used_date
            );
        }

        // Convert to CSV string
        $output = '';
        foreach ($csv_data as $row) {
            $output .= '"' . implode('","', array_map('str_replace', array_fill(0, count($row), '"'), array_fill(0, count($row), '""'), $row)) . '"' . "\n";
        }

        return array(
            'data' => $output,
            'filename' => 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.csv'
        );
    }

    /**
     * Export to XML format
     */
    private function export_to_xml($tags) {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><dynamic_tags_export/>');

        // Metadata
        $metadata = $xml->addChild('metadata');
        $metadata->addChild('exported_at', current_time('mysql'));
        $metadata->addChild('wp_version', get_bloginfo('version'));
        $metadata->addChild('plugin_version', WP_DYNAMIC_TAGS_VERSION);
        $metadata->addChild('total_records', count($tags));
        $metadata->addChild('format', 'xml');

        // Tags
        $tags_node = $xml->addChild('tags');

        foreach ($tags as $tag) {
            $tag_node = $tags_node->addChild('tag');
            $tag_node->addChild('tag_name', htmlspecialchars($tag->tag_name, ENT_XML1));
            $tag_node->addChild('shortcode', htmlspecialchars($tag->shortcode, ENT_XML1));
            $tag_node->addChild('content', htmlspecialchars($tag->content, ENT_XML1));
            $tag_node->addChild('description', htmlspecialchars($tag->description, ENT_XML1));
            $tag_node->addChild('category_id', $tag->category_id);
            $tag_node->addChild('priority', $tag->priority);
            $tag_node->addChild('usage_count', $tag->usage_count);
            $tag_node->addChild('status', $tag->status);
            $tag_node->addChild('created_date', $tag->created_date);
            $tag_node->addChild('modified_date', $tag->modified_date);
            $tag_node->addChild('last_used_date', $tag->last_used_date);

            // Meta data as CDATA
            if (!empty($tag->meta_data)) {
                $meta_data = is_string($tag->meta_data) ? $tag->meta_data : json_encode($tag->meta_data);
                $meta_node = $tag_node->addChild('meta_data');
                $meta_node_dom = dom_import_simplexml($meta_node);
                $meta_node_dom->appendChild($meta_node_dom->ownerDocument->createCDATASection($meta_data));
            }
        }

        return array(
            'data' => $xml->asXML(),
            'filename' => 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.xml'
        );
    }

    /**
     * Export to SQL format
     */
    private function export_to_sql($tags) {
        $table_name = $this->db_manager->get_table_name();
        $sql_data = array();

        // Header comment
        $sql_data[] = "-- Dynamic Tags Export";
        $sql_data[] = "-- Exported at: " . current_time('mysql');
        $sql_data[] = "-- WordPress Version: " . get_bloginfo('version');
        $sql_data[] = "-- Plugin Version: " . WP_DYNAMIC_TAGS_VERSION;
        $sql_data[] = "-- Total Records: " . count($tags);
        $sql_data[] = "";

        // Table structure (optional)
        $sql_data[] = "-- Table: {$table_name}";
        $sql_data[] = "";

        // Insert statements
        foreach ($tags as $tag) {
            $values = array(
                "'" . esc_sql($tag->tag_name) . "'",
                "'" . esc_sql($tag->shortcode) . "'",
                "'" . esc_sql($tag->content) . "'",
                "'" . esc_sql($tag->description) . "'",
                intval($tag->category_id),
                intval($tag->priority),
                intval($tag->usage_count),
                "'" . esc_sql($tag->status) . "'",
                "'" . esc_sql($tag->meta_data) . "'",
                "'" . esc_sql($tag->created_date) . "'",
                "'" . esc_sql($tag->modified_date) . "'",
                $tag->last_used_date ? "'" . esc_sql($tag->last_used_date) . "'" : 'NULL'
            );

            $sql_data[] = "INSERT INTO {$table_name} (tag_name, shortcode, content, description, category_id, priority, usage_count, status, meta_data, created_date, modified_date, last_used_date) VALUES (" . implode(', ', $values) . ");";
        }

        return array(
            'data' => implode("\n", $sql_data),
            'filename' => 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.sql'
        );
    }

    /**
     * Parse JSON import data
     */
    private function parse_json($json_data) {
        $data = json_decode($json_data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Invalid JSON format: ' . json_last_error_msg());
        }

        // Handle different JSON structures
        if (isset($data['tags'])) {
            return $data['tags'];
        } elseif (isset($data[0])) {
            return $data;
        } else {
            return array($data);
        }
    }

    /**
     * Parse CSV import data
     */
    private function parse_csv($csv_data) {
        $lines = explode("\n", $csv_data);
        $headers = array();
        $data = array();

        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $fields = str_getcsv($line);

            if ($line_num === 0) {
                $headers = $fields;
                continue;
            }

            if (count($fields) !== count($headers)) {
                continue; // Skip malformed rows
            }

            $row = array_combine($headers, $fields);
            $data[] = $this->normalize_csv_row($row);
        }

        return $data;
    }

    /**
     * Parse XML import data
     */
    private function parse_xml($xml_data) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_data);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_message = !empty($errors) ? $errors[0]->message : 'Invalid XML format';
            return new WP_Error('invalid_xml', 'XML parsing failed: ' . $error_message);
        }

        $data = array();

        // Handle different XML structures
        if (isset($xml->tags->tag)) {
            foreach ($xml->tags->tag as $tag) {
                $data[] = $this->xml_to_array($tag);
            }
        } elseif (isset($xml->tag)) {
            foreach ($xml->tag as $tag) {
                $data[] = $this->xml_to_array($tag);
            }
        }

        return $data;
    }

    /**
     * Parse SQL import data
     */
    private function parse_sql($sql_data) {
        // Extract INSERT statements
        preg_match_all('/INSERT\s+INTO\s+\S+\s*\([^)]+\)\s*VALUES\s*\([^)]+\);?/i', $sql_data, $matches);

        $data = array();

        foreach ($matches[0] as $insert_statement) {
            $parsed = $this->parse_insert_statement($insert_statement);
            if ($parsed) {
                $data[] = $parsed;
            }
        }

        return $data;
    }

    /**
     * Validate import data
     */
    private function validate_import_data($data) {
        $errors = array();
        $warnings = array();

        foreach ($data as $index => $row) {
            $row_errors = array();

            // Required fields
            if (empty($row['tag_name'])) {
                $row_errors[] = 'Tag name is required';
            }

            if (empty($row['shortcode'])) {
                $row_errors[] = 'Shortcode is required';
            }

            // Validate shortcode format
            if (!empty($row['shortcode']) && !preg_match('/^[a-zA-Z0-9_\-]+$/', $row['shortcode'])) {
                $row_errors[] = 'Invalid shortcode format';
            }

            // Check for duplicates
            if (!empty($row['shortcode']) && $this->db_manager->shortcode_exists($row['shortcode'])) {
                $warnings[] = "Row {$index}: Shortcode '{$row['shortcode']}' already exists";
            }

            if (!empty($row['tag_name']) && $this->db_manager->tag_name_exists($row['tag_name'])) {
                $warnings[] = "Row {$index}: Tag name '{$row['tag_name']}' already exists";
            }

            // Validate status
            if (isset($row['status']) && !in_array($row['status'], array('active', 'inactive'))) {
                $row_errors[] = 'Invalid status value';
            }

            // Validate priority
            if (isset($row['priority']) && (!is_numeric($row['priority']) || $row['priority'] < 0 || $row['priority'] > 100)) {
                $row_errors[] = 'Priority must be a number between 0 and 100';
            }

            if (!empty($row_errors)) {
                $errors["Row {$index}"] = $row_errors;
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'total_rows' => count($data)
        );
    }

    /**
     * Process import in batches
     */
    private function process_import_batches($data, $options) {
        $batch_size = $options['batch_size'];
        $total_records = count($data);
        $batches = array_chunk($data, $batch_size);

        $stats = array(
            'total_records' => $total_records,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => array()
        );

        foreach ($batches as $batch_index => $batch) {
            foreach ($batch as $row) {
                $stats['processed']++;

                try {
                    $result = $this->import_single_row($row, $options);

                    if (is_wp_error($result)) {
                        $stats['errors']++;
                        $stats['error_details'][] = array(
                            'row' => $stats['processed'],
                            'error' => $result->get_error_message(),
                            'data' => $row
                        );
                    } else {
                        switch ($result['action']) {
                            case 'created':
                                $stats['created']++;
                                break;
                            case 'updated':
                                $stats['updated']++;
                                break;
                            case 'skipped':
                                $stats['skipped']++;
                                break;
                        }
                    }

                } catch (Exception $e) {
                    $stats['errors']++;
                    $stats['error_details'][] = array(
                        'row' => $stats['processed'],
                        'error' => $e->getMessage(),
                        'data' => $row
                    );
                }
            }
        }

        return array(
            'success' => true,
            'stats' => $stats
        );
    }

    /**
     * Import single row
     */
    private function import_single_row($row, $options) {
        // Normalize row data
        $data = array(
            'tag_name' => sanitize_text_field($row['tag_name']),
            'shortcode' => sanitize_text_field($row['shortcode']),
            'content' => wp_kses_post($row['content']),
            'description' => sanitize_textarea_field(isset($row['description']) ? $row['description'] : ''),
            'category_id' => isset($row['category_id']) ? intval($row['category_id']) : 0,
            'priority' => isset($row['priority']) ? intval($row['priority']) : 0,
            'usage_count' => isset($row['usage_count']) ? intval($row['usage_count']) : 0,
            'status' => isset($row['status']) ? $row['status'] : 'active',
            'meta_data' => isset($row['meta_data']) ? $row['meta_data'] : array()
        );

        // Check if tag already exists
        $existing_tag = $this->db_manager->get_tag_by_shortcode($data['shortcode']);

        if ($existing_tag) {
            if ($options['skip_duplicates']) {
                return array('action' => 'skipped', 'reason' => 'duplicate_shortcode');
            } elseif ($options['update_existing']) {
                $result = $this->table_manager->update_tag($existing_tag->id, $data);
                if (is_wp_error($result)) {
                    return $result;
                }
                return array('action' => 'updated', 'id' => $existing_tag->id);
            } else {
                return new WP_Error('duplicate_shortcode', "Shortcode '{$data['shortcode']}' already exists");
            }
        }

        // Create new tag
        $result = $this->table_manager->create_tag($data);
        if (is_wp_error($result)) {
            return $result;
        }

        return array('action' => 'created', 'id' => $result);
    }

    /**
     * Helper methods
     */
    private function escape_csv_content($content) {
        return str_replace(array("\r", "\n"), array('\\r', '\\n'), $content);
    }

    private function normalize_csv_row($row) {
        $normalized = array();

        foreach ($row as $key => $value) {
            $key = strtolower(str_replace(' ', '_', $key));
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function xml_to_array($xml_node) {
        $array = array();

        foreach ($xml_node as $key => $value) {
            $array[$key] = (string) $value;
        }

        return $array;
    }

    private function parse_insert_statement($statement) {
        // Basic SQL INSERT parser - simplified for demonstration
        if (preg_match('/VALUES\s*\(([^)]+)\)/i', $statement, $matches)) {
            $values = explode(',', $matches[1]);

            // This is a simplified version - in production you'd want a more robust SQL parser
            return array(
                'tag_name' => trim($values[0], "' "),
                'shortcode' => trim($values[1], "' "),
                'content' => trim($values[2], "' "),
                'description' => trim($values[3], "' "),
                // Add more fields as needed
            );
        }

        return false;
    }

    /**
     * Handle file download
     */
    public function handle_file_download() {
        if (isset($_GET['dt_download_export']) && current_user_can('manage_options')) {
            $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';

            if (!wp_verify_nonce($nonce, 'dt_export_download')) {
                wp_die('Invalid nonce');
            }

            $export_id = sanitize_text_field($_GET['dt_download_export']);
            $export_data = get_transient('dt_export_' . $export_id);

            if (!$export_data) {
                wp_die('Export data not found or expired');
            }

            // Set headers for download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $export_data['filename'] . '"');
            header('Content-Length: ' . strlen($export_data['data']));

            echo $export_data['data'];
            exit;
        }
    }

    /**
     * AJAX handlers
     */
    public function ajax_export() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $format = sanitize_text_field($_POST['format']);
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array();

        $result = $this->export_data($format, $filters);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Store export data temporarily for download
        $export_id = uniqid();
        set_transient('dt_export_' . $export_id, array(
            'data' => $result['data'],
            'filename' => $result['filename']
        ), HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'export_id' => $export_id,
            'filename' => $result['filename'],
            'download_url' => add_query_arg(array(
                'dt_download_export' => $export_id,
                'nonce' => wp_create_nonce('dt_export_download')
            ), admin_url()),
            'stats' => $result['stats']
        ));
    }

    public function ajax_import() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (empty($_FILES['import_file'])) {
            wp_send_json_error('No file uploaded');
        }

        $file = $_FILES['import_file'];
        $format = sanitize_text_field($_POST['format']);
        $options = isset($_POST['options']) ? $_POST['options'] : array();

        // Read file content
        $file_content = file_get_contents($file['tmp_name']);

        if ($file_content === false) {
            wp_send_json_error('Failed to read file');
        }

        $result = $this->import_data($file_content, $format, $options);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    public function ajax_validate_import() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (empty($_FILES['import_file'])) {
            wp_send_json_error('No file uploaded');
        }

        $file = $_FILES['import_file'];
        $format = sanitize_text_field($_POST['format']);

        $file_content = file_get_contents($file['tmp_name']);

        if ($file_content === false) {
            wp_send_json_error('Failed to read file');
        }

        $result = $this->import_data($file_content, $format, array('validate_only' => true));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Get supported formats
     */
    public function get_supported_formats() {
        return $this->supported_formats;
    }

    /**
     * Get import/export statistics
     */
    public function get_statistics() {
        return array(
            'total_exports' => get_option('dt_total_exports', 0),
            'total_imports' => get_option('dt_total_imports', 0),
            'last_export' => get_option('dt_last_export', ''),
            'last_import' => get_option('dt_last_import', ''),
            'supported_formats' => $this->supported_formats
        );
    }
}