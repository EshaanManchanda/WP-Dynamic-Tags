<?php
/**
 * Settings Page for WP Dynamic Tags
 *
 * Provides settings for data retention and plugin behavior
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Settings_Page {

    private $post_type = 'dynamic_tag';

    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_settings_menu'), 25);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add settings menu
     */
    public function add_settings_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Settings', 'wp-dynamic-tags'),
            __('Settings', 'wp-dynamic-tags'),
            'manage_options',
            'dt-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register the delete on uninstall setting
        register_setting(
            'wp_dynamic_tags_settings',
            'wp_dynamic_tags_delete_on_uninstall',
            array(
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => array($this, 'sanitize_checkbox')
            )
        );

        // Add settings section
        add_settings_section(
            'wp_dynamic_tags_general_section',
            __('General Settings', 'wp-dynamic-tags'),
            array($this, 'general_section_callback'),
            'wp_dynamic_tags_settings'
        );

        // Add delete on uninstall field
        add_settings_field(
            'wp_dynamic_tags_delete_on_uninstall',
            __('Delete Data on Uninstall', 'wp-dynamic-tags'),
            array($this, 'delete_on_uninstall_callback'),
            'wp_dynamic_tags_settings',
            'wp_dynamic_tags_general_section'
        );

        // Usage counting: off by default, because counting means a database write for every page that shows a tag
        register_setting(
            'wp_dynamic_tags_settings',
            'wp_dynamic_tags_track_usage',
            array(
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => array($this, 'sanitize_checkbox')
            )
        );
        add_settings_field(
            'wp_dynamic_tags_track_usage',
            __('Count Tag Usage', 'wp-dynamic-tags'),
            array($this, 'track_usage_callback'),
            'wp_dynamic_tags_settings',
            'wp_dynamic_tags_general_section'
        );

        // License server URL (advanced — leave blank to share Certificate Generator's server)
        register_setting(
            'wp_dynamic_tags_settings',
            'dt_license_server_url',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'esc_url_raw'
            )
        );

        add_settings_section(
            'wp_dynamic_tags_license_section',
            __('License', 'wp-dynamic-tags'),
            array($this, 'license_section_callback'),
            'wp_dynamic_tags_settings'
        );

        add_settings_field(
            'dt_license_server_url',
            __('License Server URL', 'wp-dynamic-tags'),
            array($this, 'license_server_url_callback'),
            'wp_dynamic_tags_settings',
            'wp_dynamic_tags_license_section'
        );
    }

    /**
     * License section description
     */
    public function license_section_callback() {
        echo '<p>' . esc_html__('Activate a license key to unlock the Paid plan (unlimited tags, WooCommerce/ACF arrays, dt_loop, dt_if, query/api data sources).', 'wp-dynamic-tags') . '</p>';
    }

    /**
     * License server URL field callback
     */
    public function license_server_url_callback() {
        $value = get_option('dt_license_server_url', '');
        ?>
        <input type="url"
               name="dt_license_server_url"
               value="<?php echo esc_attr($value); ?>"
               placeholder="https://your-license-server.com"
               class="regular-text">
        <p class="description">
            <?php esc_html_e('Leave blank to reuse the Certificate Generator plugin\'s configured license server, if any.', 'wp-dynamic-tags'); ?>
        </p>
        <?php
    }

    /**
     * Sanitize checkbox input
     */
    public function sanitize_checkbox($input) {
        return ($input === true || $input === 'on' || $input === '1' || $input === 1) ? true : false;
    }

    /**
     * General section description
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure how WP Dynamic Tags handles your data.', 'wp-dynamic-tags') . '</p>';
    }

    /**
     * Delete on uninstall field callback
     */
    public function delete_on_uninstall_callback() {
        $value = get_option('wp_dynamic_tags_delete_on_uninstall', false);
        ?>
        <label>
            <input type="checkbox"
                   name="wp_dynamic_tags_delete_on_uninstall"
                   value="1"
                   <?php checked($value, true); ?>>
            <?php esc_html_e('Delete all plugin data when uninstalling', 'wp-dynamic-tags'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('If unchecked (recommended), your tags and groups will be preserved if you reinstall the plugin later.', 'wp-dynamic-tags'); ?>
        </p>
        <?php
    }

    /**
     * Usage counting field callback
     */
    public function track_usage_callback() {
        $value = get_option('wp_dynamic_tags_track_usage', false);
        ?>
        <label>
            <input type="checkbox"
                   name="wp_dynamic_tags_track_usage"
                   value="1"
                   <?php checked($value, true); ?>>
            <?php esc_html_e('Count how often each tag is shown', 'wp-dynamic-tags'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Off by default. When on, visits are counted in memory and written once at the end of each page request (one database update per tag shown); admin, AJAX, cron and REST requests are never counted. Usage columns show a dash while this is off.', 'wp-dynamic-tags'); ?>
        </p>
        <?php
    }

    /**
     * Settings page content
     */
    public function settings_page() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'wp-dynamic-tags'),
                __('Permission Denied', 'wp-dynamic-tags'),
                array('response' => 403)
            );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'dynamic_tags';

        // Get statistics
        $tags_count = wp_count_posts('dynamic_tag');
        $published_tags = isset($tags_count->publish) ? $tags_count->publish : 0;

        $groups = get_terms(array(
            'taxonomy' => 'tag_groups',
            'hide_empty' => false
        ));
        $groups_count = is_array($groups) ? count($groups) : 0;

        $table_tags_count = 0;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name) {
            $table_tags_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Dynamic Tags - Settings', 'wp-dynamic-tags'); ?></h1>

            <?php settings_errors('wp_dynamic_tags_settings'); ?>

            <!-- License -->
            <div class="postbox" style="margin: 20px 0; padding: 15px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('License', 'wp-dynamic-tags'); ?></h2>
                <?php
                $is_paid    = DT_License_Manager::is_paid();
                $plan_label = DT_License_Manager::get_plan_label();
                $license_key = DT_License_Manager::get_license_key();
                $expiry      = DT_License_Manager::get_expiry();
                ?>
                <p>
                    <strong><?php esc_html_e('Current plan:', 'wp-dynamic-tags'); ?></strong>
                    <span id="dt-plan-badge" style="padding:2px 10px;border-radius:3px;color:#fff;background:<?php echo $is_paid ? '#00a32a' : '#787c82'; ?>;">
                        <?php echo esc_html($plan_label); ?>
                    </span>
                    <?php if ($is_paid && $expiry) : ?>
                        <span class="description"><?php echo esc_html(sprintf(__('(expires %s)', 'wp-dynamic-tags'), $expiry)); ?></span>
                    <?php endif; ?>
                </p>

                <div id="dt-license-notice"></div>

                <p>
                    <input type="text" id="dt-license-key" class="regular-text"
                           placeholder="<?php esc_attr_e('Enter license key', 'wp-dynamic-tags'); ?>"
                           value="<?php echo esc_attr($license_key); ?>"
                           <?php disabled($is_paid); ?>>
                    <button type="button" id="dt-license-activate" class="button button-primary" <?php echo $is_paid ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Activate', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" id="dt-license-deactivate" class="button" <?php echo $is_paid ? '' : 'style="display:none;"'; ?>>
                        <?php esc_html_e('Deactivate', 'wp-dynamic-tags'); ?>
                    </button>
                    <span class="spinner" id="dt-license-spinner" style="float:none;"></span>
                </p>

                <table class="widefat" style="max-width: 700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Feature', 'wp-dynamic-tags'); ?></th>
                            <th><?php esc_html_e('Free', 'wp-dynamic-tags'); ?></th>
                            <th><?php esc_html_e('Paid', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (DT_License_Manager::get_plan_features() as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td><?php echo $row['free'] === true ? '✅' : ($row['free'] === false ? '❌' : esc_html($row['free'])); ?></td>
                            <td><?php echo $row['paid'] === true ? '✅' : ($row['paid'] === false ? '❌' : esc_html($row['paid'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <script>
            (function($){
                var nonce = '<?php echo esc_js(wp_create_nonce('dt_license_nonce')); ?>';
                var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

                function setBusy(busy) {
                    $('#dt-license-spinner').toggleClass('is-active', busy);
                    $('#dt-license-activate, #dt-license-deactivate').prop('disabled', busy);
                }

                function showNotice(message, isError) {
                    var cls = isError ? 'notice-error' : 'notice-success';
                    $('#dt-license-notice').html(
                        '<div class="notice ' + cls + '" style="padding:8px 12px;"><p>' + message + '</p></div>'
                    );
                }

                $('#dt-license-activate').on('click', function(){
                    var key = $('#dt-license-key').val();
                    if (!key) { return; }
                    setBusy(true);
                    $.post(ajaxUrl, { action: 'dt_activate_license', nonce: nonce, license_key: key })
                        .done(function(res){
                            showNotice(res.data && res.data.message ? res.data.message : '', !res.success);
                            if (res.success) { location.reload(); }
                        })
                        .fail(function(){ showNotice('<?php echo esc_js(__('Request failed. Please try again.', 'wp-dynamic-tags')); ?>', true); })
                        .always(function(){ setBusy(false); });
                });

                $('#dt-license-deactivate').on('click', function(){
                    if (!confirm('<?php echo esc_js(__('Deactivate this license?', 'wp-dynamic-tags')); ?>')) { return; }
                    setBusy(true);
                    $.post(ajaxUrl, { action: 'dt_deactivate_license', nonce: nonce })
                        .done(function(res){
                            showNotice(res.data && res.data.message ? res.data.message : '', !res.success);
                            if (res.success) { location.reload(); }
                        })
                        .fail(function(){ showNotice('<?php echo esc_js(__('Request failed. Please try again.', 'wp-dynamic-tags')); ?>', true); })
                        .always(function(){ setBusy(false); });
                });
            })(jQuery);
            </script>

            <!-- Current Data Status -->
            <div class="notice notice-info" style="padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php esc_html_e('Current Data Status', 'wp-dynamic-tags'); ?></h3>
                <table class="widefat" style="max-width: 600px;">
                    <tr>
                        <td><strong><?php esc_html_e('Published Tags (Posts):', 'wp-dynamic-tags'); ?></strong></td>
                        <td><?php echo esc_html($published_tags); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Tags in Database Table:', 'wp-dynamic-tags'); ?></strong></td>
                        <td><?php echo esc_html($table_tags_count); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Tag Groups:', 'wp-dynamic-tags'); ?></strong></td>
                        <td><?php echo esc_html($groups_count); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Settings Form -->
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_dynamic_tags_settings');
                do_settings_sections('wp_dynamic_tags_settings');
                submit_button();
                ?>
            </form>

            <!-- Information Boxes -->
            <div class="dt-settings-info" style="margin-top: 30px;">

                <!-- Data Retention Info -->
                <div class="postbox" style="margin-bottom: 20px;">
                    <div class="inside" style="padding: 15px;">
                        <h3><?php esc_html_e('💾 Data Retention (Recommended)', 'wp-dynamic-tags'); ?></h3>
                        <p>
                            <?php esc_html_e('By default, your tags and groups are preserved when you uninstall the plugin. This means:', 'wp-dynamic-tags'); ?>
                        </p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li><?php esc_html_e('If you reinstall the plugin later, all your tags will work immediately', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Your shortcodes will be automatically restored', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('No data loss if you accidentally uninstall', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Easy to move your site between environments', 'wp-dynamic-tags'); ?></li>
                        </ul>
                        <p>
                            <strong><?php esc_html_e('What is preserved:', 'wp-dynamic-tags'); ?></strong>
                        </p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li><?php esc_html_e('All dynamic tag posts', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Custom database table (wp_dynamic_tags)', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Tag groups taxonomy', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Usage statistics', 'wp-dynamic-tags'); ?></li>
                        </ul>
                    </div>
                </div>

                <!-- Clean Uninstall Info -->
                <div class="postbox" style="margin-bottom: 20px;">
                    <div class="inside" style="padding: 15px;">
                        <h3><?php esc_html_e('🗑️ Clean Uninstall', 'wp-dynamic-tags'); ?></h3>
                        <p>
                            <?php esc_html_e('If you enable "Delete Data on Uninstall", the following will be permanently deleted:', 'wp-dynamic-tags'); ?>
                        </p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li><?php esc_html_e('All dynamic tag posts', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('All tag groups', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('Custom database table', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('All plugin options and settings', 'wp-dynamic-tags'); ?></li>
                            <li><?php esc_html_e('All transients and cached data', 'wp-dynamic-tags'); ?></li>
                        </ul>
                        <p style="color: #d63638;">
                            <strong><?php esc_html_e('⚠️ Warning: This action cannot be undone!', 'wp-dynamic-tags'); ?></strong>
                        </p>
                    </div>
                </div>

                <!-- Export Data Option -->
                <div class="postbox">
                    <div class="inside" style="padding: 15px;">
                        <h3><?php esc_html_e('💼 Backup Your Data', 'wp-dynamic-tags'); ?></h3>
                        <p>
                            <?php esc_html_e('Before uninstalling or making major changes, you can export your tags:', 'wp-dynamic-tags'); ?>
                        </p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=dynamic_tag&page=dt-import-export')); ?>"
                               class="button button-primary">
                                <?php esc_html_e('Go to Import/Export', 'wp-dynamic-tags'); ?>
                            </a>
                        </p>
                    </div>
                </div>

            </div>

        </div>

        <style>
        .dt-settings-info h3 {
            margin-top: 0;
            font-size: 16px;
        }
        .dt-settings-info ul {
            line-height: 1.8;
        }
        </style>
        <?php
    }
}

// Initialize settings page
new WP_Dynamic_Tags_Settings_Page();
