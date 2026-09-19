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
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $table_tags_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Dynamic Tags - Settings', 'wp-dynamic-tags'); ?></h1>

            <?php settings_errors('wp_dynamic_tags_settings'); ?>

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
