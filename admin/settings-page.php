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
            WP_Dynamic_Tags_Admin::MENU,
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
     * A two-column table of facts.
     *
     * @param array<int,array{0:string,1:string}> $rows Label and value; the value is already escaped.
     */
    private function facts_table(array $rows) {
        echo '<table class="widefat striped dt-facts"><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . $row[1] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every caller passes a value that is already escaped
        }
        echo '</tbody></table>';
    }

    /**
     * "Tags and your server": how tags work, what a lot of tags cost, where this site stands (live numbers) and what each kind
     * of hosting carries. The figures live in WP_Dynamic_Tags_Capacity_Advisor (measured in Phase 9c, docs/BUG-REGISTER.md).
     */
    private function render_capacity_section() {
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();

        $total = (int) $db->get_tags_count(array('status' => ''));
        $active = (int) $db->get_tags_count(array('status' => 'active'));
        $inactive = max(0, $total - $active);

        $groups = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
        $groups_count = is_array($groups) ? count($groups) : 0;

        $stats = $processor->get_performance_stats();
        $register_ms = isset($stats['shortcode_registration']['execution_time']) ? (float) $stats['shortcode_registration']['execution_time'] * 1000 : null;

        // WordPress raises this limit inside wp-admin, so what visitors get can be lower: the caption below says so.
        $limit = ini_get('memory_limit');
        $limit_bytes = (is_string($limit) && $limit !== '') ? (int) wp_convert_hr_to_bytes($limit) : 0;
        if ($limit_bytes === -1) {
            $limit_label = __('Unlimited', 'wp-dynamic-tags');
        } elseif ($limit_bytes > 0) {
            $limit_label = (string) size_format($limit_bytes);
        } else {
            $limit_label = __('Unknown', 'wp-dynamic-tags');
        }

        // A cache run by the host (not a plugin) cannot be seen from here: sites with one can return true from the filter.
        $page_cache = (bool) apply_filters('dt_page_cache_active', (defined('WP_CACHE') && WP_CACHE) || defined('LSCWP_V') || defined('WP_ROCKET_VERSION'));

        $assessment = WP_Dynamic_Tags_Capacity_Advisor::assess($active, $limit_bytes, $page_cache);
        $estimate = WP_Dynamic_Tags_Capacity_Advisor::estimate($active);
        $safe = number_format_i18n($assessment['safe_max']);

        $badges = array(
            'ok'    => array('dt-badge-on', __('Fine', 'wp-dynamic-tags')),
            'watch' => array('dt-badge-warn', __('Watch', 'wp-dynamic-tags')),
            'high'  => array('dt-badge-bad', __('Too many', 'wp-dynamic-tags')),
        );
        $badge = $badges[$assessment['level']];

        /* translators: %s: number of active tags */
        $advice = sprintf(_n('You have %s active tag.', 'You have %s active tags.', $active, 'wp-dynamic-tags'), number_format_i18n($active)) . ' ';
        if ($assessment['reason'] === 'memory') {
            /* translators: 1: memory the tags take in MB, 2: the PHP memory limit */
            $advice .= sprintf(__('The tags take about %1$s MB of every request, a quarter or more of your PHP memory limit (%2$s). Raise the memory limit or deactivate tags you do not use.', 'wp-dynamic-tags'), number_format_i18n($estimate['memory_mb'], 1), $limit_label);
        } elseif ($assessment['level'] === 'high') {
            $advice .= __('That will make every page that is not served from a page cache, and the admin, noticeably slower, and a small host can run out of memory. Deactivate tags you do not use, or move the site to a stronger server.', 'wp-dynamic-tags');
        } elseif ($assessment['level'] === 'watch') {
            /* translators: 1: the recommended maximum, 2: extra milliseconds, 3: extra megabytes */
            $advice .= sprintf(__('That is above the recommended maximum of %1$s for a small host. Expect about %2$s ms and %3$s MB extra on every page that is not served from a page cache. Deactivate tags you do not use, or put a page cache in front of the site.', 'wp-dynamic-tags'), $safe, number_format_i18n($estimate['extra_ms']), number_format_i18n($estimate['memory_mb'], 1));
        } else {
            /* translators: %s: the recommended maximum number of tags */
            $advice .= sprintf(__('That is within the recommended maximum of %s for a small host.', 'wp-dynamic-tags'), $safe);
            if (!$page_cache) {
                /* translators: %s: the recommended maximum with a page cache */
                $advice .= ' ' . sprintf(__('With a page cache in front of the site the maximum is %s.', 'wp-dynamic-tags'), number_format_i18n(WP_Dynamic_Tags_Capacity_Advisor::SAFE_MAX_CACHED));
            }
        }

        if ($estimate['memory_mb'] < 0.1 && $estimate['extra_ms'] < 1) {
            $adds = __('Next to nothing (under 0.1 MB and 1 ms per uncached page)', 'wp-dynamic-tags');
        } else {
            /* translators: 1: megabytes, 2: milliseconds */
            $adds = sprintf(__('about %1$s MB and %2$s ms per uncached page', 'wp-dynamic-tags'), number_format_i18n($estimate['memory_mb'], 1), number_format_i18n($estimate['extra_ms']));
        }

        $kinds = array(
            'entry'     => __('Entry shared hosting (about 1 CPU core, 1 GB of RAM)', 'wp-dynamic-tags'),
            'mid'       => __('Mid-range shared or cloud hosting (2 or more cores, 2 to 4 GB of RAM)', 'wp-dynamic-tags'),
            'vps'       => __('VPS or managed WordPress hosting (4 GB of RAM or more, PHP opcache, an object cache)', 'wp-dynamic-tags'),
            'dedicated' => __('Large dedicated server', 'wp-dynamic-tags'),
        );
        $number = static function ($value) {
            return $value === null ? '&mdash;' : esc_html(number_format_i18n((int) $value));
        };
        ?>
        <div class="postbox dt-capacity">
            <div class="inside">
                <h2><?php esc_html_e('Tags and your server', 'wp-dynamic-tags'); ?></h2>

                <h3><?php esc_html_e('How Dynamic Tags works', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Every tag is one row in the plugin\'s database table: a name, a shortcode, the content and a status.', 'wp-dynamic-tags'); ?></li>
                    <li><?php esc_html_e('Every active tag is registered as a shortcode, for example [your_tag], on every request: front end, admin, AJAX, cron and REST. Inactive tags are not loaded.', 'wp-dynamic-tags'); ?></li>
                    <li><?php esc_html_e('When a page is shown, WordPress runs the shortcode and the plugin fills in placeholders such as {site_name} or {post_title} for that page and that visitor.', 'wp-dynamic-tags'); ?></li>
                    <li><?php esc_html_e('Showing a tag never writes to the database. Saving a tag does, and the change appears on the whole site at once: the supported page-cache plugins are cleared automatically.', 'wp-dynamic-tags'); ?></li>
                </ul>

                <h3><?php esc_html_e('Why a lot of tags can slow your site down', 'wp-dynamic-tags'); ?></h3>
                <p><?php esc_html_e('Because every active tag is registered on every request, each tag costs a little memory and time on every page that is not served from a page cache. WordPress also compares every registered shortcode name each time it processes content, so the cost grows with the number of tags. A few hundred tags are not noticeable; thousands are.', 'wp-dynamic-tags'); ?></p>
                <table class="widefat striped dt-measured">
                    <caption><?php esc_html_e('Measured on a development PC (PHP 8.2, MySQL 8.4, no page cache). Shared hosting can be about twice as slow.', 'wp-dynamic-tags'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Active tags', 'wp-dynamic-tags'); ?></th>
                            <th scope="col"><?php esc_html_e('Extra memory per request', 'wp-dynamic-tags'); ?></th>
                            <th scope="col"><?php esc_html_e('Extra time per uncached page', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (WP_Dynamic_Tags_Capacity_Advisor::measured() as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(number_format_i18n($row['tags'])); ?></td>
                            <td><?php /* translators: %s: megabytes */ echo esc_html(sprintf(__('%s MB', 'wp-dynamic-tags'), number_format_i18n($row['memory_mb']))); ?></td>
                            <td><?php /* translators: %s: milliseconds */ echo esc_html(sprintf(__('%s ms', 'wp-dynamic-tags'), number_format_i18n($row['extra_ms']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Your site now', 'wp-dynamic-tags'); ?></h3>
                <?php
                $this->facts_table(array(
                    array(__('Tags', 'wp-dynamic-tags'), esc_html(sprintf(/* translators: 1: all tags, 2: active tags, 3: inactive tags */ __('%1$s (%2$s active, %3$s inactive)', 'wp-dynamic-tags'), number_format_i18n($total), number_format_i18n($active), number_format_i18n($inactive)))),
                    array(__('Tag groups', 'wp-dynamic-tags'), esc_html(number_format_i18n($groups_count))),
                    array(__('Shortcodes registered in this request', 'wp-dynamic-tags'), esc_html(number_format_i18n(count($processor->get_registered_shortcodes())))),
                    array(__('Time to load and register the tags in this request', 'wp-dynamic-tags'), $register_ms === null ? '&mdash;' : esc_html(sprintf(/* translators: %s: milliseconds */ __('%s ms', 'wp-dynamic-tags'), number_format_i18n($register_ms, 1)))),
                    array(__('What your active tags add (estimate)', 'wp-dynamic-tags'), esc_html($adds)),
                    array(__('PHP version', 'wp-dynamic-tags'), esc_html(PHP_VERSION)),
                    array(__('PHP memory limit', 'wp-dynamic-tags'), esc_html($limit_label)),
                    array(__('Page cache', 'wp-dynamic-tags'), esc_html($page_cache ? __('Detected', 'wp-dynamic-tags') : __('Not detected (a cache run by your host cannot be seen from here)', 'wp-dynamic-tags'))),
                    array(__('Status', 'wp-dynamic-tags'), '<span class="dt-badge ' . esc_attr($badge[0]) . '">' . esc_html($badge[1]) . '</span> ' . esc_html($advice)),
                ));
                ?>
                <p class="description"><?php esc_html_e('WordPress raises the PHP memory limit inside wp-admin, so the limit your visitors get can be lower than the one shown here.', 'wp-dynamic-tags'); ?></p>

                <h3><?php esc_html_e('Recommended tag counts by hosting type', 'wp-dynamic-tags'); ?></h3>
                <table class="widefat striped dt-tiers">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Hosting', 'wp-dynamic-tags'); ?></th>
                            <th scope="col"><?php esc_html_e('Comfortable', 'wp-dynamic-tags'); ?></th>
                            <th scope="col"><?php esc_html_e('Recommended maximum', 'wp-dynamic-tags'); ?></th>
                            <th scope="col"><?php esc_html_e('With a page cache', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (WP_Dynamic_Tags_Capacity_Advisor::tiers() as $key => $tier) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($kinds[$key]); ?></th>
                            <td><?php echo $number($tier['comfortable']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the closure ?></td>
                            <td><?php echo $number($tier['max']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the closure ?></td>
                            <td><?php echo $number($tier['max_cached']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the closure ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('The first row follows from the limits of a real shared-hosting plan (1 CPU core, 1 GB of RAM) and the measurements above; the other rows are estimates scaled from it. A table of 50,000 tags was imported, listed, searched and exported without errors in testing: that is a stress result, not a support claim.', 'wp-dynamic-tags'); ?></p>

                <h3><?php esc_html_e('What you can do', 'wp-dynamic-tags'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Deactivate or delete tags you no longer use: inactive tags are not loaded.', 'wp-dynamic-tags'); ?></li>
                    <li><?php esc_html_e('Use a page cache, so visitors do not run PHP for every page view. Changing a tag clears the supported caches automatically.', 'wp-dynamic-tags'); ?></li>
                    <li><?php
                    /* translators: %s: the name of a filter, in code tags */
                    printf(esc_html__('On a site with a very large number of tags, developers can stop tags being registered on REST requests with the filter %s.', 'wp-dynamic-tags'), '<code>dt_register_shortcodes_on_rest</code>');
                    ?></li>
                    <li><?php esc_html_e('An import is limited to 5,000 rows at a time by default. Check the numbers above before importing thousands of tags.', 'wp-dynamic-tags'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Settings page content
     */
    public function settings_page() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'wp-dynamic-tags'),
                esc_html__('Permission Denied', 'wp-dynamic-tags'),
                array('response' => 403)
            );
        }

        ?>
        <div class="wrap dt-wrap">
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
                    <span id="dt-plan-badge" style="padding:2px 10px;border-radius:3px;color:#fff;background:<?php echo $is_paid ? '#007017' : '#646970'; ?>;">
                        <?php echo esc_html($plan_label); ?>
                    </span>
                    <?php if ($is_paid && $expiry) : ?>
                        <span class="description"><?php /* translators: %s: the date the license expires */ echo esc_html(sprintf(__('(expires %s)', 'wp-dynamic-tags'), $expiry)); ?></span>
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

            <!-- How tags work, what many tags cost, where this site stands -->
            <?php $this->render_capacity_section(); ?>

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
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dt-import-export')); ?>"
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
