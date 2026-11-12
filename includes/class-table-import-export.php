<?php
/**
 * Table Import/Export Manager for WP Dynamic Tags
 *
 * Moves tags between sites as JSON, CSV or XML files; SQL is export only.
 *
 *  - CSV is written the way RFC 4180 says (real newlines inside quoted fields, a UTF-8 byte order mark so a spreadsheet reads
 *    accents) and every text cell that a spreadsheet could run as a formula (it starts with = + - @, a tab or a carriage
 *    return) gets a leading apostrophe; the import removes exactly that apostrophe again, so an export read back is identical.
 *  - A file is refused when it is too big (filter dt_import_max_bytes, 5 MB), has too many rows (filter dt_import_max_rows,
 *    5000), is of another type, is XML with a DOCTYPE or in another encoding than UTF-8, or has no usable header or rows.
 *  - A file with some bad rows imports the good ones; every bad row is reported by its number in the file (row 1 is the first
 *    tag), and the dry run (dt_table_validate_import) shows the same report without changing anything.
 *
 * Error codes: no_file, upload_failed, unreadable, file_too_large, unsupported_type, unsupported_format, invalid_json,
 * invalid_xml, xml_doctype_not_allowed, xml_encoding, csv_header, no_rows, too_many_rows, import_failed, export_failed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Table_Import_Export {

    /** The text columns of a CSV: the ones guarded against formulas on export and unguarded on import. */
    private const CSV_TEXT_COLUMNS = array('tag_name', 'shortcode', 'content', 'description', 'meta_data');

    /** Key a parser puts on a row it could not read, so the row keeps its number and is reported. */
    private const ROW_ERROR = '_row_error';

    private static $instance = null;
    private $table_manager;
    private $db_manager;

    /** Formats a file can be imported from. SQL is deliberately missing: it is export only. */
    private $import_formats = array('csv', 'json', 'xml');
    private $export_formats = array('csv', 'json', 'xml', 'sql');

    /** @var array<int,true> ids of the tag groups that exist here (filled once per import) */
    private $group_ids = array();

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

    // ================================================================== export

    /**
     * Export data to various formats
     *
     * @param string $format  json (default), csv, xml or sql.
     * @param array  $filters status (active|inactive) and category_id; nothing else is taken from the caller.
     * @return array|WP_Error
     */
    public function export_data($format = 'json', $filters = array()) {
        $start_time = microtime(true);

        try {
            // Get data based on filters
            $tags = $this->get_filtered_data($filters);

            if (empty($tags)) {
                return new WP_Error('no_data', __('No data found to export', 'wp-dynamic-tags'));
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

            if (is_wp_error($result)) {
                return $result;
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

        } catch (Throwable $e) {
            return new WP_Error('export_failed', __('Export failed.', 'wp-dynamic-tags'));
        }
    }

    /**
     * Get filtered data for export. Only the status and the group are filters; the order is fixed.
     */
    private function get_filtered_data($filters) {
        $filters = is_array($filters) ? $filters : array();
        $status = isset($filters['status']) && is_scalar($filters['status']) ? (string) $filters['status'] : '';

        $args = array(
            'status' => in_array($status, array('active', 'inactive'), true) ? $status : '',
            'orderby' => 'id',
            'order' => 'ASC'
        );

        $category = isset($filters['category_id']) && is_scalar($filters['category_id']) ? absint($filters['category_id']) : 0;
        if ($category > 0) {
            $args['category_id'] = $category;
        }

        return $this->table_manager->get_tags($args);
    }

    /**
     * The meta data of a tag as the JSON text the CSV and XML formats carry ('' when there is none).
     */
    private function meta_as_json($tag) {
        $meta = WP_Dynamic_Tags_Database_Manager::decode_meta($tag->meta_data);
        $json = (is_array($meta) && $meta) ? wp_json_encode($meta) : '';
        return is_string($json) ? $json : '';
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
                'meta_data' => WP_Dynamic_Tags_Database_Manager::decode_meta($tag->meta_data),
                'created_date' => $tag->created_date,
                'modified_date' => $tag->modified_date,
                'last_used_date' => $tag->last_used_date
            );
        }

        $json = wp_json_encode($export_data, JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return new WP_Error('export_failed', __('The tags could not be written as JSON.', 'wp-dynamic-tags'));
        }

        return array(
            'data' => $json,
            'filename' => 'dynamic-tags-export-' . gmdate('Y-m-d-H-i-s') . '.json'
        );
    }

    /**
     * A text cell for the CSV: one that starts with = + - @, a tab or a carriage return (after any apostrophes this very
     * rule put there) gets a leading apostrophe, which a spreadsheet shows as nothing and does not run.
     */
    public static function guard_csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^\'*[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /** The inverse of guard_csv_cell(): removes the one apostrophe the export added. */
    public static function unguard_csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^\'+[=+\-@\t\r]/', $value) === 1 ? substr($value, 1) : $value;
    }

    /**
     * Writes rows as CSV text: RFC 4180 (fields that need it are quoted, quotes are doubled, newlines stay real newlines
     * inside quotes), preceded by a UTF-8 byte order mark so a spreadsheet reads accents correctly. The escape character is
     * switched off because quote doubling is what read_csv() undoes. Pure: no WordPress calls.
     *
     * @param array<int,array<int,scalar|null>> $rows
     */
    public static function write_csv(array $rows) {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $fields) {
            fputcsv($stream, $fields, ',', '"', '');
        }
        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);
        return $text;
    }

    /**
     * Reads CSV text written by write_csv() or a spreadsheet: a leading byte order mark is skipped, quoted fields may span
     * lines, blank lines are skipped, CRLF and LF both end a row. Pure: no WordPress calls.
     *
     * @return array<int,array<int,string>>
     */
    public static function read_csv($text) {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $text));
        rewind($stream);
        $rows = array();
        while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($fields === array(null)) {
                continue; // a blank line
            }
            $rows[] = array_map('strval', $fields);
        }
        fclose($stream);
        return $rows;
    }

    /**
     * Export to CSV format
     */
    private function export_to_csv($tags) {
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
        $rows = array($headers);

        foreach ($tags as $tag) {
            $rows[] = array(
                self::guard_csv_cell($tag->tag_name),
                self::guard_csv_cell($tag->shortcode),
                self::guard_csv_cell($tag->content),
                self::guard_csv_cell($tag->description),
                $tag->category_id,
                $tag->priority,
                $tag->usage_count,
                $tag->status,
                self::guard_csv_cell($this->meta_as_json($tag)),
                $tag->created_date,
                $tag->modified_date,
                $tag->last_used_date
            );
        }

        return array(
            'data' => self::write_csv($rows),
            'filename' => 'dynamic-tags-export-' . gmdate('Y-m-d-H-i-s') . '.csv'
        );
    }

    /**
     * A text for an XML element: invalid UTF-8 and the characters XML 1.0 cannot carry are dropped, the rest is escaped.
     */
    private function xml_text($value) {
        $text = wp_check_invalid_utf8((string) $value, true);
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
        return htmlspecialchars(is_string($clean) ? $clean : '', ENT_XML1, 'UTF-8');
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
        $metadata->addChild('total_records', (string) count($tags));
        $metadata->addChild('format', 'xml');

        // Tags
        $tags_node = $xml->addChild('tags');

        foreach ($tags as $tag) {
            $tag_node = $tags_node->addChild('tag');
            $tag_node->addChild('tag_name', $this->xml_text($tag->tag_name));
            $tag_node->addChild('shortcode', $this->xml_text($tag->shortcode));
            $tag_node->addChild('content', $this->xml_text($tag->content));
            $tag_node->addChild('description', $this->xml_text($tag->description));
            $tag_node->addChild('category_id', (string) $tag->category_id);
            $tag_node->addChild('priority', (string) $tag->priority);
            $tag_node->addChild('usage_count', (string) $tag->usage_count);
            $tag_node->addChild('status', $this->xml_text($tag->status));
            $tag_node->addChild('created_date', (string) $tag->created_date);
            $tag_node->addChild('modified_date', (string) $tag->modified_date);
            $tag_node->addChild('last_used_date', (string) $tag->last_used_date);

            // Meta data as CDATA
            $meta_data = $this->meta_as_json($tag);
            if ($meta_data !== '') {
                $meta_node = $tag_node->addChild('meta_data');
                $meta_node_dom = dom_import_simplexml($meta_node);
                $meta_node_dom->appendChild($meta_node_dom->ownerDocument->createCDATASection($meta_data));
            }
        }

        return array(
            'data' => (string) $xml->asXML(),
            'filename' => 'dynamic-tags-export-' . gmdate('Y-m-d-H-i-s') . '.xml'
        );
    }

    /**
     * Export to SQL format. Export only: a SQL file is never imported.
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
            'filename' => 'dynamic-tags-export-' . gmdate('Y-m-d-H-i-s') . '.sql'
        );
    }

    // ================================================================== import

    /**
     * The most rows one import may hold (filter dt_import_max_rows, default 5000; 0 or less means no limit).
     */
    private function max_rows() {
        return (int) apply_filters('dt_import_max_rows', 5000);
    }

    /**
     * Import data from a file's text.
     *
     * With validate_only the answer is the report of the dry run and nothing is written. Otherwise every valid row is
     * imported and every other row is listed in stats.error_details with its number and the reason.
     *
     * @param string $file_data The file's content.
     * @param string $format    csv, json or xml (sql is export only).
     * @param array  $options   update_existing, skip_duplicates, validate_only.
     * @return array|WP_Error
     */
    public function import_data($file_data, $format = 'json', $options = array()) {
        $start_time = microtime(true);

        $defaults = array(
            'update_existing' => false,
            'skip_duplicates' => true,
            'batch_size' => 50, // kept for callers that pass it; rows are handled one by one
            'validate_only' => false
        );

        $options = wp_parse_args($options, $defaults);
        $format = strtolower((string) $format);

        if (!in_array($format, $this->import_formats, true)) {
            /* translators: %s: the formats a file can be imported from */
            return new WP_Error('unsupported_format', sprintf(__('This format cannot be imported. Use %s.', 'wp-dynamic-tags'), implode(', ', $this->import_formats)));
        }

        try {
            // Parse data based on format
            switch ($format) {
                case 'csv':
                    $rows = $this->parse_csv((string) $file_data);
                    break;
                case 'xml':
                    $rows = $this->parse_xml((string) $file_data);
                    break;
                case 'json':
                default:
                    $rows = $this->parse_json((string) $file_data);
                    break;
            }

            if (is_wp_error($rows)) {
                return $rows;
            }

            if (!$rows) {
                return new WP_Error('no_rows', __('The file contains no tags.', 'wp-dynamic-tags'));
            }

            $max = $this->max_rows();
            if ($max > 0 && count($rows) > $max) {
                /* translators: 1: rows in the file, 2: the limit */
                return new WP_Error('too_many_rows', sprintf(__('The file has %1$d rows and at most %2$d can be imported at once. Split the file, or raise the limit with the dt_import_max_rows filter.', 'wp-dynamic-tags'), count($rows), $max));
            }

            $this->group_ids = $this->load_group_ids();
            $report = $this->validate_import_data($rows);

            if ($options['validate_only']) {
                return array(
                    'success' => true,
                    'validation' => $report,
                    'total_records' => count($rows)
                );
            }

            // Process the rows
            $result = $this->process_import($rows, $options, $report);

            $result['stats']['execution_time'] = microtime(true) - $start_time;
            $result['stats']['memory_usage'] = memory_get_usage();

            return $result;

        } catch (Throwable $e) {
            return new WP_Error('import_failed', __('Import failed.', 'wp-dynamic-tags'));
        }
    }

    /**
     * The ids of the tag groups that exist on this site.
     *
     * @return array<int,true>
     */
    private function load_group_ids() {
        $ids = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false, 'fields' => 'ids'));
        return is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : array();
    }

    /**
     * Parse JSON import data: {"tags": [...]}, a list of tags, or a single tag.
     */
    private function parse_json($json_data) {
        $data = json_decode($json_data, true, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            /* translators: %s: the parser's message */
            return new WP_Error('invalid_json', sprintf(__('Invalid JSON format: %s', 'wp-dynamic-tags'), json_last_error_msg()));
        }

        if (!is_array($data)) {
            return new WP_Error('invalid_json', __('The JSON file must hold a list of tags, or an object with a "tags" list.', 'wp-dynamic-tags'));
        }

        if (array_key_exists('tags', $data)) {
            if (!is_array($data['tags'])) {
                return new WP_Error('invalid_json', __('The "tags" entry of the JSON file must be a list.', 'wp-dynamic-tags'));
            }
            return array_values($data['tags']);
        }

        return array_is_list($data) ? $data : array($data);
    }

    /**
     * Parse CSV import data: the header written by the export (spreadsheets add a byte order mark and use CRLF), quoted
     * fields that span lines, blank lines skipped. A row with the wrong number of columns is kept as a row error.
     */
    private function parse_csv($csv_data) {
        $headers = null;
        $rows = array();

        foreach (self::read_csv($csv_data) as $fields) {
            if ($headers === null) {
                $headers = array_map(static function ($header) {
                    return strtolower(str_replace(' ', '_', trim((string) $header)));
                }, $fields);
                if (!in_array('tag_name', $headers, true) || !in_array('shortcode', $headers, true)) {
                    return new WP_Error('csv_header', __('The first row must be the header the export writes, with at least the columns Tag Name and Shortcode.', 'wp-dynamic-tags'));
                }
                continue;
            }

            if (count($fields) !== count($headers)) {
                /* translators: 1: columns in the row, 2: columns in the header */
                $rows[] = array(self::ROW_ERROR => sprintf(__('The row has %1$d columns and the header has %2$d.', 'wp-dynamic-tags'), count($fields), count($headers)));
                continue;
            }

            $row = array_combine($headers, $fields);
            foreach (self::CSV_TEXT_COLUMNS as $column) {
                if (isset($row[$column])) {
                    $row[$column] = self::unguard_csv_cell($row[$column]);
                }
            }
            $rows[] = $row;
        }

        if ($headers === null) {
            return new WP_Error('csv_header', __('The CSV file is empty.', 'wp-dynamic-tags'));
        }

        return $rows;
    }

    /**
     * Parse XML import data
     */
    private function parse_xml($xml_data) {
        $xml_data = (string) preg_replace('/^\xEF\xBB\xBF/', '', $xml_data);

        // Only UTF-8: a NUL byte means UTF-16 or UTF-32, which hides the declarations the checks below look for.
        if (strpos($xml_data, "\0") !== false || !mb_check_encoding($xml_data, 'UTF-8')
            || (preg_match('/^\s*<\?xml[^>]*\sencoding\s*=\s*["\']([^"\']+)["\']/i', $xml_data, $declared) === 1 && strtoupper($declared[1]) !== 'UTF-8')) {
            return new WP_Error('xml_encoding', __('Only XML in UTF-8 is accepted.', 'wp-dynamic-tags'));
        }

        // A DOCTYPE is how entity-expansion and external-entity attacks are declared; tag exports never contain one (I3).
        if (stripos($xml_data, '<!DOCTYPE') !== false || stripos($xml_data, '<!ENTITY') !== false) {
            return new WP_Error('xml_doctype_not_allowed', __('XML with a DOCTYPE or entity declaration is not accepted.', 'wp-dynamic-tags'));
        }

        // Leave libxml's error mode as another plugin set it.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_data, 'SimpleXMLElement', LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $detail = !empty($errors) ? trim($errors[0]->message) : __('Invalid XML format', 'wp-dynamic-tags');
            /* translators: %s: the parser's message */
            return new WP_Error('invalid_xml', sprintf(__('XML parsing failed: %s', 'wp-dynamic-tags'), $detail));
        }

        $rows = array();

        // Handle different XML structures
        if (isset($xml->tags->tag)) {
            foreach ($xml->tags->tag as $tag) {
                $rows[] = $this->xml_to_array($tag);
            }
        } elseif (isset($xml->tag)) {
            foreach ($xml->tag as $tag) {
                $rows[] = $this->xml_to_array($tag);
            }
        }

        return $rows;
    }

    private function xml_to_array($xml_node) {
        $array = array();

        foreach ($xml_node as $key => $value) {
            $array[$key] = (string) $value;
        }

        return $array;
    }

    /**
     * The dry run: one verdict per row. Row numbers start at 1 (the first tag of the file).
     *
     * @param array $rows
     * @return array{valid: bool, errors: array<string,string[]>, warnings: string[], total_rows: int, valid_rows: int, invalid_rows: int, invalid_row_numbers: int[]}
     */
    private function validate_import_data($rows) {
        $codes = array();
        $names = array();
        foreach ($this->table_manager->get_tags(array('status' => '')) as $existing) {
            $codes[strtolower((string) $existing->shortcode)] = true;
            $names[strtolower((string) $existing->tag_name)] = true;
        }

        $errors = array();
        $warnings = array();
        $invalid = array();
        $seen_codes = array();
        $seen_names = array();
        $number = 0;

        foreach ($rows as $row) {
            $number++;
            $problems = $this->row_problems($row, $number, $codes, $names, $seen_codes, $seen_names, $warnings);
            if ($problems) {
                $errors['Row ' . $number] = $problems;
                $invalid[] = $number;
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'total_rows' => count($rows),
            'valid_rows' => count($rows) - count($invalid),
            'invalid_rows' => count($invalid),
            'invalid_row_numbers' => $invalid
        );
    }

    /**
     * What is wrong with one row (empty when it can be imported). Notes that do not stop the import go to $warnings.
     *
     * @return string[]
     */
    private function row_problems($row, $number, array $codes, array $names, array &$seen_codes, array &$seen_names, array &$warnings) {
        if (!is_array($row)) {
            return array(__('The row is not a tag.', 'wp-dynamic-tags'));
        }
        if (isset($row[self::ROW_ERROR])) {
            return array((string) $row[self::ROW_ERROR]);
        }

        $problems = array();

        foreach (array('tag_name', 'shortcode', 'content', 'description', 'status', 'priority', 'category_id', 'usage_count') as $field) {
            if (isset($row[$field]) && !is_scalar($row[$field])) {
                /* translators: %s: a field name */
                $problems[] = sprintf(__('%s must be text or a number.', 'wp-dynamic-tags'), $field);
            }
        }
        if ($problems) {
            return $problems;
        }

        $name = trim((string) ($row['tag_name'] ?? ''));
        $code = trim((string) ($row['shortcode'] ?? ''));
        $code_ok = false;

        if ($name === '') {
            $problems[] = __('Tag name is required.', 'wp-dynamic-tags');
        } elseif (!preg_match(WP_Dynamic_Tags_Tag_Service::NAME_PATTERN, $name)) {
            $problems[] = __('Tag name can only contain letters, numbers, spaces, hyphens and underscores.', 'wp-dynamic-tags');
        }

        if ($code === '') {
            $problems[] = __('Shortcode is required.', 'wp-dynamic-tags');
        } elseif (!preg_match(WP_Dynamic_Tags_Tag_Service::SHORTCODE_PATTERN, $code)) {
            $problems[] = __('Shortcode can only contain letters, numbers, hyphens and underscores.', 'wp-dynamic-tags');
        } else {
            $allowed = WP_Dynamic_Tags_Tag_Service::check_shortcode($code);
            if (is_wp_error($allowed)) {
                $problems[] = $allowed->get_error_message();
            } else {
                $code_ok = true;
            }
        }

        $status = trim((string) ($row['status'] ?? ''));
        if ($status !== '' && !in_array($status, array('active', 'inactive'), true)) {
            $problems[] = __('Status must be "active" or "inactive".', 'wp-dynamic-tags');
        }

        $priority = trim((string) ($row['priority'] ?? ''));
        if ($priority !== '' && (!is_numeric($priority) || $priority < 0 || $priority > 100)) {
            $problems[] = __('Priority must be a number between 0 and 100.', 'wp-dynamic-tags');
        }

        $usage = trim((string) ($row['usage_count'] ?? ''));
        if ($usage !== '' && (!is_numeric($usage) || $usage < 0)) {
            $problems[] = __('Usage count must be a number, zero or more.', 'wp-dynamic-tags');
        }

        $group = trim((string) ($row['category_id'] ?? ''));
        if ($group !== '' && !is_numeric($group)) {
            $problems[] = __('Group id must be a number.', 'wp-dynamic-tags');
        } elseif ((int) $group > 0 && !isset($this->group_ids[(int) $group])) {
            /* translators: 1: row number, 2: group id */
            $warnings[] = sprintf(__('Row %1$d: the group %2$d does not exist on this site, so the tag is imported without a group.', 'wp-dynamic-tags'), $number, (int) $group);
        }

        if ($code_ok) {
            $key = strtolower($code);
            $repeated = isset($seen_codes[$key]);
            if ($repeated) {
                /* translators: 1: row number, 2: shortcode, 3: the first row that has it */
                $warnings[] = sprintf(__('Row %1$d: the shortcode [%2$s] is also in row %3$d.', 'wp-dynamic-tags'), $number, $code, $seen_codes[$key]);
            } else {
                $seen_codes[$key] = $number;
            }

            if (isset($codes[$key])) {
                /* translators: 1: row number, 2: shortcode */
                $warnings[] = sprintf(__('Row %1$d: the shortcode [%2$s] already exists (it is skipped or updated, as the options say).', 'wp-dynamic-tags'), $number, $code);
            } elseif (!$repeated && $name !== '') {
                // A new tag: its name must be free here and in the rows before it (a repeated shortcode is a duplicate of the first row).
                $name_key = strtolower($name);
                if (isset($names[$name_key])) {
                    $problems[] = __('Another tag already has this name.', 'wp-dynamic-tags');
                } elseif (isset($seen_names[$name_key])) {
                    $problems[] = __('Another row of the file has this name.', 'wp-dynamic-tags');
                } elseif (!$problems) {
                    $seen_names[$name_key] = $number;
                }
            }
        }

        return $problems;
    }

    /**
     * Import the rows the dry run accepted, report the others.
     */
    private function process_import($rows, $options, $report) {
        $stats = array(
            'total_records' => count($rows),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => array()
        );

        // A big file takes a while: more memory and a longer time limit than a normal admin request.
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        // One reference graph for the whole import instead of one scan of every stored tag per created tag.
        WP_Dynamic_Tags_Tag_Service::begin_batch();
        try {
            $number = 0;
            foreach ($rows as $row) {
                $number++;
                $stats['processed']++;

                if (isset($report['errors']['Row ' . $number])) {
                    $stats['errors']++;
                    $stats['error_details'][] = array('row' => $number, 'error' => implode(' ', $report['errors']['Row ' . $number]));
                    continue;
                }

                try {
                    $result = $this->import_single_row($row, $options);

                    if (is_wp_error($result)) {
                        $stats['errors']++;
                        $stats['error_details'][] = array('row' => $number, 'error' => $result->get_error_message());
                    } elseif ($result['action'] === 'created') {
                        $stats['created']++;
                    } elseif ($result['action'] === 'updated') {
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $stats['error_details'][] = array('row' => $number, 'error' => __('The row could not be imported.', 'wp-dynamic-tags'));
                }
            }
        } finally {
            WP_Dynamic_Tags_Tag_Service::end_batch();
        }

        return array(
            'success' => true,
            'stats' => $stats
        );
    }

    /**
     * Import single row. Content goes through the Tag Service like every other write (markup is filtered unless the user
     * may post unfiltered HTML), so there is one rule, and conditions such as {if a < b} survive.
     */
    private function import_single_row($row, $options) {
        $meta = WP_Dynamic_Tags_Database_Manager::decode_meta(isset($row['meta_data']) ? $row['meta_data'] : array());
        $group = (int) (isset($row['category_id']) ? $row['category_id'] : 0);
        $status = trim((string) (isset($row['status']) ? $row['status'] : ''));

        $data = array(
            'tag_name' => sanitize_text_field(trim((string) $row['tag_name'])),
            'shortcode' => sanitize_text_field(trim((string) $row['shortcode'])),
            'content' => (string) (isset($row['content']) ? $row['content'] : ''),
            'description' => sanitize_textarea_field((string) (isset($row['description']) ? $row['description'] : '')),
            'category_id' => isset($this->group_ids[$group]) ? $group : 0,
            'priority' => (int) (isset($row['priority']) ? $row['priority'] : 0),
            'usage_count' => max(0, (int) (isset($row['usage_count']) ? $row['usage_count'] : 0)),
            'status' => $status === '' ? 'active' : $status,
            'meta_data' => is_array($meta) ? $meta : array()
        );

        // Check if tag already exists
        $existing_tag = $this->db_manager->get_tag_by_shortcode($data['shortcode']);

        if ($existing_tag) {
            if ($options['update_existing']) {
                // What the file does not say stays as it is here: the local usage counter, the group, the meta data.
                unset($data['usage_count']);
                if (!$data['category_id']) {
                    unset($data['category_id']);
                }
                if (!$data['meta_data']) {
                    unset($data['meta_data']);
                }
                $result = $this->table_manager->update_tag($existing_tag->id, $data);
                if (is_wp_error($result)) {
                    return $result;
                }
                return array('action' => 'updated', 'id' => $existing_tag->id);
            }
            if ($options['skip_duplicates']) {
                return array('action' => 'skipped', 'reason' => 'duplicate_shortcode');
            }
            /* translators: %s: shortcode */
            return new WP_Error('duplicate_shortcode', sprintf(__('The shortcode [%s] already exists.', 'wp-dynamic-tags'), $data['shortcode']));
        }

        // Create new tag
        $result = $this->table_manager->create_tag($data);
        if (is_wp_error($result)) {
            return $result;
        }

        return array('action' => 'created', 'id' => $result);
    }

    // ================================================================== download and endpoints

    /**
     * Handle file download
     */
    public function handle_file_download() {
        if (isset($_GET['dt_download_export']) && current_user_can('manage_options')) {
            $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

            if (!wp_verify_nonce($nonce, 'dt_export_download')) {
                wp_die(esc_html__('Invalid nonce', 'wp-dynamic-tags'));
            }

            $export_id = sanitize_text_field(wp_unslash($_GET['dt_download_export']));
            $export_data = $this->take_export($export_id, get_current_user_id());

            if (!$export_data) {
                wp_die(esc_html__('Export data not found or expired', 'wp-dynamic-tags'));
            }

            // Set headers for download
            header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            header('Content-Disposition: attachment; filename="' . $export_data['filename'] . '"');
            header('Content-Length: ' . strlen($export_data['data']));

            echo $export_data['data']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download, not a page
            exit;
        }
    }

    /**
     * Returns a stored export and deletes it, but only for the user who created it (I1). An export holds every tag
     * (certificate tags carry student data): it used to sit in a transient for an hour under a uniqid() that any
     * administrator could guess, and could be downloaded repeatedly.
     *
     * @param string $export_id
     * @param int    $user_id   The user asking for the download.
     * @return array{data: string, filename: string}|null Null when it does not exist, expired, or belongs to someone else.
     */
    public function take_export($export_id, $user_id) {
        $key = 'dt_export_' . $export_id;
        $stored = get_transient($key);

        if (!is_array($stored) || (int) $user_id < 1 || (int) ($stored['user_id'] ?? 0) !== (int) $user_id) {
            return null;
        }

        delete_transient($key);
        return array('data' => (string) $stored['data'], 'filename' => (string) $stored['filename']);
    }

    /**
     * AJAX handlers
     */
    public function ajax_export() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-dynamic-tags'));
        }

        $format = isset($_POST['format']) ? sanitize_key(wp_unslash($_POST['format'])) : '';
        $filters = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized and whitelisted by get_filtered_data(), sanitize_import_options() and read_upload(); the nonce is verified by the calling handler

        $result = $this->export_data($format, $filters);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Store export data temporarily for download: an unguessable id, bound to this user, ten minutes (I1)
        $export_id = wp_generate_password(32, false);
        set_transient('dt_export_' . $export_id, array(
            'data' => $result['data'],
            'filename' => $result['filename'],
            'user_id' => get_current_user_id()
        ), 10 * MINUTE_IN_SECONDS);

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

    /**
     * Checks and reads the uploaded import file (I2). The endpoints used to read whatever PHP had put in tmp_name:
     * no upload-error check, no size cap, no check of the file type.
     *
     * Filter dt_import_max_bytes sets the size cap (default 5 MB).
     *
     * @return array{content: string, format: string}|WP_Error
     */
    private function read_upload() {
        $file = isset($_FILES['import_file']) ? $_FILES['import_file'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized and whitelisted by get_filtered_data(), sanitize_import_options() and read_upload(); the nonce is verified by the calling handler; the nonce is verified by the calling handler before this runs
        if (!is_array($file) || !isset($file['tmp_name'], $file['error']) || is_array($file['error'])) {
            return new WP_Error('no_file', __('No file uploaded', 'wp-dynamic-tags'));
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            /* translators: %d: PHP's upload error code */
            return new WP_Error('upload_failed', sprintf(__('The upload failed (error code %d).', 'wp-dynamic-tags'), (int) $file['error']));
        }

        $max = (int) apply_filters('dt_import_max_bytes', 5 * MB_IN_BYTES);
        $size = is_readable($file['tmp_name']) ? filesize($file['tmp_name']) : false;
        if ($size === false) {
            return new WP_Error('unreadable', __('Failed to read file', 'wp-dynamic-tags'));
        }
        if ($max > 0 && $size > $max) {
            /* translators: %s: the size limit */
            return new WP_Error('file_too_large', sprintf(__('The file is larger than the %s limit.', 'wp-dynamic-tags'), size_format($max)));
        }

        $extension = strtolower(pathinfo(isset($file['name']) ? sanitize_file_name(wp_unslash($file['name'])) : '', PATHINFO_EXTENSION));
        if (!in_array($extension, $this->import_formats, true)) {
            /* translators: %s: the file types that can be imported */
            return new WP_Error('unsupported_type', sprintf(__('Unsupported file type. Use %s.', 'wp-dynamic-tags'), implode(', ', $this->import_formats)));
        }

        $requested = isset($_POST['format']) ? sanitize_key(wp_unslash($_POST['format'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by the calling handler before this runs
        $format = in_array($requested, $this->import_formats, true) ? $requested : $extension;

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return new WP_Error('unreadable', __('Failed to read file', 'wp-dynamic-tags'));
        }

        return array('content' => $content, 'format' => $format);
    }

    /**
     * Only the documented import options are taken from the request, with the right types (I2).
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function sanitize_import_options($raw) {
        $raw = is_array($raw) ? $raw : array();
        $options = array();
        foreach (array('update_existing', 'skip_duplicates') as $flag) {
            if (isset($raw[$flag])) {
                $options[$flag] = filter_var($raw[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($raw['batch_size'])) {
            $options['batch_size'] = min(500, max(1, (int) $raw['batch_size']));
        }
        return $options;
    }

    public function ajax_import() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-dynamic-tags'));
        }

        $upload = $this->read_upload();
        if (is_wp_error($upload)) {
            wp_send_json_error($upload->get_error_message());
        }

        $options = $this->sanitize_import_options(isset($_POST['options']) ? wp_unslash($_POST['options']) : array()); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized and whitelisted by get_filtered_data(), sanitize_import_options() and read_upload(); the nonce is verified by the calling handler

        $result = $this->import_data($upload['content'], $upload['format'], $options);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    public function ajax_validate_import() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-dynamic-tags'));
        }

        $upload = $this->read_upload();
        if (is_wp_error($upload)) {
            wp_send_json_error($upload->get_error_message());
        }

        // Validation never imports: validate_only is forced, whatever the request says.
        $result = $this->import_data($upload['content'], $upload['format'], array('validate_only' => true));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * The formats a file can be imported from.
     *
     * @return string[]
     */
    public function get_import_formats() {
        return $this->import_formats;
    }

    /**
     * The formats the tags can be exported to.
     *
     * @return string[]
     */
    public function get_export_formats() {
        return $this->export_formats;
    }

    /**
     * Get supported formats (the export formats; see get_import_formats() for what can be imported)
     */
    public function get_supported_formats() {
        return $this->export_formats;
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
            'supported_formats' => $this->export_formats,
            'import_formats' => $this->import_formats
        );
    }
}
