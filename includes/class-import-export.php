<?php
/**
 * Import/Export Manager for WP Dynamic Tags
 * 
 * Handles JSON/CSV import and export of dynamic tags
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Import_Export {
    
    private $post_type = 'dynamic_tag';
    private $transient_key = 'wp_dynamic_tags_cache';

    // Performance optimization - integrate with main plugin cache
    private static $static_cache = array();
    private $cache_version = '2.0.1';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'handle_import'));
        add_action('wp_ajax_dt_export_json', array($this, 'export_json'));
        add_action('wp_ajax_dt_export_csv', array($this, 'export_csv'));
    }
    
    /**
     * Add import/export admin page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Import/Export', 'wp-dynamic-tags'),
            __('Import/Export', 'wp-dynamic-tags'),
            'manage_options',
            'dt-import-export',
            array($this, 'import_export_page')
        );
    }
    
    /**
     * Import/Export page
     */
    public function import_export_page() {
        $total_tags = wp_count_posts($this->post_type)->publish;
        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Import/Export', 'wp-dynamic-tags'); ?></h1>
            
            <?php $this->show_notices(); ?>
            
            <div class="card-container" style="display: flex; gap: 20px; flex-wrap: wrap;">
                <!-- Export Section -->
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2><?php _e('Export Tags', 'wp-dynamic-tags'); ?></h2>
                    <p><?php printf(__('You have %d dynamic tags to export.', 'wp-dynamic-tags'), $total_tags); ?></p>
                    
                    <div class="export-options">
                        <h3><?php _e('Export Format', 'wp-dynamic-tags'); ?></h3>
                        
                        <div style="margin-bottom: 15px;">
                            <button type="button" id="export-json-btn" class="button button-primary" 
                                    <?php disabled($total_tags, 0); ?>>
                                <span class="dashicons dashicons-download"></span>
                                <?php _e('Export as JSON', 'wp-dynamic-tags'); ?>
                            </button>
                            <p class="description">
                                <?php _e('JSON format preserves all data including categories and metadata. Best for backup and migration.', 'wp-dynamic-tags'); ?>
                            </p>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <button type="button" id="export-csv-btn" class="button"
                                    <?php disabled($total_tags, 0); ?>>
                                <span class="dashicons dashicons-media-spreadsheet"></span>
                                <?php _e('Export as CSV', 'wp-dynamic-tags'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Enhanced CSV format with better data consistency. Excel-compatible with UTF-8 encoding.', 'wp-dynamic-tags'); ?>
                            </p>
                        </div>
                        
                        <div class="export-options-advanced">
                            <h4><?php _e('Export Options', 'wp-dynamic-tags'); ?></h4>
                            <label>
                                <input type="checkbox" id="include-metadata" checked>
                                <?php _e('Include metadata (creation date, category, groups)', 'wp-dynamic-tags'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" id="include-usage-stats">
                                <?php _e('Include usage statistics (if available)', 'wp-dynamic-tags'); ?>
                            </label><br><br>

                            <h4><?php _e('Export Filters', 'wp-dynamic-tags'); ?></h4>
                            <label>
                                <?php _e('Category:', 'wp-dynamic-tags'); ?>
                                <select id="export-category">
                                    <option value=""><?php _e('All Categories', 'wp-dynamic-tags'); ?></option>
                                    <?php
                                    $categories = $this->get_all_categories();
                                    foreach ($categories as $category) {
                                        echo '<option value="' . esc_attr($category) . '">' . esc_html(ucfirst($category)) . '</option>';
                                    }
                                    ?>
                                </select>
                            </label><br>
                            <label>
                                <?php _e('Group:', 'wp-dynamic-tags'); ?>
                                <select id="export-group">
                                    <option value=""><?php _e('All Groups', 'wp-dynamic-tags'); ?></option>
                                    <?php
                                    $groups = get_terms(array(
                                        'taxonomy' => 'tag_groups',
                                        'hide_empty' => false
                                    ));
                                    if (!is_wp_error($groups)) {
                                        foreach ($groups as $group) {
                                            echo '<option value="' . esc_attr($group->term_id) . '">' . esc_html($group->name) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Import Section -->
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2><?php _e('Import Tags', 'wp-dynamic-tags'); ?></h2>
                    <p><?php _e('Upload a JSON or CSV file to import dynamic tags.', 'wp-dynamic-tags'); ?></p>
                    
                    <form method="post" enctype="multipart/form-data" id="import-form">
                        <?php wp_nonce_field('dt_import_tags', 'dt_import_nonce'); ?>

                        <div style="margin-bottom: 15px;">
                            <label for="import-file" class="screen-reader-text"><?php _e('Choose file', 'wp-dynamic-tags'); ?></label>
                            <input type="file" name="import_file" id="import-file" accept=".json,.csv" required>
                            <p class="description">
                                <?php _e('Supported formats: JSON (.json) and CSV (.csv). Max file size: 32MB', 'wp-dynamic-tags'); ?>
                            </p>
                            <div id="file-info" style="display: none; background: #f0f0f1; padding: 10px; margin-top: 10px; border-radius: 4px;">
                                <strong><?php _e('File Preview:', 'wp-dynamic-tags'); ?></strong>
                                <div id="file-details"></div>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <h4><?php _e('Import Options', 'wp-dynamic-tags'); ?></h4>
                            
                            <label>
                                <input type="radio" name="conflict_resolution" value="skip" checked>
                                <?php _e('Skip existing tags (recommended)', 'wp-dynamic-tags'); ?>
                            </label><br>
                            
                            <label>
                                <input type="radio" name="conflict_resolution" value="update">
                                <?php _e('Update existing tags with new values', 'wp-dynamic-tags'); ?>
                            </label><br>
                            
                            <label>
                                <input type="radio" name="conflict_resolution" value="rename">
                                <?php _e('Rename conflicting tags (add suffix)', 'wp-dynamic-tags'); ?>
                            </label>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>
                                <input type="checkbox" name="validate_keys" checked>
                                <?php _e('Validate and sanitize tag keys', 'wp-dynamic-tags'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="create_backup" checked>
                                <?php _e('Create backup before import (recommended)', 'wp-dynamic-tags'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="preview_import" checked>
                                <?php _e('Preview import data before processing', 'wp-dynamic-tags'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="detailed_logging">
                                <?php _e('Enable detailed error logging', 'wp-dynamic-tags'); ?>
                            </label>
                        </div>
                        
                        <div id="import-preview" style="display: none; background: #fff; border: 1px solid #c3c4c7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                            <h4><?php _e('Import Preview', 'wp-dynamic-tags'); ?></h4>
                            <div id="preview-content"></div>
                            <div id="preview-validation"></div>
                        </div>

                        <div id="import-progress" style="display: none; margin: 15px 0;">
                            <h4><?php _e('Import Progress', 'wp-dynamic-tags'); ?></h4>
                            <div style="background: #f0f0f1; border-radius: 4px; padding: 3px;">
                                <div id="progress-bar" style="background: #0073aa; height: 20px; border-radius: 2px; width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <div id="progress-text"></div>
                            <div id="import-log" style="background: #f6f7f7; padding: 10px; margin-top: 10px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;"></div>
                        </div>

                        <p class="submit">
                            <input type="button" id="preview-import-btn" class="button"
                                   value="<?php esc_attr_e('Preview Import', 'wp-dynamic-tags'); ?>" disabled>
                            <input type="submit" name="import_tags" class="button-primary"
                                   value="<?php esc_attr_e('Import Tags', 'wp-dynamic-tags'); ?>" disabled>
                            <input type="button" id="cancel-import-btn" class="button"
                                   value="<?php esc_attr_e('Cancel', 'wp-dynamic-tags'); ?>" style="display: none;">
                        </p>
                    </form>
                </div>
            </div>
            
            <!-- Recent Imports/Exports -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Recent Activity', 'wp-dynamic-tags'); ?></h2>
                <?php $this->show_recent_activity(); ?>
            </div>
            
            <!-- Help Section -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Import/Export Help', 'wp-dynamic-tags'); ?></h2>
                
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <h3><?php _e('JSON Format Example', 'wp-dynamic-tags'); ?></h3>
                        <pre style="background: #f6f7f7; padding: 10px; overflow-x: auto; font-size: 12px;">{
  "version": "1.0",
  "export_date": "2024-01-01T12:00:00Z",
  "tags": [
    {
      "key": "site_title",
      "value": "My Website Title",
      "category": "general",
      "created_date": "2024-01-01T10:00:00Z"
    },
    {
      "key": "company_phone",
      "value": "+1 (555) 123-4567",
      "category": "contact",
      "created_date": "2024-01-01T11:00:00Z"
    }
  ]
}</pre>
                    </div>
                    
                    <div style="flex: 1; min-width: 250px;">
                        <h3><?php _e('Standardized CSV Format (v2.0)', 'wp-dynamic-tags'); ?></h3>
                        <pre style="background: #f6f7f7; padding: 10px; overflow-x: auto; font-size: 12px;">tag_key,tag_value,category,groups,priority,created_date,modified_date,export_version,shortcode_patterns
site_title,"My Website Title",general,"Branding;General",0,"2024-01-01 10:00:00","2024-01-02 15:30:00","2.0","[site_title];[branding_site_title]"
company_phone,"+1 (555) 123-4567",contact,"Contact Info",1,"2024-01-01 11:00:00","2024-01-01 11:00:00","2.0","[company_phone];[contact_company_phone]"
date3,"24th Feb 2026",general,"Math Olympiad",0,"2024-01-01 12:00:00","2024-01-01 12:00:00","2.0","[date3];[math_olympiad_date3];[mo_date3]"</pre>

                        <h4><?php _e('Enhanced CSV Features (v2.0):', 'wp-dynamic-tags'); ?></h4>
                        <ul style="font-size: 13px;">
                            <li><?php _e('✅ Standardized column structure for consistent imports/exports', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ UTF-8 encoding with BOM for Excel compatibility', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Automatic group creation during import', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Enhanced duplicate detection and resolution', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Shortcode pattern documentation for each tag', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Comprehensive validation with detailed error reporting', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Smart alias generation for common naming patterns', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('✅ Export versioning for compatibility tracking', 'wp-dynamic-tags'); ?></li>
                        </ul>

                        <h4><?php _e('CSV Import Requirements:', 'wp-dynamic-tags'); ?></h4>
                        <ul style="font-size: 13px;">
                            <li><?php _e('First row must contain headers', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('Required columns: tag_key, tag_value', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('Optional columns: category, groups, priority', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('Groups should be semicolon-separated (Group1;Group2)', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('Use quotes around values containing commas or semicolons', 'wp-dynamic-tags'); ?></li>
                            <li><?php _e('UTF-8 encoding recommended for special characters', 'wp-dynamic-tags'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Export JSON
            $('#export-json-btn').on('click', function() {
                var includeMetadata = $('#include-metadata').is(':checked');
                var includeUsage = $('#include-usage-stats').is(':checked');
                
                window.location.href = ajaxurl + '?action=dt_export_json&include_metadata=' + includeMetadata + 
                                     '&include_usage=' + includeUsage + '&nonce=' + '<?php echo wp_create_nonce('dt_export'); ?>';
            });
            
            // Export CSV
            $('#export-csv-btn').on('click', function() {
                var includeMetadata = $('#include-metadata').is(':checked');
                var includeUsage = $('#include-usage-stats').is(':checked');
                var exportCategory = $('#export-category').val();
                var exportGroup = $('#export-group').val();

                var url = ajaxurl + '?action=dt_export_csv&include_metadata=' + includeMetadata +
                         '&include_usage=' + includeUsage +
                         '&export_category=' + encodeURIComponent(exportCategory) +
                         '&export_group=' + encodeURIComponent(exportGroup) +
                         '&nonce=' + '<?php echo wp_create_nonce('dt_export'); ?>';

                window.location.href = url;
            });
            
            // Enhanced file upload validation and preview
            $('#import-file').on('change', function() {
                var file = this.files[0];
                var $fileInfo = $('#file-info');
                var $fileDetails = $('#file-details');
                var $previewBtn = $('#preview-import-btn');
                var $importBtn = $('input[name="import_tags"]');

                if (file) {
                    var extension = file.name.split('.').pop().toLowerCase();
                    var fileSize = (file.size / 1024 / 1024).toFixed(2); // MB

                    if (extension !== 'json' && extension !== 'csv') {
                        alert('<?php _e('Please select a JSON or CSV file.', 'wp-dynamic-tags'); ?>');
                        this.value = '';
                        $fileInfo.hide();
                        $previewBtn.prop('disabled', true);
                        $importBtn.prop('disabled', true);
                        return;
                    }

                    if (file.size > 32 * 1024 * 1024) { // 32MB limit
                        alert('<?php _e('File size exceeds 32MB limit. Please choose a smaller file.', 'wp-dynamic-tags'); ?>');
                        this.value = '';
                        $fileInfo.hide();
                        $previewBtn.prop('disabled', true);
                        $importBtn.prop('disabled', true);
                        return;
                    }

                    $fileDetails.html(
                        '<div><strong><?php _e('File:', 'wp-dynamic-tags'); ?></strong> ' + file.name + '</div>' +
                        '<div><strong><?php _e('Size:', 'wp-dynamic-tags'); ?></strong> ' + fileSize + ' MB</div>' +
                        '<div><strong><?php _e('Type:', 'wp-dynamic-tags'); ?></strong> ' + extension.toUpperCase() + '</div>'
                    );
                    $fileInfo.show();
                    $previewBtn.prop('disabled', false);

                    // Enable import button only if preview is not required
                    if (!$('input[name="preview_import"]').is(':checked')) {
                        $importBtn.prop('disabled', false);
                    }
                } else {
                    $fileInfo.hide();
                    $previewBtn.prop('disabled', true);
                    $importBtn.prop('disabled', true);
                }
            });

            // Preview import functionality
            $('#preview-import-btn').on('click', function() {
                var file = $('#import-file')[0].files[0];
                if (!file) return;

                var $previewDiv = $('#import-preview');
                var $previewContent = $('#preview-content');
                var $previewValidation = $('#preview-validation');

                $previewContent.html('<p><?php _e('Processing file...', 'wp-dynamic-tags'); ?></p>');
                $previewValidation.html('');
                $previewDiv.show();

                var reader = new FileReader();
                reader.onload = function(e) {
                    var content = e.target.result;
                    var extension = file.name.split('.').pop().toLowerCase();

                    try {
                        var previewData = parseImportFile(content, extension);
                        displayPreview(previewData);
                        $('input[name="import_tags"]').prop('disabled', false);
                    } catch (error) {
                        $previewContent.html('<p style="color: red;"><?php _e('Error:', 'wp-dynamic-tags'); ?> ' + error.message + '</p>');
                        $('input[name="import_tags"]').prop('disabled', true);
                    }
                };
                reader.readAsText(file);
            });

            function parseImportFile(content, extension) {
                var data = [];
                var errors = [];

                // Strip UTF-8 BOM (Byte Order Mark) if present
                // BOM is added by export for Excel compatibility but breaks header detection
                if (content.charCodeAt(0) === 0xFEFF) {
                    content = content.substr(1);
                }

                if (extension === 'json') {
                    try {
                        var jsonData = JSON.parse(content);
                        if (jsonData.tags && Array.isArray(jsonData.tags)) {
                            data = jsonData.tags;
                        } else {
                            throw new Error('<?php _e('Invalid JSON structure. Expected "tags" array.', 'wp-dynamic-tags'); ?>');
                        }
                    } catch (e) {
                        throw new Error('<?php _e('Invalid JSON format:', 'wp-dynamic-tags'); ?> ' + e.message);
                    }
                } else if (extension === 'csv') {
                    // Normalize line endings to handle Windows (CRLF), Unix (LF), and Mac (CR)
                    var normalizedContent = content.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    var lines = normalizedContent.split('\n');

                    // Filter out empty lines
                    lines = lines.filter(function(line) {
                        return line.trim() !== '';
                    });

                    if (lines.length < 2) {
                        throw new Error('<?php _e('CSV file must contain at least header and one data row.', 'wp-dynamic-tags'); ?>');
                    }

                    var headers = lines[0].split(',').map(h => h.trim().replace(/\"/g, ''));
                    if (!headers.includes('tag_key') || !headers.includes('tag_value')) {
                        throw new Error('<?php _e('CSV must contain tag_key and tag_value columns.', 'wp-dynamic-tags'); ?>');
                    }

                    for (var i = 1; i < lines.length; i++) {
                        if (lines[i].trim() === '') continue;

                        var values = parseCSVLine(lines[i]);
                        if (values.length >= 2) {
                            var record = {};
                            for (var j = 0; j < headers.length && j < values.length; j++) {
                                record[headers[j]] = values[j];
                            }
                            data.push(record);
                        }
                    }
                }

                return {
                    data: data,
                    errors: errors,
                    total: data.length
                };
            }

            function parseCSVLine(line) {
                var result = [];
                var current = '';
                var inQuotes = false;

                for (var i = 0; i < line.length; i++) {
                    var char = line[i];
                    if (char === '\"') {
                        inQuotes = !inQuotes;
                    } else if (char === ',' && !inQuotes) {
                        result.push(current.trim());
                        current = '';
                    } else {
                        current += char;
                    }
                }
                result.push(current.trim());
                return result;
            }

            function displayPreview(previewData) {
                var $previewContent = $('#preview-content');
                var $previewValidation = $('#preview-validation');

                var html = '<p><strong><?php _e('Found:', 'wp-dynamic-tags'); ?></strong> ' + previewData.total + ' <?php _e('tags to import', 'wp-dynamic-tags'); ?></p>';

                if (previewData.data.length > 0) {
                    html += '<table class=\"wp-list-table widefat fixed striped\" style=\"margin-top: 10px;\">';
                    html += '<thead><tr><th><?php _e('Tag Key', 'wp-dynamic-tags'); ?></th><th><?php _e('Tag Value', 'wp-dynamic-tags'); ?></th><th><?php _e('Category', 'wp-dynamic-tags'); ?></th></tr></thead>';
                    html += '<tbody>';

                    var previewCount = Math.min(5, previewData.data.length);
                    for (var i = 0; i < previewCount; i++) {
                        var item = previewData.data[i];
                        html += '<tr>';
                        html += '<td>' + (item.tag_key || item.key || '<?php _e('N/A', 'wp-dynamic-tags'); ?>') + '</td>';
                        html += '<td>' + ((item.tag_value || item.value || '').substring(0, 50) + '...') + '</td>';
                        html += '<td>' + (item.category || '<?php _e('general', 'wp-dynamic-tags'); ?>') + '</td>';
                        html += '</tr>';
                    }

                    if (previewData.data.length > 5) {
                        html += '<tr><td colspan=\"3\"><em><?php _e('...and', 'wp-dynamic-tags'); ?> ' + (previewData.data.length - 5) + ' <?php _e('more rows', 'wp-dynamic-tags'); ?></em></td></tr>';
                    }
                    html += '</tbody></table>';
                }

                $previewContent.html(html);

                // Validation messages
                var validationHtml = '';
                if (previewData.errors.length > 0) {
                    validationHtml += '<div style=\"color: red; margin-top: 10px;\"><strong><?php _e('Validation Errors:', 'wp-dynamic-tags'); ?></strong><ul>';
                    previewData.errors.forEach(function(error) {
                        validationHtml += '<li>' + error + '</li>';
                    });
                    validationHtml += '</ul></div>';
                } else {
                    validationHtml += '<div style=\"color: green; margin-top: 10px;\"><strong><?php _e('✓ File validation passed', 'wp-dynamic-tags'); ?></strong></div>';
                }

                $previewValidation.html(validationHtml);
            }

            // Toggle import button based on preview requirement
            $('input[name=\"preview_import\"]').on('change', function() {
                var $importBtn = $('input[name=\"import_tags\"]');
                var $previewBtn = $('#preview-import-btn');
                var hasFile = $('#import-file')[0].files.length > 0;

                if (this.checked) {
                    if (hasFile && !$previewBtn.prop('disabled')) {
                        $importBtn.prop('disabled', true);
                    }
                } else {
                    if (hasFile) {
                        $importBtn.prop('disabled', false);
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle JSON export
     */
    public function export_json() {
        check_admin_referer('dt_export', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $include_metadata = isset($_GET['include_metadata']) && $_GET['include_metadata'] === 'true';
        $include_usage = isset($_GET['include_usage']) && $_GET['include_usage'] === 'true';
        
        $tags = $this->get_all_tags_for_export_array();
        
        $export_data = array(
            'version' => '1.0',
            'export_date' => current_time('c'),
            'total_tags' => count($tags),
            'site_url' => get_site_url(),
            'tags' => array()
        );
        
        foreach ($tags as $tag) {
            $tag_data = array(
                'key' => $this->sanitize_tag_key($tag->post_title),
                'value' => $tag->post_content
            );
            
            if ($include_metadata) {
                $tag_data['category'] = get_post_meta($tag->ID, '_dt_category', true) ?: 'general';
                $tag_data['created_date'] = get_post_time('c', false, $tag);
                $tag_data['modified_date'] = get_post_modified_time('c', false, $tag);
                $tag_data['priority'] = get_post_meta($tag->ID, '_dt_priority', true) ?: 0;

                // Include groups
                $groups = wp_get_post_terms($tag->ID, 'tag_groups');
                $tag_data['groups'] = array();
                foreach ($groups as $group) {
                    $tag_data['groups'][] = array(
                        'id' => $group->term_id,
                        'name' => $group->name,
                        'slug' => $group->slug,
                        'description' => $group->description,
                        'color' => get_term_meta($group->term_id, 'color', true) ?: '#2271b1'
                    );
                }
            }
            
            if ($include_usage) {
                $tag_data['usage_count'] = get_post_meta($tag->ID, '_dt_usage_count', true) ?: 0;
            }
            
            $export_data['tags'][] = $tag_data;
        }
        
        $filename = 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.json';
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Log export activity
        $this->log_activity('export', array(
            'format' => 'json',
            'total_tags' => count($tags),
            'exported_count' => count($export_data['tags']),
            'include_metadata' => $include_metadata,
            'include_usage' => $include_usage
        ));

        exit;
    }
    
    /**
     * Handle CSV export with enhanced options and validation
     */
    public function export_csv() {
        check_admin_referer('dt_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $include_metadata = isset($_GET['include_metadata']) && $_GET['include_metadata'] === 'true';
        $include_usage = isset($_GET['include_usage']) && $_GET['include_usage'] === 'true';
        $export_category = isset($_GET['export_category']) ? sanitize_text_field($_GET['export_category']) : '';
        $export_group = isset($_GET['export_group']) ? sanitize_text_field($_GET['export_group']) : '';

        // Validate and get filtered tags
        $tags = $this->get_filtered_tags_for_export($export_category, $export_group);

        if (empty($tags)) {
            wp_die(__('No tags found matching the export criteria.', 'wp-dynamic-tags'));
        }

        // Validate data before export
        $validation_result = $this->validate_export_data($tags);
        if (!$validation_result['valid']) {
            wp_die(__('Export validation failed: ', 'wp-dynamic-tags') . $validation_result['message']);
        }

        $filename = 'dynamic-tags-export-' . date('Y-m-d-H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $output = fopen('php://output', 'w');

        // Add BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Standardized CSV Headers - Always include all columns for consistency
        $headers = array(
            'tag_key',
            'tag_value',
            'category',
            'groups',
            'priority',
            'created_date',
            'modified_date'
        );

        // Add optional usage columns if requested
        if ($include_usage) {
            $headers[] = 'usage_count';
            $headers[] = 'last_used';
        }

        // Add export metadata columns for enhanced tracking
        $headers[] = 'export_version';
        $headers[] = 'shortcode_patterns';

        fputcsv($output, $headers);

        // Export data with enhanced validation
        $exported_count = 0;
        foreach ($tags as $tag) {
            try {
                $tag_key = $this->sanitize_tag_key($tag->post_title);

                // Skip if tag key is invalid
                if (empty($tag_key)) {
                    continue;
                }

                // Build standardized row with all required columns
                $category = get_post_meta($tag->ID, '_dt_category', true) ?: 'general';

                // Enhanced group handling with validation
                $groups = wp_get_post_terms($tag->ID, 'tag_groups');
                $group_names = array();
                if (!is_wp_error($groups) && !empty($groups)) {
                    foreach ($groups as $group) {
                        if (isset($group->name) && !empty($group->name)) {
                            $group_names[] = $this->sanitize_csv_content($group->name);
                        }
                    }
                }

                $priority = get_post_meta($tag->ID, '_dt_priority', true);
                $priority = is_numeric($priority) ? intval($priority) : 0;

                // Generate shortcode patterns for this tag
                $shortcode_patterns = $this->get_tag_shortcode_patterns($tag->ID, $tag_key, $group_names);

                // Standard row structure (always includes all columns)
                $row = array(
                    $tag_key,
                    $this->sanitize_csv_content($tag->post_content),
                    $category,
                    implode(';', $group_names),
                    $priority,
                    get_post_time('Y-m-d H:i:s', false, $tag),
                    get_post_modified_time('Y-m-d H:i:s', false, $tag)
                );

                // Add optional usage statistics
                if ($include_usage) {
                    $usage_count = get_post_meta($tag->ID, '_dt_usage_count', true) ?: 0;
                    $last_used = get_post_meta($tag->ID, '_dt_last_used', true) ?: '';

                    $row[] = intval($usage_count);
                    $row[] = $last_used ? date('Y-m-d H:i:s', strtotime($last_used)) : '';
                }

                // Add export metadata columns
                $row[] = '2.0'; // Export version for compatibility tracking
                $row[] = implode(';', $shortcode_patterns);

                fputcsv($output, $row);
                $exported_count++;

            } catch (Exception $e) {
                // Log error but continue with other tags
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('CSV Export Error for tag ID ' . $tag->ID . ': ' . $e->getMessage());
                }
                continue;
            }
        }

        fclose($output);

        // Log export activity
        $this->log_activity('export', array(
            'format' => 'csv',
            'total_tags' => count($tags),
            'exported_count' => $exported_count,
            'include_metadata' => $include_metadata,
            'include_usage' => $include_usage,
            'filters' => array(
                'category' => $export_category,
                'group' => $export_group
            )
        ));

        exit;
    }
    
    /**
     * Handle import with enhanced validation and logging
     */
    public function handle_import() {
        if (!isset($_POST['import_tags']) || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('dt_import_tags', 'dt_import_nonce');

        // Validate file upload
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error_messages = array(
                UPLOAD_ERR_INI_SIZE => __('File size exceeds PHP upload limit.', 'wp-dynamic-tags'),
                UPLOAD_ERR_FORM_SIZE => __('File size exceeds form limit.', 'wp-dynamic-tags'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded.', 'wp-dynamic-tags'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'wp-dynamic-tags'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary upload directory.', 'wp-dynamic-tags'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'wp-dynamic-tags'),
                UPLOAD_ERR_EXTENSION => __('File upload stopped by extension.', 'wp-dynamic-tags')
            );

            $error_code = $_FILES['import_file']['error'];
            $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : __('Unknown upload error.', 'wp-dynamic-tags');

            add_action('admin_notices', function() use ($error_message) {
                echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            });
            return;
        }

        $file = $_FILES['import_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Validate file type
        if (!in_array($extension, array('json', 'csv'))) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('Unsupported file format. Please upload JSON or CSV files only.', 'wp-dynamic-tags') . '</p></div>';
            });
            return;
        }

        // Validate file size (32MB limit)
        if ($file['size'] > 32 * 1024 * 1024) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . __('File size exceeds 32MB limit.', 'wp-dynamic-tags') . '</p></div>';
            });
            return;
        }

        // Get import options
        $conflict_resolution = sanitize_text_field($_POST['conflict_resolution']);
        $validate_keys = isset($_POST['validate_keys']);
        $create_backup = isset($_POST['create_backup']);
        $detailed_logging = isset($_POST['detailed_logging']);

        // Create backup if requested
        $backup_created = false;
        if ($create_backup) {
            $backup_result = $this->create_backup();
            $backup_created = $backup_result['success'];
            if (!$backup_created) {
                add_action('admin_notices', function() use ($backup_result) {
                    echo '<div class="notice notice-warning"><p>' . __('Warning: Backup creation failed - ', 'wp-dynamic-tags') . esc_html($backup_result['message']) . '</p></div>';
                });
            }
        }

        // Process import based on file type
        $start_time = microtime(true);
        try {
            if ($extension === 'json') {
                $result = $this->import_json_enhanced($file['tmp_name'], $conflict_resolution, $validate_keys, $detailed_logging);
            } else {
                $result = $this->import_csv_enhanced($file['tmp_name'], $conflict_resolution, $validate_keys, $detailed_logging);
            }

            $result['processing_time'] = round(microtime(true) - $start_time, 2);
            $result['backup_created'] = $backup_created;

        } catch (Exception $e) {
            $result = array(
                'success' => false,
                'message' => __('Import failed: ', 'wp-dynamic-tags') . $e->getMessage(),
                'processing_time' => round(microtime(true) - $start_time, 2),
                'backup_created' => $backup_created
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags Import Error: ' . $e->getMessage());
            }
        }

        // Clear cache
        delete_transient($this->transient_key);

        // Log import activity with enhanced data
        $this->log_activity('import', $result);

        // Show enhanced result with detailed feedback
        add_action('admin_notices', function() use ($result) {
            $this->show_enhanced_import_notice($result);
        });
    }
    
    /**
     * Import JSON file
     */
    private function import_json($file_path, $conflict_resolution, $validate_keys) {
        $content = file_get_contents($file_path);
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'message' => __('Invalid JSON file.', 'wp-dynamic-tags'));
        }
        
        if (!isset($data['tags']) || !is_array($data['tags'])) {
            return array('success' => false, 'message' => __('Invalid JSON structure.', 'wp-dynamic-tags'));
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        foreach ($data['tags'] as $tag_data) {
            if (!isset($tag_data['key']) || !isset($tag_data['value'])) {
                $errors++;
                continue;
            }
            
            $key = $validate_keys ? $this->sanitize_tag_key($tag_data['key']) : $tag_data['key'];
            $value = $tag_data['value'];
            $category = isset($tag_data['category']) ? $tag_data['category'] : 'general';
            $groups = isset($tag_data['groups']) ? $tag_data['groups'] : array();
            $priority = isset($tag_data['priority']) ? intval($tag_data['priority']) : 0;

            $result = $this->import_single_tag($key, $value, $category, $conflict_resolution, $groups, $priority);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }
        
        $message = sprintf(
            __('Import completed: %d imported, %d skipped, %d errors.', 'wp-dynamic-tags'),
            $imported, $skipped, $errors
        );
        
        return array(
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }
    
    /**
     * Import CSV file
     */
    private function import_csv($file_path, $conflict_resolution, $validate_keys) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return array('success' => false, 'message' => __('Cannot read CSV file.', 'wp-dynamic-tags'));
        }
        
        $headers = fgetcsv($handle);
        if (!$headers || !in_array('tag_key', $headers) || !in_array('tag_value', $headers)) {
            fclose($handle);
            return array('success' => false, 'message' => __('CSV must contain tag_key and tag_value columns.', 'wp-dynamic-tags'));
        }
        
        $key_index = array_search('tag_key', $headers);
        $value_index = array_search('tag_value', $headers);
        $category_index = array_search('category', $headers);
        $groups_index = array_search('groups', $headers);
        $priority_index = array_search('priority', $headers);
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) <= max($key_index, $value_index)) {
                $errors++;
                continue;
            }
            
            $key = $validate_keys ? $this->sanitize_tag_key($row[$key_index]) : $row[$key_index];
            $value = $row[$value_index];
            $category = ($category_index !== false && isset($row[$category_index])) ? $row[$category_index] : 'general';

            // Parse groups from CSV (semicolon-separated)
            $groups = array();
            if ($groups_index !== false && isset($row[$groups_index]) && !empty($row[$groups_index])) {
                $group_names = explode(';', $row[$groups_index]);
                foreach ($group_names as $group_name) {
                    $group_name = trim($group_name);
                    if (!empty($group_name)) {
                        $groups[] = array('name' => $group_name);
                    }
                }
            }

            $priority = ($priority_index !== false && isset($row[$priority_index])) ? intval($row[$priority_index]) : 0;

            $result = $this->import_single_tag($key, $value, $category, $conflict_resolution, $groups, $priority);
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }
        
        fclose($handle);
        
        $message = sprintf(
            __('Import completed: %d imported, %d skipped, %d errors.', 'wp-dynamic-tags'),
            $imported, $skipped, $errors
        );
        
        return array(
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }
    
    /**
     * Enhanced single tag import with detailed tracking
     */
    private function import_single_tag($key, $value, $category, $conflict_resolution, $groups = array(), $priority = 0, $source_data = array()) {
        if (empty($key) || empty($value)) {
            return 'error';
        }
        
        // Check if tag exists
        $existing = get_posts(array(
            'post_type' => $this->post_type,
            'title' => $key,
            'post_status' => 'publish',
            'posts_per_page' => 1
        ));
        
        if (!empty($existing)) {
            switch ($conflict_resolution) {
                case 'skip':
                    return 'skipped';
                    
                case 'update':
                    wp_update_post(array(
                        'ID' => $existing[0]->ID,
                        'post_content' => $value
                    ));
                    update_post_meta($existing[0]->ID, '_dt_category', $category);
                    update_post_meta($existing[0]->ID, '_dt_priority', $priority);
                    $group_result = $this->assign_groups_to_tag($existing[0]->ID, $groups);

                    // Log group assignment results if enabled
                    if (!empty($group_result['errors']) && defined('WP_DEBUG') && WP_DEBUG) {
                        foreach ($group_result['errors'] as $error) {
                            error_log("WP Dynamic Tags Group Assignment Error: " . $error);
                        }
                    }


                    return 'updated';
                    
                case 'rename':
                    $key = $this->get_unique_key($key);
                    break;
            }
        }
        
        // Create new tag
        $post_id = wp_insert_post(array(
            'post_title' => $key,
            'post_content' => $value,
            'post_status' => 'publish',
            'post_type' => $this->post_type
        ));
        
        if ($post_id) {
            update_post_meta($post_id, '_dt_category', $category);
            update_post_meta($post_id, '_dt_priority', $priority);

            // Enhanced group assignment with tracking
            $group_result = $this->assign_groups_to_tag($post_id, $groups);

            // Log group assignment results if enabled
            if (!empty($group_result['errors']) && defined('WP_DEBUG') && WP_DEBUG) {
                foreach ($group_result['errors'] as $error) {
                    error_log("WP Dynamic Tags Group Assignment Error: " . $error);
                }
            }

            // Store import metadata for tracking
            if (!empty($source_data)) {
                update_post_meta($post_id, '_dt_imported_from', 'csv');
                update_post_meta($post_id, '_dt_import_date', current_time('mysql'));
                if (isset($source_data['line_number'])) {
                    update_post_meta($post_id, '_dt_import_line', $source_data['line_number']);
                }

            }

            return 'imported';
        }
        
        return 'error';
    }

    /**
     * Enhanced group assignment with auto-creation and validation
     */
    private function assign_groups_to_tag($post_id, $groups) {
        if (empty($groups)) {
            return array('assigned' => 0, 'created' => 0, 'errors' => array());
        }

        $group_ids = array();
        $created_groups = 0;
        $assignment_errors = array();

        foreach ($groups as $group_data) {
            try {
                // Handle different group data formats
                if (is_array($group_data)) {
                    $group_name = isset($group_data['name']) ? trim($group_data['name']) : '';
                    $group_slug = isset($group_data['slug']) ? trim($group_data['slug']) : '';
                    $group_color = isset($group_data['color']) ? $group_data['color'] : $this->get_random_group_color();
                    $group_description = isset($group_data['description']) ? trim($group_data['description']) : '';
                } else {
                    // Simple string format
                    $group_name = trim((string) $group_data);
                    $group_slug = '';
                    $group_color = $this->get_random_group_color();
                    $group_description = '';
                }

                if (empty($group_name)) {
                    continue;
                }

                // Validate group name length
                if (strlen($group_name) > 200) {
                    $assignment_errors[] = "Group name '{$group_name}' exceeds 200 characters";
                    continue;
                }

                // Try to find existing group by name or slug
                $existing_group = get_term_by('name', $group_name, 'tag_groups');
                if (!$existing_group && !empty($group_slug)) {
                    $existing_group = get_term_by('slug', $group_slug, 'tag_groups');
                }

                if ($existing_group) {
                    $group_ids[] = $existing_group->term_id;
                } else {
                    // Create new group with enhanced validation
                    $insert_args = array(
                        'description' => $group_description
                    );

                    // Generate unique slug if not provided
                    if (!empty($group_slug)) {
                        $insert_args['slug'] = $group_slug;
                    } else {
                        $insert_args['slug'] = $this->generate_group_slug($group_name);
                    }

                    $result = wp_insert_term($group_name, 'tag_groups', $insert_args);

                    if (!is_wp_error($result)) {
                        $group_ids[] = $result['term_id'];
                        $created_groups++;

                        // Set group color with validation
                        if (!empty($group_color) && $this->is_valid_color($group_color)) {
                            update_term_meta($result['term_id'], 'color', $group_color);
                        } else {
                            update_term_meta($result['term_id'], 'color', $this->get_random_group_color());
                        }

                        // Log group creation
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("WP Dynamic Tags: Created new group '{$group_name}' (ID: {$result['term_id']})");
                        }
                    } else {
                        $assignment_errors[] = "Failed to create group '{$group_name}': " . $result->get_error_message();
                    }
                }

            } catch (Exception $e) {
                $assignment_errors[] = "Error processing group '{$group_name}': " . $e->getMessage();
            }
        }

        // Assign groups to the tag
        $assigned_count = 0;
        if (!empty($group_ids)) {
            $assign_result = wp_set_post_terms($post_id, $group_ids, 'tag_groups');
            if (!is_wp_error($assign_result)) {
                $assigned_count = count($group_ids);
            } else {
                $assignment_errors[] = "Failed to assign groups to tag: " . $assign_result->get_error_message();
            }
        }

        return array(
            'assigned' => $assigned_count,
            'created' => $created_groups,
            'errors' => $assignment_errors
        );
    }

    /**
     * Generate a unique slug for a group
     */
    private function generate_group_slug($group_name) {
        $base_slug = sanitize_title($group_name);
        if (empty($base_slug)) {
            $base_slug = 'group-' . uniqid();
        }

        $slug = $base_slug;
        $counter = 1;

        // Ensure uniqueness
        while (term_exists($slug, 'tag_groups')) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get a random color for new groups
     */
    private function get_random_group_color() {
        $colors = array(
            '#2271b1', '#d63638', '#00a32a', '#dba617',
            '#8e44ad', '#e67e22', '#1abc9c', '#34495e',
            '#e91e63', '#9c27b0', '#673ab7', '#3f51b5',
            '#2196f3', '#03a9f4', '#00bcd4', '#009688'
        );
        return $colors[array_rand($colors)];
    }

    /**
     * Validate if a string is a valid color
     */
    private function is_valid_color($color) {
        // Check for hex color format
        if (preg_match('/^#[a-f0-9]{6}$/i', $color)) {
            return true;
        }
        // Check for rgb/rgba format
        if (preg_match('/^rgba?\([0-9\s,\.]+\)$/i', $color)) {
            return true;
        }
        return false;
    }

    /**
     * Get unique key for renamed imports
     */
    private function get_unique_key($base_key) {
        $counter = 1;
        $new_key = $base_key;
        
        while (get_posts(array(
            'post_type' => $this->post_type,
            'title' => $new_key,
            'post_status' => 'publish',
            'posts_per_page' => 1
        ))) {
            $new_key = $base_key . '_' . $counter;
            $counter++;
        }
        
        return $new_key;
    }
    
    /**
     * Create backup with enhanced error handling
     */
    private function create_backup() {
        try {
            $tags = $this->get_all_tags_for_export_array();

            $backup_data = array(
                'version' => '1.0',
                'backup_date' => current_time('c'),
                'backup_type' => 'pre_import',
                'total_tags' => count($tags),
                'tags' => array()
            );

            foreach ($tags as $tag) {
                $backup_data['tags'][] = array(
                    'key' => $this->sanitize_tag_key($tag->post_title),
                    'value' => $tag->post_content,
                    'category' => get_post_meta($tag->ID, '_dt_category', true) ?: 'general',
                    'priority' => get_post_meta($tag->ID, '_dt_priority', true) ?: 0,
                    'created_date' => get_post_time('c', false, $tag),
                    'modified_date' => get_post_modified_time('c', false, $tag)
                );
            }

            $upload_dir = wp_upload_dir();
            if (is_wp_error($upload_dir)) {
                throw new Exception(__('Unable to access upload directory: ', 'wp-dynamic-tags') . $upload_dir->get_error_message());
            }

            $backup_dir = $upload_dir['basedir'] . '/dynamic-tags-backups';

            if (!file_exists($backup_dir)) {
                if (!wp_mkdir_p($backup_dir)) {
                    throw new Exception(__('Unable to create backup directory.', 'wp-dynamic-tags'));
                }
            }

            if (!is_writable($backup_dir)) {
                throw new Exception(__('Backup directory is not writable.', 'wp-dynamic-tags'));
            }

            $filename = 'backup-' . date('Y-m-d-H-i-s') . '.json';
            $filepath = $backup_dir . '/' . $filename;

            $json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Failed to encode backup data to JSON.', 'wp-dynamic-tags'));
            }

            $bytes_written = file_put_contents($filepath, $json_data);
            if ($bytes_written === false) {
                throw new Exception(__('Failed to write backup file.', 'wp-dynamic-tags'));
            }

            update_option('dt_last_backup', array(
                'date' => current_time('c'),
                'file' => $filename,
                'path' => $filepath,
                'size' => $bytes_written,
                'tags_count' => count($tags)
            ));

            return array(
                'success' => true,
                'message' => sprintf(__('Backup created successfully: %s (%d tags)', 'wp-dynamic-tags'), $filename, count($tags)),
                'file' => $filename,
                'path' => $filepath
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Enhanced JSON import with better validation and logging
     */
    private function import_json_enhanced($file_path, $conflict_resolution, $validate_keys, $detailed_logging = false) {
        $content = file_get_contents($file_path);
        if ($content === false) {
            throw new Exception(__('Unable to read import file.', 'wp-dynamic-tags'));
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON file: ', 'wp-dynamic-tags') . json_last_error_msg());
        }

        if (!isset($data['tags']) || !is_array($data['tags'])) {
            throw new Exception(__('Invalid JSON structure. Expected "tags" array.', 'wp-dynamic-tags'));
        }

        return $this->process_import_data($data['tags'], $conflict_resolution, $validate_keys, $detailed_logging, 'JSON', array());
    }

    /**
     * Enhanced CSV import with better validation and logging
     */
    private function import_csv_enhanced($file_path, $conflict_resolution, $validate_keys, $detailed_logging = false) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception(__('Cannot read CSV file.', 'wp-dynamic-tags'));
        }

        $headers = fgetcsv($handle);

        // Strip UTF-8 BOM from first header if present
        // BOM is added during export for Excel compatibility but breaks header detection
        if (!empty($headers[0])) {
            $headers[0] = $this->remove_utf8_bom($headers[0]);
        }

        if (!$headers || !in_array('tag_key', $headers) || !in_array('tag_value', $headers)) {
            fclose($handle);
            throw new Exception(__('CSV must contain tag_key and tag_value columns.', 'wp-dynamic-tags'));
        }

        // Enhanced header detection for all supported columns
        $column_mapping = array(
            'key_index' => array_search('tag_key', $headers),
            'value_index' => array_search('tag_value', $headers),
            'category_index' => array_search('category', $headers),
            'groups_index' => array_search('groups', $headers),
            'priority_index' => array_search('priority', $headers),
            'created_date_index' => array_search('created_date', $headers),
            'modified_date_index' => array_search('modified_date', $headers)
        );

        // Validate required columns
        if ($column_mapping['key_index'] === false || $column_mapping['value_index'] === false) {
            fclose($handle);
            throw new Exception(__('CSV must contain tag_key and tag_value columns.', 'wp-dynamic-tags'));
        }

        $import_data = array();
        $line_number = 1;
        $validation_errors = array();
        $duplicate_keys = array();

        // Pre-validation pass to detect duplicates within the CSV
        while (($row = fgetcsv($handle)) !== false) {
            $line_number++;

            if (empty($row) || count($row) <= max($column_mapping['key_index'], $column_mapping['value_index'])) {
                if ($detailed_logging) {
                    $validation_errors[] = "Line {$line_number}: Insufficient columns or empty row";
                }
                continue;
            }

            $key = trim($row[$column_mapping['key_index']]);
            $value = trim($row[$column_mapping['value_index']]);

            // Basic validation
            if (empty($key) || empty($value)) {
                $validation_errors[] = "Line {$line_number}: Missing tag_key or tag_value";
                continue;
            }

            // Check for CSV duplicates
            $sanitized_key = $validate_keys ? $this->sanitize_tag_key($key) : $key;
            if (isset($duplicate_keys[$sanitized_key])) {
                $validation_errors[] = "Line {$line_number}: Duplicate key '{$key}' found (first occurrence at line {$duplicate_keys[$sanitized_key]})";
            } else {
                $duplicate_keys[$sanitized_key] = $line_number;
            }

            $record = array(
                'key' => $key,
                'value' => $value,
                'line_number' => $line_number
            );

            // Parse optional columns with enhanced validation
            if ($column_mapping['category_index'] !== false && isset($row[$column_mapping['category_index']])) {
                $category = trim($row[$column_mapping['category_index']]);
                $record['category'] = !empty($category) ? $category : 'general';
            } else {
                $record['category'] = 'general';
            }

            // Enhanced group parsing with validation
            if ($column_mapping['groups_index'] !== false && isset($row[$column_mapping['groups_index']]) && !empty($row[$column_mapping['groups_index']])) {
                $groups_string = trim($row[$column_mapping['groups_index']]);
                $group_names = explode(';', $groups_string);
                $record['groups'] = array();

                foreach ($group_names as $group_name) {
                    $group_name = trim($group_name);
                    if (!empty($group_name)) {
                        // Validate group name
                        if (strlen($group_name) > 200) {
                            $validation_errors[] = "Line {$line_number}: Group name '{$group_name}' exceeds 200 characters";
                        } else {
                            $record['groups'][] = array('name' => $group_name);
                        }
                    }
                }
            } else {
                $record['groups'] = array();
            }

            // Enhanced priority validation
            if ($column_mapping['priority_index'] !== false && isset($row[$column_mapping['priority_index']])) {
                $priority = trim($row[$column_mapping['priority_index']]);
                if (is_numeric($priority)) {
                    $record['priority'] = max(0, min(999, intval($priority))); // Limit to 0-999
                } else {
                    $record['priority'] = 0;
                    if (!empty($priority)) {
                        $validation_errors[] = "Line {$line_number}: Invalid priority value '{$priority}', using 0";
                    }
                }
            } else {
                $record['priority'] = 0;
            }

            // Parse date fields (for reference, not used in import)
            if ($column_mapping['created_date_index'] !== false && isset($row[$column_mapping['created_date_index']])) {
                $record['created_date'] = trim($row[$column_mapping['created_date_index']]);
            }
            if ($column_mapping['modified_date_index'] !== false && isset($row[$column_mapping['modified_date_index']])) {
                $record['modified_date'] = trim($row[$column_mapping['modified_date_index']]);
            }


            $import_data[] = $record;
        }

        fclose($handle);

        if (empty($import_data)) {
            throw new Exception(__('No valid data rows found in CSV file.', 'wp-dynamic-tags'));
        }

        // Log validation errors
        if (!empty($validation_errors) && $detailed_logging) {
            foreach ($validation_errors as $error) {
                error_log("WP Dynamic Tags CSV Validation: " . $error);
            }
        }

        return $this->process_import_data($import_data, $conflict_resolution, $validate_keys, $detailed_logging, 'CSV', $validation_errors);
    }

    /**
     * Process import data with enhanced validation and progress tracking
     */
    private function process_import_data($import_data, $conflict_resolution, $validate_keys, $detailed_logging, $format, $validation_errors = array()) {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $updated = 0;
        $error_details = array();

        $total_items = count($import_data);
        $current_item = 0;

        foreach ($import_data as $tag_data) {
            $current_item++;

            try {
                // Extract key and value based on format
                $key = isset($tag_data['key']) ? $tag_data['key'] : (isset($tag_data['tag_key']) ? $tag_data['tag_key'] : '');
                $value = isset($tag_data['value']) ? $tag_data['value'] : (isset($tag_data['tag_value']) ? $tag_data['tag_value'] : '');

                if (empty($key) || empty($value)) {
                    $errors++;
                    if ($detailed_logging) {
                        $line_ref = isset($tag_data['line_number']) ? " (line {$tag_data['line_number']})" : " (item {$current_item})";
                        $error_details[] = "Missing key or value{$line_ref}";
                    }
                    continue;
                }

                $sanitized_key = $validate_keys ? $this->sanitize_tag_key($key) : $key;
                if (empty($sanitized_key)) {
                    $errors++;
                    if ($detailed_logging) {
                        $line_ref = isset($tag_data['line_number']) ? " (line {$tag_data['line_number']})" : " (item {$current_item})";
                        $error_details[] = "Invalid key '{$key}'{$line_ref}";
                    }
                    continue;
                }

                $category = isset($tag_data['category']) ? $tag_data['category'] : 'general';
                $groups = isset($tag_data['groups']) ? $tag_data['groups'] : array();
                $priority = isset($tag_data['priority']) ? intval($tag_data['priority']) : 0;

                $result = $this->import_single_tag($sanitized_key, $value, $category, $conflict_resolution, $groups, $priority, $tag_data);

                switch ($result) {
                    case 'imported':
                        $imported++;
                        break;
                    case 'updated':
                        $updated++;
                        break;
                    case 'skipped':
                        $skipped++;
                        break;
                    default:
                        $errors++;
                        if ($detailed_logging) {
                            $line_ref = isset($tag_data['line_number']) ? " (line {$tag_data['line_number']})" : " (item {$current_item})";
                            $error_details[] = "Failed to process tag '{$sanitized_key}'{$line_ref}";
                        }
                }

            } catch (Exception $e) {
                $errors++;
                if ($detailed_logging) {
                    $line_ref = isset($tag_data['line_number']) ? " (line {$tag_data['line_number']})" : " (item {$current_item})";
                    $error_details[] = $e->getMessage() . $line_ref;
                    error_log("WP Dynamic Tags Import Error{$line_ref}: " . $e->getMessage());
                }
            }
        }

        // Build result message
        $message_parts = array();
        if ($imported > 0) $message_parts[] = sprintf(__('%d imported', 'wp-dynamic-tags'), $imported);
        if ($updated > 0) $message_parts[] = sprintf(__('%d updated', 'wp-dynamic-tags'), $updated);
        if ($skipped > 0) $message_parts[] = sprintf(__('%d skipped', 'wp-dynamic-tags'), $skipped);
        if ($errors > 0) $message_parts[] = sprintf(__('%d errors', 'wp-dynamic-tags'), $errors);

        $message = sprintf(__('%s import completed: %s', 'wp-dynamic-tags'), $format, implode(', ', $message_parts));

        $success = ($imported + $updated) > 0;

        // Enhanced result data with comprehensive tracking
        $result = array(
            'success' => $success,
            'message' => $message,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_processed' => $total_items,
            'error_details' => $error_details,
            'validation_errors' => $validation_errors,
            'format' => $format,
            'summary' => array(
                'total_items' => $total_items,
                'successful_operations' => $imported + $updated,
                'failed_operations' => $errors,
                'skipped_operations' => $skipped,
                'validation_issues' => count($validation_errors),
                'success_rate' => $total_items > 0 ? round((($imported + $updated) / $total_items) * 100, 2) : 0
            )
        );

        // Add detailed breakdown for admin users
        if (current_user_can('manage_options') && ($errors > 0 || count($validation_errors) > 0)) {
            $result['detailed_report'] = $this->generate_import_report($result);
        }

        return $result;
    }
    
    /**
     * Generate detailed import report for administrators
     */
    private function generate_import_report($result) {
        $report = array(
            'timestamp' => current_time('c'),
            'import_summary' => $result['summary'],
            'issues' => array(),
            'recommendations' => array()
        );

        // Analyze validation errors
        if (!empty($result['validation_errors'])) {
            $validation_categories = array();
            foreach ($result['validation_errors'] as $error) {
                if (strpos($error, 'Duplicate key') !== false) {
                    $validation_categories['duplicate_keys'][] = $error;
                } elseif (strpos($error, 'Missing tag_key') !== false) {
                    $validation_categories['missing_data'][] = $error;
                } elseif (strpos($error, 'Group name') !== false && strpos($error, 'exceeds') !== false) {
                    $validation_categories['invalid_groups'][] = $error;
                } else {
                    $validation_categories['other'][] = $error;
                }
            }

            $report['issues']['validation_errors'] = $validation_categories;

            // Add recommendations based on validation errors
            if (isset($validation_categories['duplicate_keys'])) {
                $report['recommendations'][] = 'Consider assigning groups to duplicate tag keys or use the "rename" conflict resolution option.';
            }
            if (isset($validation_categories['missing_data'])) {
                $report['recommendations'][] = 'Ensure all rows in the CSV file have both tag_key and tag_value columns filled.';
            }
            if (isset($validation_categories['invalid_groups'])) {
                $report['recommendations'][] = 'Group names should be under 200 characters. Consider using shorter, descriptive names.';
            }
        }

        // Analyze runtime errors
        if (!empty($result['error_details'])) {
            $report['issues']['runtime_errors'] = $result['error_details'];
            $report['recommendations'][] = 'Check the error details above and verify data formatting meets requirements.';
        }

        // Performance recommendations
        if ($result['summary']['total_items'] > 1000) {
            $report['recommendations'][] = 'For large imports, consider breaking the file into smaller chunks (500-1000 rows each) for better performance.';
        }

        if ($result['summary']['success_rate'] < 80) {
            $report['recommendations'][] = 'Success rate is below 80%. Please review the validation errors and consider cleaning the import data.';
        }

        return $report;
    }

    /**
     * Get all tags for export
     */
    /**
     * Optimized tag export with memory-efficient generator
     */
    private function get_all_tags_for_export($batch_size = 50) {
        global $wpdb;

        // Use direct SQL with LIMIT/OFFSET for memory efficiency
        $offset = 0;

        do {
            $sql = $wpdb->prepare("
                SELECT ID, post_title, post_content, post_date
                FROM {$wpdb->posts}
                WHERE post_type = %s
                AND post_status = 'publish'
                ORDER BY post_title ASC
                LIMIT %d OFFSET %d
            ", $this->post_type, $batch_size, $offset);

            $posts = $wpdb->get_results($sql);

            if (empty($posts)) {
                break;
            }

            // Convert to post objects for compatibility
            foreach ($posts as $post) {
                $tag = new stdClass();
                $tag->ID = $post->ID;
                $tag->post_title = $post->post_title;
                $tag->post_content = $post->post_content;
                $tag->post_date = $post->post_date;
                yield $tag;
            }

            $offset += $batch_size;
        } while (count($posts) === $batch_size);
    }

    /**
     * Get all tags as array (for backward compatibility)
     */
    private function get_all_tags_for_export_array() {
        $tags = array();
        foreach ($this->get_all_tags_for_export() as $tag) {
            $tags[] = $tag;
        }
        return $tags;
    }
    
    /**
     * Log activity with enhanced data
     */
    private function log_activity($type, $data) {
        $activities = get_option('dt_activities', array());

        // Ensure data has proper structure
        if (!is_array($data)) {
            $data = array('message' => $data);
        }

        // Add default message if not provided
        if (!isset($data['message'])) {
            switch ($type) {
                case 'export':
                    $format = isset($data['format']) ? strtoupper($data['format']) : 'Unknown';
                    $count = isset($data['exported_count']) ? $data['exported_count'] : 0;
                    $data['message'] = sprintf(__('%s export completed: %d tags', 'wp-dynamic-tags'), $format, $count);
                    break;
                case 'import':
                    $imported = isset($data['imported']) ? $data['imported'] : 0;
                    $data['message'] = sprintf(__('Import completed: %d tags imported', 'wp-dynamic-tags'), $imported);
                    break;
                default:
                    $data['message'] = ucfirst($type) . ' completed';
            }
        }

        $activity = array(
            'type' => $type,
            'date' => current_time('c'),
            'data' => $data,
            'user' => get_current_user_id()
        );

        array_unshift($activities, $activity);
        $activities = array_slice($activities, 0, 15); // Keep more activities for better tracking

        update_option('dt_activities', $activities);
    }
    
    /**
     * Show recent activity
     */
    private function show_recent_activity() {
        $activities = get_option('dt_activities', array());
        
        if (empty($activities)) {
            echo '<p>' . __('No recent import/export activity.', 'wp-dynamic-tags') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Type', 'wp-dynamic-tags') . '</th>';
        echo '<th>' . __('Date', 'wp-dynamic-tags') . '</th>';
        echo '<th>' . __('Result', 'wp-dynamic-tags') . '</th>';
        echo '<th>' . __('User', 'wp-dynamic-tags') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($activities as $activity) {
            $user = get_userdata($activity['user']);
            $user_name = $user ? $user->display_name : __('Unknown', 'wp-dynamic-tags');
            
            echo '<tr>';
            echo '<td>' . ucfirst($activity['type']) . '</td>';
            echo '<td>' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($activity['date'])) . '</td>';
            echo '<td>' . esc_html($activity['data']['message']) . '</td>';
            echo '<td>' . esc_html($user_name) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Show enhanced import notice with detailed feedback
     */
    private function show_enhanced_import_notice($result) {
        $class = $result['success'] ? 'notice-success' : 'notice-error';
        $main_message = $result['message'];

        // Add processing time if available
        if ($result['success'] && isset($result['processing_time'])) {
            $main_message .= ' ' . sprintf(__('(Processing time: %s seconds)', 'wp-dynamic-tags'), $result['processing_time']);
        }

        echo '<div class="notice ' . $class . ' is-dismissible">';
        echo '<p><strong>' . esc_html($main_message) . '</strong></p>';

        // Show detailed summary for significant operations
        if (isset($result['summary']) && $result['summary']['total_items'] > 0) {
            echo '<div style="margin-left: 20px; font-size: 13px;">';
            echo '<p><strong>' . __('Import Summary:', 'wp-dynamic-tags') . '</strong></p>';
            echo '<ul style="margin: 5px 0 10px 20px;">';

            if ($result['summary']['successful_operations'] > 0) {
                echo '<li style="color: #00a32a;">✓ ' . sprintf(__('%d tags successfully processed', 'wp-dynamic-tags'), $result['summary']['successful_operations']) . '</li>';
            }

            if ($result['summary']['skipped_operations'] > 0) {
                echo '<li style="color: #dba617;">⚠ ' . sprintf(__('%d tags skipped (already exist)', 'wp-dynamic-tags'), $result['summary']['skipped_operations']) . '</li>';
            }

            if ($result['summary']['failed_operations'] > 0) {
                echo '<li style="color: #d63638;">✗ ' . sprintf(__('%d tags failed to process', 'wp-dynamic-tags'), $result['summary']['failed_operations']) . '</li>';
            }

            if ($result['summary']['validation_issues'] > 0) {
                echo '<li style="color: #d63638;">⚠ ' . sprintf(__('%d validation issues found', 'wp-dynamic-tags'), $result['summary']['validation_issues']) . '</li>';
            }

            echo '<li>' . sprintf(__('Success rate: %s%%', 'wp-dynamic-tags'), $result['summary']['success_rate']) . '</li>';
            echo '</ul>';
            echo '</div>';
        }

        // Show validation errors for administrators
        if (current_user_can('manage_options') && !empty($result['validation_errors'])) {
            echo '<div style="margin-left: 20px; margin-top: 10px;">';
            echo '<details style="font-size: 13px;">';
            echo '<summary style="cursor: pointer; color: #d63638;"><strong>' . __('Validation Issues (click to expand)', 'wp-dynamic-tags') . '</strong></summary>';
            echo '<div style="margin: 10px 0; padding: 10px; background: #fef7f0; border-left: 4px solid #dba617;">';

            $displayed_errors = array_slice($result['validation_errors'], 0, 10);
            foreach ($displayed_errors as $error) {
                echo '<div style="margin: 3px 0;">• ' . esc_html($error) . '</div>';
            }

            if (count($result['validation_errors']) > 10) {
                echo '<div style="margin: 10px 0; font-style: italic;">';
                echo sprintf(__('... and %d more validation issues. Check the error log for complete details.', 'wp-dynamic-tags'), count($result['validation_errors']) - 10);
                echo '</div>';
            }
            echo '</div>';
            echo '</details>';
            echo '</div>';
        }

        // Show recommendations for administrators
        if (current_user_can('manage_options') && isset($result['detailed_report']['recommendations']) && !empty($result['detailed_report']['recommendations'])) {
            echo '<div style="margin-left: 20px; margin-top: 10px;">';
            echo '<details style="font-size: 13px;">';
            echo '<summary style="cursor: pointer; color: #2271b1;"><strong>' . __('Recommendations (click to expand)', 'wp-dynamic-tags') . '</strong></summary>';
            echo '<div style="margin: 10px 0; padding: 10px; background: #f0f6fc; border-left: 4px solid #2271b1;">';

            foreach ($result['detailed_report']['recommendations'] as $recommendation) {
                echo '<div style="margin: 5px 0;">💡 ' . esc_html($recommendation) . '</div>';
            }
            echo '</div>';
            echo '</details>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Show admin notices
     */
    private function show_notices() {
        // Notices are handled by add_action('admin_notices') in handle_import
    }
    
    /**
     * Sanitize tag key
     */
    private function sanitize_tag_key($key) {
        // UPDATED: Match the new simplified sanitization from main plugin
        $key = strtolower(trim($key));
        // Remove all non-alphanumeric characters and replace with underscore
        $key = preg_replace('/[^a-z0-9]/', '_', $key);
        // Collapse multiple underscores into single underscore
        $key = preg_replace('/_{2,}/', '_', $key);
        // Remove leading/trailing underscores
        $key = trim($key, '_');

        // Ensure we have a valid key
        if (empty($key)) {
            $key = 'unnamed_tag';
        }

        return $key;
    }

    /**
     * Get filtered tags for export based on category and group
     */
    private function get_filtered_tags_for_export($category = '', $group_id = '') {
        $args = array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        // Filter by category
        if (!empty($category)) {
            $args['meta_query'] = array(
                array(
                    'key' => '_dt_category',
                    'value' => $category,
                    'compare' => '='
                )
            );
        }

        // Filter by group
        if (!empty($group_id) && is_numeric($group_id)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'tag_groups',
                    'field' => 'term_id',
                    'terms' => intval($group_id)
                )
            );
        }

        return get_posts($args);
    }

    /**
     * Validate export data before processing
     */
    private function validate_export_data($tags) {
        $validation_result = array(
            'valid' => true,
            'message' => ''
        );

        if (empty($tags)) {
            $validation_result['valid'] = false;
            $validation_result['message'] = __('No tags found for export.', 'wp-dynamic-tags');
            return $validation_result;
        }

        $invalid_tags = array();
        foreach ($tags as $tag) {
            $tag_key = $this->sanitize_tag_key($tag->post_title);
            if (empty($tag_key)) {
                $invalid_tags[] = $tag->post_title;
            }
        }

        if (!empty($invalid_tags)) {
            $validation_result['valid'] = false;
            $validation_result['message'] = sprintf(
                __('Found %d tags with invalid keys: %s', 'wp-dynamic-tags'),
                count($invalid_tags),
                implode(', ', array_slice($invalid_tags, 0, 5)) . (count($invalid_tags) > 5 ? '...' : '')
            );
        }

        return $validation_result;
    }

    /**
     * Sanitize content for CSV export
     */
    private function sanitize_csv_content($content) {
        // Remove any null bytes and normalize line endings
        $content = str_replace("\0", '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        // Limit content length for CSV compatibility
        if (strlen($content) > 32767) { // Excel cell limit
            $content = substr($content, 0, 32767) . '...';
        }

        return $content;
    }

    /**
     * Get all categories used in tags
     */
    private function get_all_categories() {
        global $wpdb;

        $categories = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_dt_category'
            AND pm.meta_value != ''
            AND p.post_type = %s
            AND p.post_status = 'publish'
            ORDER BY pm.meta_value
        ", $this->post_type));

        return array_merge(array('general'), $categories);
    }

    /**
     * NEW SIMPLIFIED: Get the single shortcode pattern for a tag (group_tag format)
     */
    private function get_tag_shortcode_patterns($post_id, $tag_key, $group_names) {
        // MATCHES new simplified logic from wp-dynamic-tags.php
        if (!empty($group_names)) {
            // For grouped tags: return [group_tag] format only
            $primary_group = reset($group_names); // Use first group
            $group_slug = $this->sanitize_tag_key($primary_group);
            $primary_shortcode = $group_slug . '_' . $tag_key;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags Export: Grouped tag '{$tag_key}' -> [{$primary_shortcode}]");
            }

            return array('[' . $primary_shortcode . ']');
        } else {
            // For ungrouped tags: return [tag] format only
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags Export: Ungrouped tag '{$tag_key}' -> [{$tag_key}]");
            }

            return array('[' . $tag_key . ']');
        }
    }

    /**
     * Remove UTF-8 BOM (Byte Order Mark) from string
     *
     * BOM is added during CSV export for Excel compatibility (line 633)
     * but must be stripped during import to ensure proper header detection
     *
     * @param string $text Text that may contain BOM
     * @return string Text with BOM removed
     */
    private function remove_utf8_bom($text) {
        $bom = pack('H*','EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);
        return $text;
    }


    // REMOVED: Abbreviation system to ensure single shortcode approach
}