<?php
/**
 * Table Manager Admin Interface for WP Dynamic Tags
 *
 * Provides admin interface for managing custom table and migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Table_Manager_Admin {

    private $post_type = 'dynamic_tag';
    private $page_slug = 'dt-table-manager';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('wp_ajax_dt_table_migrate', array($this, 'ajax_migrate'));
        add_action('wp_ajax_dt_table_sync', array($this, 'ajax_sync'));
        add_action('wp_ajax_dt_table_reset', array($this, 'ajax_reset'));
        add_action('wp_ajax_dt_table_statistics', array($this, 'ajax_statistics'));
        add_action('wp_ajax_dt_table_export', array($this, 'ajax_export'));
        add_action('wp_ajax_dt_table_import', array($this, 'ajax_import'));
        add_action('wp_ajax_dt_create_table', array($this, 'ajax_create_table'));
        add_action('wp_ajax_dt_check_table_health', array($this, 'ajax_check_table_health'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Table Manager', 'wp-dynamic-tags'),
            __('Table Manager', 'wp-dynamic-tags'),
            'manage_options',
            $this->page_slug,
            array($this, 'admin_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-admin');

        wp_localize_script('jquery', 'dtTableManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dt_table_manager'),
            'strings' => array(
                'confirmMigration' => __('Are you sure you want to migrate data? This cannot be undone.', 'wp-dynamic-tags'),
                'confirmReset' => __('Are you sure you want to reset the table? All data will be lost!', 'wp-dynamic-tags'),
                'migrating' => __('Migrating...', 'wp-dynamic-tags'),
                'syncing' => __('Syncing...', 'wp-dynamic-tags'),
                'resetting' => __('Resetting...', 'wp-dynamic-tags')
            )
        ));
    }

    /**
     * Handle admin actions
     */
    public function handle_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== $this->page_slug) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        switch ($action) {
            case 'enable_table_integration':
                if (wp_verify_nonce($_GET['_wpnonce'], 'enable_table_integration')) {
                    update_option('wp_dynamic_tags_table_integration_enabled', true);
                    wp_redirect(add_query_arg(array(
                        'page' => $this->page_slug,
                        'message' => 'table_integration_enabled'
                    ), admin_url('edit.php?post_type=' . $this->post_type)));
                    exit;
                }
                break;

            case 'disable_table_integration':
                if (wp_verify_nonce($_GET['_wpnonce'], 'disable_table_integration')) {
                    update_option('wp_dynamic_tags_table_integration_enabled', false);
                    wp_redirect(add_query_arg(array(
                        'page' => $this->page_slug,
                        'message' => 'table_integration_disabled'
                    ), admin_url('edit.php?post_type=' . $this->post_type)));
                    exit;
                }
                break;
        }
    }

    /**
     * Admin page content
     */
    public function admin_page() {
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $db_manager = $plugin->get_db_manager();
        $migration_manager = $plugin->get_migration_manager();
        $table_manager = $plugin->get_table_manager();
        $bridge = $plugin->get_post_table_bridge();

        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Table Manager', 'wp-dynamic-tags'); ?></h1>

            <?php $this->show_notices(); ?>

            <div class="dt-table-manager-container">

                <!-- Status Overview -->
                <div class="card">
                    <h2><?php _e('Table Status Overview', 'wp-dynamic-tags'); ?></h2>
                    <div id="dt-status-overview">
                        <?php $this->render_status_overview($db_manager, $migration_manager, $bridge); ?>
                    </div>
                </div>

                <!-- Table Integration Settings -->
                <div class="card">
                    <h2><?php _e('Table Integration Settings', 'wp-dynamic-tags'); ?></h2>
                    <?php $this->render_integration_settings($plugin); ?>
                </div>

                <!-- Migration Tools -->
                <div class="card">
                    <h2><?php _e('Migration Tools', 'wp-dynamic-tags'); ?></h2>
                    <?php $this->render_migration_tools($migration_manager); ?>
                </div>

                <!-- Sync Tools -->
                <div class="card">
                    <h2><?php _e('Synchronization Tools', 'wp-dynamic-tags'); ?></h2>
                    <?php $this->render_sync_tools($bridge); ?>
                </div>

                <!-- Import/Export Tools -->
                <div class="card">
                    <h2><?php _e('Import/Export Tools', 'wp-dynamic-tags'); ?></h2>
                    <?php $this->render_import_export_tools($plugin->get_table_import_export()); ?>
                </div>

                <!-- Statistics -->
                <div class="card">
                    <h2><?php _e('Statistics & Diagnostics', 'wp-dynamic-tags'); ?></h2>
                    <div id="dt-statistics">
                        <?php $this->render_statistics($table_manager, $migration_manager, $bridge); ?>
                    </div>
                </div>

                <!-- Migration Log -->
                <div class="card">
                    <h2><?php _e('Migration Log', 'wp-dynamic-tags'); ?></h2>
                    <?php $this->render_migration_log($migration_manager); ?>
                </div>

            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Auto-refresh statistics every 10 seconds during operations
            var refreshInterval;

            function startStatsRefresh() {
                refreshInterval = setInterval(function() {
                    $.ajax({
                        url: dtTableManager.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'dt_table_statistics',
                            nonce: dtTableManager.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#dt-status-overview').html(response.data.status_html);
                                $('#dt-statistics').html(response.data.stats_html);
                            }
                        }
                    });
                }, 10000);
            }

            function stopStatsRefresh() {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                }
            }

            // Migration button
            $('#dt-migrate-button').on('click', function() {
                if (!confirm(dtTableManager.strings.confirmMigration)) {
                    return;
                }

                var $button = $(this);
                var originalText = $button.text();
                $button.text(dtTableManager.strings.migrating).prop('disabled', true);

                startStatsRefresh();

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dt_table_migrate',
                        nonce: dtTableManager.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Migration completed successfully: ' + response.data.message);
                            location.reload();
                        } else {
                            var errorMsg = 'Migration failed: ' + response.data;
                            errorMsg += '\n\nTroubleshooting tips:';
                            errorMsg += '\n• Ensure the table exists (check status above)';
                            errorMsg += '\n• Verify database permissions';
                            errorMsg += '\n• Check for conflicting tag names';
                            errorMsg += '\n• Try smaller batches if you have many tags';
                            alert(errorMsg);
                            $button.text(originalText).prop('disabled', false);
                            stopStatsRefresh();
                        }
                    },
                    error: function() {
                        alert('Migration failed due to server error.\n\nThis usually indicates:\n• Database connection issues\n• Server timeout (try again)\n• Insufficient memory (contact hosting provider)');
                        $button.text(originalText).prop('disabled', false);
                        stopStatsRefresh();
                    }
                });
            });

            // Sync buttons
            $('.dt-sync-button').on('click', function() {
                var $button = $(this);
                var syncType = $button.data('sync-type');
                var originalText = $button.text();

                $button.text(dtTableManager.strings.syncing).prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dt_table_sync',
                        sync_type: syncType,
                        nonce: dtTableManager.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Synchronization completed: ' + response.data.message);
                            location.reload();
                        } else {
                            var errorMsg = 'Sync failed: ' + response.data;
                            errorMsg += '\n\nTroubleshooting tips:';
                            errorMsg += '\n• Check table and posts both exist';
                            errorMsg += '\n• Verify no data corruption exists';
                            errorMsg += '\n• Try individual sync directions first';
                            alert(errorMsg);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Sync failed due to server error.\n\nThis usually indicates:\n• Network connectivity issues\n• Database connection problems\n• Server resource limits');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            // Reset button
            $('#dt-reset-button').on('click', function() {
                if (!confirm(dtTableManager.strings.confirmReset)) {
                    return;
                }

                var $button = $(this);
                var originalText = $button.text();
                $button.text(dtTableManager.strings.resetting).prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dt_table_reset',
                        nonce: dtTableManager.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Reset failed: ' + response.data);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Reset failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            // Export button
            $('#dt-export-button').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();
                $button.text('Exporting...').prop('disabled', true);

                var exportData = {
                    action: 'dt_table_export',
                    format: $('#export-format').val(),
                    filters: {
                        status: $('#export-status').val(),
                        category_id: $('#export-category').val()
                    },
                    nonce: dtTableManager.nonce
                };

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: exportData,
                    success: function(response) {
                        if (response.success) {
                            // Create download link
                            var downloadUrl = response.data.download_url;
                            var link = document.createElement('a');
                            link.href = downloadUrl;
                            link.download = response.data.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);

                            alert('Export completed successfully! ' + response.data.stats.total_records + ' records exported.');
                        } else {
                            alert('Export failed: ' + response.data);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Export failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            // Validate import button
            $('#dt-validate-import-button').on('click', function() {
                var fileInput = $('#import-file')[0];
                if (!fileInput.files.length) {
                    alert('Please select a file to validate.');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'dt_table_validate_import');
                formData.append('import_file', fileInput.files[0]);
                formData.append('format', getImportFormat(fileInput.files[0].name));
                formData.append('nonce', dtTableManager.nonce);

                var $button = $(this);
                var originalText = $button.text();
                $button.text('Validating...').prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            var validation = response.data.validation;
                            var resultHtml = '<h4>Validation Results:</h4>';
                            resultHtml += '<p>Total Records: ' + response.data.total_records + '</p>';

                            if (validation.valid) {
                                resultHtml += '<p style="color: green;">✓ Validation passed - ready to import!</p>';
                            } else {
                                resultHtml += '<p style="color: red;">✗ Validation failed</p>';
                                resultHtml += '<ul>';
                                for (var error in validation.errors) {
                                    resultHtml += '<li><strong>' + error + ':</strong> ' + validation.errors[error].join(', ') + '</li>';
                                }
                                resultHtml += '</ul>';
                            }

                            if (validation.warnings.length > 0) {
                                resultHtml += '<h5>Warnings:</h5><ul>';
                                validation.warnings.forEach(function(warning) {
                                    resultHtml += '<li style="color: orange;">' + warning + '</li>';
                                });
                                resultHtml += '</ul>';
                            }

                            $('#dt-import-results').html(resultHtml).show();
                        } else {
                            alert('Validation failed: ' + response.data);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Validation failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            // Import button
            $('#dt-import-button').on('click', function() {
                var fileInput = $('#import-file')[0];
                if (!fileInput.files.length) {
                    alert('Please select a file to import.');
                    return;
                }

                if (!confirm('Are you sure you want to import this data? This action cannot be undone.')) {
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'dt_table_import');
                formData.append('import_file', fileInput.files[0]);
                formData.append('format', getImportFormat(fileInput.files[0].name));
                formData.append('options[update_existing]', $('#update-existing').is(':checked') ? '1' : '0');
                formData.append('options[skip_duplicates]', $('#skip-duplicates').is(':checked') ? '1' : '0');
                formData.append('nonce', dtTableManager.nonce);

                var $button = $(this);
                var originalText = $button.text();
                $button.text('Importing...').prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            var stats = response.data.stats;
                            var resultHtml = '<h4>Import Completed:</h4>';
                            resultHtml += '<p>Total Records Processed: ' + stats.total_records + '</p>';
                            resultHtml += '<p>Created: ' + stats.created + '</p>';
                            resultHtml += '<p>Updated: ' + stats.updated + '</p>';
                            resultHtml += '<p>Skipped: ' + stats.skipped + '</p>';
                            resultHtml += '<p>Errors: ' + stats.errors + '</p>';

                            if (stats.errors > 0 && stats.error_details.length > 0) {
                                resultHtml += '<h5>Error Details:</h5><ul>';
                                stats.error_details.forEach(function(error) {
                                    resultHtml += '<li>Row ' + error.row + ': ' + error.error + '</li>';
                                });
                                resultHtml += '</ul>';
                            }

                            $('#dt-import-results').html(resultHtml).show();

                            if (stats.created > 0 || stats.updated > 0) {
                                setTimeout(function() {
                                    location.reload();
                                }, 3000);
                            }
                        } else {
                            alert('Import failed: ' + response.data);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Import failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            function getImportFormat(filename) {
                var extension = filename.split('.').pop().toLowerCase();
                switch (extension) {
                    case 'json':
                        return 'json';
                    case 'csv':
                        return 'csv';
                    case 'xml':
                        return 'xml';
                    case 'sql':
                        return 'sql';
                    default:
                        return 'json';
                }
            }

            // Create table button
            $('#dt-create-table-button').on('click', function() {
                if (!confirm('Create the dynamic tags table? This will enable the table system.')) {
                    return;
                }

                var $button = $(this);
                var originalText = $button.text();
                $button.text('Creating...').prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dt_create_table',
                        nonce: dtTableManager.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = response.data.message;
                            if (response.data.already_exists) {
                                message += '\n\nThe table is already available and ready to use.';
                            } else if (response.data.created) {
                                message += '\n\nYou can now migrate your existing data or start using the table integration.';
                            }
                            alert(message);
                            location.reload();
                        } else {
                            alert('Table creation failed: ' + response.data);
                            $button.text(originalText).prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Table creation failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });

            // Check health button
            $('#dt-check-health-button').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();
                $button.text('Checking...').prop('disabled', true);

                $.ajax({
                    url: dtTableManager.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dt_check_table_health',
                        nonce: dtTableManager.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var results = response.data;
                            var message = 'Health Check Results:\n\n';
                            message += 'Overall Status: ' + results.status.toUpperCase() + '\n';
                            message += 'Message: ' + results.message + '\n\n';
                            message += 'Table Exists: ' + (results.table_exists ? 'Yes' : 'No') + '\n';

                            if (results.table_exists) {
                                message += 'Columns Count: ' + results.columns_count + '\n';
                                message += 'Indexes Count: ' + results.indexes_count + '\n';
                                message += 'Record Count: ' + results.record_count + '\n';
                                message += 'Read Access: ' + (results.read_access ? 'OK' : 'Failed') + '\n';
                                message += 'Write Access: ' + (results.write_access ? 'OK' : 'Failed') + '\n';

                                if (results.missing_columns && results.missing_columns.length > 0) {
                                    message += '\nMissing Columns:\n';
                                    results.missing_columns.forEach(function(column) {
                                        message += '- ' + column + '\n';
                                    });
                                }

                                if (results.read_error) {
                                    message += '\nRead Error: ' + results.read_error + '\n';
                                }

                                if (results.write_error) {
                                    message += '\nWrite Error: ' + results.write_error + '\n';
                                }
                            } else if (results.creation_error) {
                                message += '\nCreation Error: ' + results.creation_error + '\n';
                            }

                            alert(message);
                        } else {
                            alert('Health check failed: ' + response.data);
                        }
                        $button.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Health check failed due to server error.');
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>

        <style>
        .dt-table-manager-container .card {
            margin-bottom: 20px;
        }

        .dt-status-item {
            display: inline-block;
            margin: 10px 20px 10px 0;
            padding: 10px 15px;
            background: #f9f9f9;
            border-radius: 4px;
            border-left: 4px solid #0073aa;
        }

        .dt-status-item.warning {
            border-left-color: #ffba00;
        }

        .dt-status-item.error {
            border-left-color: #d63638;
        }

        .dt-status-item.success {
            border-left-color: #00a32a;
        }

        .dt-status-label {
            font-weight: bold;
            display: block;
        }

        .dt-status-value {
            font-size: 18px;
            color: #0073aa;
        }

        .dt-migration-log {
            max-height: 300px;
            overflow-y: auto;
            background: #f9f9f9;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }

        .dt-log-entry {
            margin-bottom: 5px;
            padding: 3px 0;
        }

        .dt-log-entry.error {
            color: #d63638;
        }

        .dt-log-entry.warning {
            color: #ffba00;
        }

        .dt-log-entry.success {
            color: #00a32a;
        }

        .dt-action-buttons {
            margin: 15px 0;
        }

        .dt-action-buttons .button {
            margin-right: 10px;
        }
        </style>
        <?php
    }

    /**
     * Render status overview
     */
    private function render_status_overview($db_manager, $migration_manager, $bridge) {
        $table_exists = $db_manager->table_exists();
        $migration_status = $migration_manager->get_migration_status();
        $sync_stats = $bridge ? $bridge->get_sync_statistics() : null;

        echo '<div class="dt-status-grid" style="display: flex; flex-wrap: wrap;">';

        // Table Status
        $table_class = $table_exists ? 'success' : 'error';
        echo '<div class="dt-status-item ' . $table_class . '">';
        echo '<span class="dt-status-label">' . __('Table Status', 'wp-dynamic-tags') . '</span>';
        echo '<span class="dt-status-value">' . ($table_exists ? __('Created', 'wp-dynamic-tags') : __('Not Created', 'wp-dynamic-tags')) . '</span>';

        // Add action buttons for table creation
        if (!$table_exists) {
            echo '<div style="margin-top: 8px;">';
            echo '<button type="button" id="dt-create-table-button" class="button button-small button-primary">' . __('Create Table', 'wp-dynamic-tags') . '</button> ';
            echo '<button type="button" id="dt-check-health-button" class="button button-small">' . __('Check Health', 'wp-dynamic-tags') . '</button>';
            echo '</div>';

            // Show any creation errors
            $creation_error = get_option('wp_dynamic_tags_table_creation_error');
            if ($creation_error) {
                echo '<div style="margin-top: 5px; padding: 8px; background: #ffecec; border-left: 3px solid #d63638; font-size: 12px; border-radius: 3px;">';
                echo '<strong>' . __('Last Error:', 'wp-dynamic-tags') . '</strong> ' . esc_html($creation_error);
                echo '<br><br><strong>' . __('Troubleshooting:', 'wp-dynamic-tags') . '</strong><br>';
                echo '• ' . __('Check database user permissions', 'wp-dynamic-tags') . '<br>';
                echo '• ' . __('Verify database connection', 'wp-dynamic-tags') . '<br>';
                echo '• ' . __('Contact your hosting provider if issues persist', 'wp-dynamic-tags') . '<br>';
                echo '• ' . __('Use "Check Health" button for detailed diagnostics', 'wp-dynamic-tags');
                echo '</div>';
            } else {
                echo '<div style="margin-top: 5px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffba00; font-size: 12px; border-radius: 3px;">';
                echo '<strong>' . __('Next Steps:', 'wp-dynamic-tags') . '</strong><br>';
                echo '• ' . __('Click "Create Table" to set up the database table', 'wp-dynamic-tags') . '<br>';
                echo '• ' . __('Use "Check Health" to verify your database setup', 'wp-dynamic-tags') . '<br>';
                echo '• ' . __('After creation, you can migrate existing data', 'wp-dynamic-tags');
                echo '</div>';
            }
        }

        echo '</div>';

        // Migration Status
        $migration_class = 'warning';
        if ($migration_status === 'completed') {
            $migration_class = 'success';
        } elseif ($migration_status === 'failed') {
            $migration_class = 'error';
        }

        echo '<div class="dt-status-item ' . $migration_class . '">';
        echo '<span class="dt-status-label">' . __('Migration Status', 'wp-dynamic-tags') . '</span>';
        echo '<span class="dt-status-value">' . ucfirst(str_replace('_', ' ', $migration_status)) . '</span>';
        echo '</div>';

        if ($sync_stats) {
            // Sync Status
            $sync_class = ($sync_stats['posts_without_table_records'] === 0 && $sync_stats['table_records_without_posts'] === 0) ? 'success' : 'warning';
            echo '<div class="dt-status-item ' . $sync_class . '">';
            echo '<span class="dt-status-label">' . __('Sync Status', 'wp-dynamic-tags') . '</span>';
            echo '<span class="dt-status-value">' . ($sync_class === 'success' ? __('In Sync', 'wp-dynamic-tags') : __('Out of Sync', 'wp-dynamic-tags')) . '</span>';
            echo '</div>';

            // Total Records
            echo '<div class="dt-status-item">';
            echo '<span class="dt-status-label">' . __('Total Posts', 'wp-dynamic-tags') . '</span>';
            echo '<span class="dt-status-value">' . $sync_stats['total_posts'] . '</span>';
            echo '</div>';

            echo '<div class="dt-status-item">';
            echo '<span class="dt-status-label">' . __('Total Table Records', 'wp-dynamic-tags') . '</span>';
            echo '<span class="dt-status-value">' . $sync_stats['total_table_records'] . '</span>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render integration settings
     */
    private function render_integration_settings($plugin) {
        $integration_enabled = $plugin->is_table_integration_enabled();

        echo '<p>' . __('Control whether the custom table system is active alongside the existing post type system.', 'wp-dynamic-tags') . '</p>';

        echo '<div class="dt-action-buttons">';

        if ($integration_enabled) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #00a32a; margin-right: 5px;"></span>';
            echo __('Table integration is currently enabled.', 'wp-dynamic-tags') . ' ';
            echo '<a href="' . wp_nonce_url(
                add_query_arg(array(
                    'page' => $this->page_slug,
                    'action' => 'disable_table_integration'
                ), admin_url('edit.php?post_type=' . $this->post_type)),
                'disable_table_integration'
            ) . '" class="button">' . __('Disable Table Integration', 'wp-dynamic-tags') . '</a>';
        } else {
            echo '<span class="dashicons dashicons-warning" style="color: #ffba00; margin-right: 5px;"></span>';
            echo __('Table integration is currently disabled.', 'wp-dynamic-tags') . ' ';
            echo '<a href="' . wp_nonce_url(
                add_query_arg(array(
                    'page' => $this->page_slug,
                    'action' => 'enable_table_integration'
                ), admin_url('edit.php?post_type=' . $this->post_type)),
                'enable_table_integration'
            ) . '" class="button button-primary">' . __('Enable Table Integration', 'wp-dynamic-tags') . '</a>';
        }

        echo '</div>';

        echo '<h4>' . __('Sync Settings', 'wp-dynamic-tags') . '</h4>';
        echo '<form method="post" action="options.php">';
        settings_fields('wp_dynamic_tags_sync_settings');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">' . __('Hard Delete on Post Delete', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="wp_dynamic_tags_hard_delete_on_post_delete" value="1" ' .
             checked(get_option('wp_dynamic_tags_hard_delete_on_post_delete', false), true, false) . '> ';
        echo __('Permanently delete table records when posts are deleted', 'wp-dynamic-tags') . '</label>';
        echo '<p class="description">' . __('If unchecked, table records will be marked as inactive instead.', 'wp-dynamic-tags') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . __('Hard Delete on Table Delete', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="wp_dynamic_tags_hard_delete_on_table_delete" value="1" ' .
             checked(get_option('wp_dynamic_tags_hard_delete_on_table_delete', false), true, false) . '> ';
        echo __('Permanently delete posts when table records are deleted', 'wp-dynamic-tags') . '</label>';
        echo '<p class="description">' . __('If unchecked, posts will be marked as draft instead.', 'wp-dynamic-tags') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        submit_button();
        echo '</form>';
    }

    /**
     * Render migration tools
     */
    private function render_migration_tools($migration_manager) {
        $migration_status = $migration_manager->get_migration_status();
        $migration_needed = $migration_manager->is_migration_needed();

        echo '<p>' . __('Migrate data between the post type system and the custom table.', 'wp-dynamic-tags') . '</p>';

        if ($migration_needed) {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>' . __('Migration Recommended:', 'wp-dynamic-tags') . '</strong> ' .
                 __('You have posts that haven\'t been migrated to the table yet.', 'wp-dynamic-tags') . '</p>';
            echo '</div>';
        }

        echo '<div class="dt-action-buttons">';

        if ($migration_status !== 'in_progress') {
            echo '<button type="button" id="dt-migrate-button" class="button button-primary">';
            echo __('Migrate Posts to Table', 'wp-dynamic-tags') . '</button>';

            echo '<button type="button" class="dt-sync-button button" data-sync-type="table_to_posts">';
            echo __('Migrate Table to Posts', 'wp-dynamic-tags') . '</button>';

            echo '<button type="button" id="dt-reset-button" class="button button-secondary">';
            echo __('Reset Migration', 'wp-dynamic-tags') . '</button>';
        } else {
            echo '<p><em>' . __('Migration is currently in progress...', 'wp-dynamic-tags') . '</em></p>';
        }

        echo '</div>';
    }

    /**
     * Render import/export tools
     */
    private function render_import_export_tools($import_export) {
        if (!$import_export) {
            echo '<p>' . __('Import/Export manager not available.', 'wp-dynamic-tags') . '</p>';
            return;
        }

        echo '<p>' . __('Export your dynamic tags data or import from various formats.', 'wp-dynamic-tags') . '</p>';

        // Export section
        echo '<h3>' . __('Export Data', 'wp-dynamic-tags') . '</h3>';
        echo '<form id="dt-export-form">';
        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row">' . __('Export Format', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<select name="export_format" id="export-format">';
        echo '<option value="json">JSON</option>';
        echo '<option value="csv">CSV</option>';
        echo '<option value="xml">XML</option>';
        echo '<option value="sql">SQL</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . __('Filter by Status', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<select name="export_status" id="export-status">';
        echo '<option value="">' . __('All Statuses', 'wp-dynamic-tags') . '</option>';
        echo '<option value="active">' . __('Active Only', 'wp-dynamic-tags') . '</option>';
        echo '<option value="inactive">' . __('Inactive Only', 'wp-dynamic-tags') . '</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . __('Filter by Category', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<select name="export_category" id="export-category">';
        echo '<option value="">' . __('All Categories', 'wp-dynamic-tags') . '</option>';
        $categories = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
        if (!is_wp_error($categories)) {
            foreach ($categories as $category) {
                echo '<option value="' . $category->term_id . '">' . esc_html($category->name) . '</option>';
            }
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="button" id="dt-export-button" class="button button-primary">' . __('Export Data', 'wp-dynamic-tags') . '</button>';
        echo '</p>';
        echo '</form>';

        // Import section
        echo '<h3>' . __('Import Data', 'wp-dynamic-tags') . '</h3>';
        echo '<form id="dt-import-form" enctype="multipart/form-data">';
        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row">' . __('Import File', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<input type="file" name="import_file" id="import-file" accept=".json,.csv,.xml,.sql" required>';
        echo '<p class="description">' . __('Choose a file to import. Supported formats: JSON, CSV, XML, SQL', 'wp-dynamic-tags') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . __('Import Options', 'wp-dynamic-tags') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="update_existing" id="update-existing" value="1"> ' . __('Update existing tags', 'wp-dynamic-tags') . '</label><br>';
        echo '<label><input type="checkbox" name="skip_duplicates" id="skip-duplicates" value="1" checked> ' . __('Skip duplicate entries', 'wp-dynamic-tags') . '</label><br>';
        echo '<p class="description">' . __('Choose how to handle existing tags during import.', 'wp-dynamic-tags') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="button" id="dt-validate-import-button" class="button">' . __('Validate Import', 'wp-dynamic-tags') . '</button>';
        echo '<button type="button" id="dt-import-button" class="button button-primary">' . __('Import Data', 'wp-dynamic-tags') . '</button>';
        echo '</p>';
        echo '</form>';

        echo '<div id="dt-import-results" style="display: none;"></div>';
    }

    /**
     * Render sync tools
     */
    private function render_sync_tools($bridge) {
        if (!$bridge) {
            echo '<p>' . __('Sync bridge not available.', 'wp-dynamic-tags') . '</p>';
            return;
        }

        $sync_stats = $bridge->get_sync_statistics();

        echo '<p>' . __('Synchronize data between posts and table records.', 'wp-dynamic-tags') . '</p>';

        if ($sync_stats['posts_without_table_records'] > 0) {
            echo '<div class="notice notice-warning inline">';
            echo '<p>' . sprintf(
                __('%d posts found without corresponding table records.', 'wp-dynamic-tags'),
                $sync_stats['posts_without_table_records']
            ) . '</p>';
            echo '</div>';
        }

        if ($sync_stats['table_records_without_posts'] > 0) {
            echo '<div class="notice notice-warning inline">';
            echo '<p>' . sprintf(
                __('%d table records found without corresponding posts.', 'wp-dynamic-tags'),
                $sync_stats['table_records_without_posts']
            ) . '</p>';
            echo '</div>';
        }

        echo '<div class="dt-action-buttons">';

        echo '<button type="button" class="dt-sync-button button" data-sync-type="posts_to_table">';
        echo __('Sync Posts to Table', 'wp-dynamic-tags') . '</button>';

        echo '<button type="button" class="dt-sync-button button" data-sync-type="table_to_posts">';
        echo __('Sync Table to Posts', 'wp-dynamic-tags') . '</button>';

        echo '<button type="button" class="dt-sync-button button" data-sync-type="bidirectional">';
        echo __('Bidirectional Sync', 'wp-dynamic-tags') . '</button>';

        echo '</div>';
    }

    /**
     * Render statistics
     */
    private function render_statistics($table_manager, $migration_manager, $bridge) {
        $table_stats = $table_manager ? $table_manager->get_statistics() : null;
        $migration_stats = $migration_manager->get_migration_statistics();
        $sync_stats = $bridge ? $bridge->get_sync_statistics() : null;

        echo '<div style="display: flex; flex-wrap: wrap; gap: 20px;">';

        // Table Statistics
        if ($table_stats) {
            echo '<div style="flex: 1; min-width: 250px;">';
            echo '<h4>' . __('Table Statistics', 'wp-dynamic-tags') . '</h4>';
            echo '<ul>';
            echo '<li>' . __('Total Tags:', 'wp-dynamic-tags') . ' ' . $table_stats['total_tags'] . '</li>';
            echo '<li>' . __('Active Tags:', 'wp-dynamic-tags') . ' ' . $table_stats['active_tags'] . '</li>';
            echo '<li>' . __('Inactive Tags:', 'wp-dynamic-tags') . ' ' . $table_stats['inactive_tags'] . '</li>';
            if ($table_stats['most_used_tag']) {
                echo '<li>' . __('Most Used Tag:', 'wp-dynamic-tags') . ' ' . $table_stats['most_used_tag']->tag_name .
                     ' (' . $table_stats['most_used_tag']->usage_count . ' ' . __('uses', 'wp-dynamic-tags') . ')</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Migration Statistics
        echo '<div style="flex: 1; min-width: 250px;">';
        echo '<h4>' . __('Migration Statistics', 'wp-dynamic-tags') . '</h4>';
        echo '<ul>';
        echo '<li>' . __('Total Posts:', 'wp-dynamic-tags') . ' ' . $migration_stats['total_posts'] . '</li>';
        echo '<li>' . __('Published Posts:', 'wp-dynamic-tags') . ' ' . $migration_stats['published_posts'] . '</li>';
        echo '<li>' . __('Draft Posts:', 'wp-dynamic-tags') . ' ' . $migration_stats['draft_posts'] . '</li>';
        echo '<li>' . __('Table Records:', 'wp-dynamic-tags') . ' ' . $migration_stats['total_table_records'] . '</li>';
        echo '<li>' . __('Migration Status:', 'wp-dynamic-tags') . ' ' . ucfirst(str_replace('_', ' ', $migration_stats['migration_status'])) . '</li>';
        echo '</ul>';
        echo '</div>';

        // Sync Statistics
        if ($sync_stats) {
            echo '<div style="flex: 1; min-width: 250px;">';
            echo '<h4>' . __('Sync Statistics', 'wp-dynamic-tags') . '</h4>';
            echo '<ul>';
            echo '<li>' . __('Posts with Table Records:', 'wp-dynamic-tags') . ' ' . $sync_stats['posts_with_table_records'] . '</li>';
            echo '<li>' . __('Posts without Table Records:', 'wp-dynamic-tags') . ' ' . $sync_stats['posts_without_table_records'] . '</li>';
            echo '<li>' . __('Table Records without Posts:', 'wp-dynamic-tags') . ' ' . $sync_stats['table_records_without_posts'] . '</li>';
            echo '<li>' . __('Sync Enabled:', 'wp-dynamic-tags') . ' ' . ($sync_stats['sync_enabled'] ? __('Yes', 'wp-dynamic-tags') : __('No', 'wp-dynamic-tags')) . '</li>';
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render migration log
     */
    private function render_migration_log($migration_manager) {
        $log_entries = $migration_manager->get_migration_log();

        if (empty($log_entries)) {
            echo '<p>' . __('No migration log entries found.', 'wp-dynamic-tags') . '</p>';
            return;
        }

        echo '<div class="dt-migration-log">';

        foreach (array_reverse($log_entries) as $entry) {
            $class = isset($entry['type']) ? $entry['type'] : 'info';
            echo '<div class="dt-log-entry ' . esc_attr($class) . '">';
            echo '<strong>' . $entry['timestamp'] . '</strong> [' . strtoupper($class) . '] ' . esc_html($entry['message']);
            echo '</div>';
        }

        echo '</div>';

        echo '<p>';
        echo '<a href="' . add_query_arg(array(
            'page' => $this->page_slug,
            'action' => 'clear_log',
            '_wpnonce' => wp_create_nonce('clear_migration_log')
        ), admin_url('edit.php?post_type=' . $this->post_type)) . '" class="button">';
        echo __('Clear Log', 'wp-dynamic-tags') . '</a>';
        echo '</p>';
    }

    /**
     * Show admin notices
     */
    private function show_notices() {
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            $notices = array(
                'table_integration_enabled' => array('success', __('Table integration has been enabled.', 'wp-dynamic-tags')),
                'table_integration_disabled' => array('warning', __('Table integration has been disabled.', 'wp-dynamic-tags')),
                'migration_completed' => array('success', __('Migration completed successfully.', 'wp-dynamic-tags')),
                'sync_completed' => array('success', __('Synchronization completed successfully.', 'wp-dynamic-tags')),
                'reset_completed' => array('success', __('Table reset completed successfully.', 'wp-dynamic-tags'))
            );

            if (isset($notices[$message])) {
                list($type, $text) = $notices[$message];
                echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . $text . '</p></div>';
            }
        }
    }

    /**
     * AJAX: Handle migration
     */
    public function ajax_migrate() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $migration_manager = $plugin->get_migration_manager();

        $result = $migration_manager->migrate_posts_to_table();

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Migration completed. %d tags migrated successfully.', 'wp-dynamic-tags'),
                    $result['migrated']
                )
            ));
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX: Handle sync
     */
    public function ajax_sync() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $sync_type = sanitize_text_field($_POST['sync_type']);
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $bridge = $plugin->get_post_table_bridge();

        if (!$bridge) {
            wp_send_json_error('Sync bridge not available');
        }

        switch ($sync_type) {
            case 'posts_to_table':
                $result = $bridge->force_sync_posts_to_table();
                break;
            case 'table_to_posts':
                $result = $bridge->force_sync_table_to_posts();
                break;
            case 'bidirectional':
                $result1 = $bridge->force_sync_posts_to_table();
                $result2 = $bridge->force_sync_table_to_posts();
                $result = array(
                    'synced' => $result1['synced'] + $result2['synced'],
                    'errors' => $result1['errors'] + $result2['errors']
                );
                break;
            default:
                wp_send_json_error('Invalid sync type');
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Sync completed. %d records synced, %d errors.', 'wp-dynamic-tags'),
                $result['synced'],
                $result['errors']
            )
        ));
    }

    /**
     * AJAX: Handle reset
     */
    public function ajax_reset() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $migration_manager = $plugin->get_migration_manager();

        $migration_manager->reset_migration();

        wp_send_json_success(array(
            'message' => __('Table reset completed successfully.', 'wp-dynamic-tags')
        ));
    }

    /**
     * AJAX: Get updated statistics
     */
    public function ajax_statistics() {
        check_ajax_referer('dt_table_manager', 'nonce');

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $db_manager = $plugin->get_db_manager();
        $migration_manager = $plugin->get_migration_manager();
        $table_manager = $plugin->get_table_manager();
        $bridge = $plugin->get_post_table_bridge();

        ob_start();
        $this->render_status_overview($db_manager, $migration_manager, $bridge);
        $status_html = ob_get_clean();

        ob_start();
        $this->render_statistics($table_manager, $migration_manager, $bridge);
        $stats_html = ob_get_clean();

        wp_send_json_success(array(
            'status_html' => $status_html,
            'stats_html' => $stats_html
        ));
    }

    /**
     * AJAX: Handle export
     */
    public function ajax_export() {
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $import_export = $plugin->get_table_import_export();

        if ($import_export) {
            $import_export->ajax_export();
        } else {
            wp_send_json_error('Import/Export manager not available');
        }
    }

    /**
     * AJAX: Handle import
     */
    public function ajax_import() {
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $import_export = $plugin->get_table_import_export();

        if ($import_export) {
            $import_export->ajax_import();
        } else {
            wp_send_json_error('Import/Export manager not available');
        }
    }

    /**
     * AJAX: Handle table creation
     */
    public function ajax_create_table() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $db_manager = $plugin->get_db_manager();

        if (!$db_manager) {
            wp_send_json_error('Database manager not available');
        }

        // Check if table already exists
        if ($db_manager->table_exists()) {
            wp_send_json_success(array(
                'message' => __('Table already exists.', 'wp-dynamic-tags'),
                'already_exists' => true
            ));
        }

        // Attempt to create the table
        $result = $db_manager->create_table();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Verify table was created
        if ($db_manager->table_exists()) {
            // Clear any previous error flags
            delete_option('wp_dynamic_tags_table_creation_error');

            wp_send_json_success(array(
                'message' => __('Table created successfully.', 'wp-dynamic-tags'),
                'created' => true
            ));
        } else {
            wp_send_json_error(__('Table creation appeared to succeed but table still does not exist.', 'wp-dynamic-tags'));
        }
    }

    /**
     * AJAX: Handle table health check
     */
    public function ajax_check_table_health() {
        check_ajax_referer('dt_table_manager', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $db_manager = $plugin->get_db_manager();

        if (!$db_manager) {
            wp_send_json_error('Database manager not available');
        }

        $health_report = array();

        // Check if table exists
        $table_exists = $db_manager->table_exists();
        $health_report['table_exists'] = $table_exists;

        if ($table_exists) {
            // Check table structure
            global $wpdb;
            $table_name = $wpdb->prefix . 'dynamic_tags';

            $columns = $wpdb->get_results("DESCRIBE {$table_name}");
            $health_report['columns_count'] = count($columns);

            // Check for required columns
            $required_columns = array('id', 'tag_name', 'shortcode', 'content', 'status', 'created_date');
            $existing_columns = array_column($columns, 'Field');
            $missing_columns = array_diff($required_columns, $existing_columns);
            $health_report['missing_columns'] = $missing_columns;

            // Check indexes
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
            $health_report['indexes_count'] = count($indexes);

            // Test basic operations
            try {
                $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                $health_report['read_access'] = true;
            } catch (Exception $e) {
                $health_report['read_access'] = false;
                $health_report['read_error'] = $e->getMessage();
            }

            // Test write access
            try {
                $wpdb->query("INSERT INTO {$table_name} (tag_name, shortcode, content, status) VALUES ('__test__', '__test__', '__test__', 'inactive')");
                $test_id = $wpdb->insert_id;
                if ($test_id) {
                    $wpdb->delete($table_name, array('id' => $test_id));
                    $health_report['write_access'] = true;
                } else {
                    $health_report['write_access'] = false;
                }
            } catch (Exception $e) {
                $health_report['write_access'] = false;
                $health_report['write_error'] = $e->getMessage();
            }

            // Get record count
            $health_report['record_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        } else {
            // Check for creation errors
            $creation_error = get_option('wp_dynamic_tags_table_creation_error');
            if ($creation_error) {
                $health_report['creation_error'] = $creation_error;
            }
        }

        // Determine overall health status
        if ($table_exists && empty($health_report['missing_columns']) &&
            $health_report['read_access'] && $health_report['write_access']) {
            $health_report['status'] = 'healthy';
            $health_report['message'] = __('Table is healthy and functioning properly.', 'wp-dynamic-tags');
        } elseif ($table_exists) {
            $health_report['status'] = 'warning';
            $health_report['message'] = __('Table exists but has some issues.', 'wp-dynamic-tags');
        } else {
            $health_report['status'] = 'error';
            $health_report['message'] = __('Table does not exist.', 'wp-dynamic-tags');
        }

        wp_send_json_success($health_report);
    }
}