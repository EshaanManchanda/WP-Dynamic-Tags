<?php
/**
 * License Manager — Free/Paid plan state and remote activation.
 *
 * Mirrors Certificate-Generator-V7.5's CG_License_Manager architecture
 * (same remote contract: POST {server}/api/payments/activate-remote),
 * trimmed to two plans since Dynamic Tags has no usage-quota system —
 * see LICENSE-PLAN.md and LICENSE-IMPLEMENTATION-PROMPT.md in the plugin root.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DT_License_Manager
{
    const OPTION_PLAN        = 'dt_plan';
    const OPTION_LICENSE_KEY = 'dt_license_key';
    const OPTION_EXPIRY      = 'dt_license_expiry';
    const CRON_HEARTBEAT     = 'dt_license_heartbeat';

    const PLAN_FREE = 'free';
    const PLAN_PAID = 'paid';

    /** The portfolio backend that issues and checks keys (the same one Certificate Generator Pro uses). */
    const DEFAULT_LICENSE_SERVER = 'https://tlvapi.techlovev.in';

    /** How many tags a Free site can create itself (Add New, Duplicate, Import). Tags other plugins create do not count. */
    const FREE_TAG_LIMIT = 15;

    /** {prefix:...} placeholders that need the Paid plan; one gate in the placeholder class decides all of them. */
    const GATED_PREFIXES = array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func');

    /** ACF field types whose value is more than one scalar: Paid. A scalar field ({acf:name}) stays free. */
    const ACF_PAID_TYPES = array('repeater', 'flexible_content', 'group', 'gallery', 'relationship', 'post_object');

    public static function get_plan()
    {
        $plan = (string) get_option(self::OPTION_PLAN, self::PLAN_FREE);
        return $plan === self::PLAN_PAID ? self::PLAN_PAID : self::PLAN_FREE;
    }

    public static function is_paid()
    {
        return self::get_plan() === self::PLAN_PAID;
    }

    public static function get_plan_label()
    {
        return self::is_paid() ? __('Paid', 'wp-dynamic-tags') : __('Free', 'wp-dynamic-tags');
    }

    public static function get_license_key()
    {
        return (string) get_option(self::OPTION_LICENSE_KEY, '');
    }

    public static function get_expiry()
    {
        return (string) get_option(self::OPTION_EXPIRY, '');
    }

    /** A stored key as shown on the Settings screen: the first five and the last four characters, the rest hidden. */
    public static function mask_key($key): string
    {
        $key = (string) $key;
        if (strlen($key) <= 12) {
            return $key === '' ? '' : str_repeat("\u{2022}", strlen($key));
        }
        return substr($key, 0, 5) . str_repeat("\u{2022}", strlen($key) - 9) . substr($key, -4);
    }

    /**
     * Resolves the portfolio backend URL: the DT_LICENSE_SERVER constant, this plugin's setting, Certificate Generator's
     * configured server (so both plugins share one backend account, LICENSE-PLAN.md §6, without depending on that plugin),
     * then the default server. Only an https address is used: the license key must not travel in clear text, and an
     * address that is not https (or not an address at all) is skipped, never half-used.
     */
    public static function get_license_server()
    {
        $candidates = array(
            defined('DT_LICENSE_SERVER') ? (string) DT_LICENSE_SERVER : '',
            (string) get_option('dt_license_server_url', ''),
            (string) get_option('cg_license_server_url', ''),
        );
        foreach ($candidates as $candidate) {
            $url = esc_url_raw(trim($candidate), array('https'));
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }
        return self::DEFAULT_LICENSE_SERVER;
    }

    /**
     * Where a person goes to buy a Paid key. Empty until the site (or a later release) says: the plugin never invents an address.
     */
    public static function get_upgrade_url(): string
    {
        return esc_url_raw((string) apply_filters('dt_upgrade_url', ''), array('http', 'https'));
    }

    /** Whether a {prefix:...} placeholder needs the Paid plan. */
    public static function is_gated_prefix($prefix): bool
    {
        return in_array($prefix, self::GATED_PREFIXES, true);
    }

    /**
     * Whether an ACF field may be read on this site: scalar fields always, repeater/group/gallery/relationship values and
     * sub-fields ({acf:rows.name}) on Paid only.
     *
     * @param string|null $subfield The part after the dot, if any.
     * @param string|null $type     The ACF field type, null when unknown (treated as a scalar, as before).
     */
    public static function acf_allowed($subfield, $type): bool
    {
        return self::is_paid() || ($subfield === null && !in_array($type, self::ACF_PAID_TYPES, true));
    }

    /**
     * The Paid features a piece of tag content uses, by name, for the admin's badge, notice and preview note. A pure reading
     * of the text (nothing is called or rendered); a field condition is told from a built-in or a site's own one through the
     * placeholder registry.
     *
     * @param mixed $content
     * @return string[] e.g. array('{wc:}', '[dt_loop]', 'field conditions'); empty when the content uses none.
     */
    public static function paid_features_in($content): array
    {
        $content = (string) $content;
        if ($content === '') {
            return array();
        }

        $found = array();
        if (preg_match_all('/\{(' . implode('|', self::GATED_PREFIXES) . '):/', $content, $m)) {
            foreach ($m[1] as $prefix) {
                $found['{' . $prefix . ':}'] = true;
            }
        }
        if (preg_match('/\{acf:[^{}|?]*\./', $content)) {
            $found['{acf:rows.field}'] = true; // a dot reaches into a repeater or a group
        }
        if (stripos($content, '[dt_loop') !== false) {
            $found['[dt_loop]'] = true;
        }
        if (stripos($content, '[dt_template') !== false) {
            $found['[dt_template]'] = true;
        }

        $conditions = array();
        if (preg_match_all('/\{if:([^{}]+)\}/i', $content, $m)) {
            $conditions = $m[1];
        }
        if (preg_match_all('/\[dt_if\s[^\]]*?condition\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $conditions[] = $hit[1] !== '' ? $hit[1] : ($hit[2] ?? '');
            }
        }
        if ($conditions && class_exists('WP_Dynamic_Tags_Placeholders')) {
            $placeholders = WP_Dynamic_Tags_Placeholders::get_instance();
            foreach ($conditions as $condition) {
                if ($placeholders->is_field_condition(html_entity_decode($condition))) {
                    $found['field conditions'] = true;
                    break;
                }
            }
        }

        return array_keys($found);
    }

    // ------------------------------------------------------------------ the tag limit

    /**
     * How many tags count toward the Free limit: every tag that is not marked as created by another plugin (dt_origin = api,
     * set by create_tag($data) and nowhere else). Tags that exist from before the mark counted too: a person cannot tell them
     * apart from their own.
     */
    public static function count_capped_tags(): int
    {
        global $wpdb;
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        if (!$db->table_exists()) {
            return 0; // nothing to count (the screens report the missing table themselves)
        }
        $table = $db->get_table_name();
        $marked = '%' . $wpdb->esc_like('"' . WP_Dynamic_Tags_Tag_Service::ORIGIN_KEY . '";s:3:"' . WP_Dynamic_Tags_Tag_Service::ORIGIN_API . '"') . '%';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own; the pattern is a placeholder
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE meta_data IS NULL OR meta_data NOT LIKE %s", $marked));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** The refusal a person gets when a Free site is full. Says what stays untouched, and where to go only if a place is known. */
    public static function limit_message(): string
    {
        $url = self::get_upgrade_url();
        /* translators: %d: the number of tags a Free site can create */
        $message = sprintf(__('The Free plan allows %d tags that you create yourself. Tags that other plugins create do not count, and every tag you already have keeps working.', 'wp-dynamic-tags'), self::FREE_TAG_LIMIT);
        if ($url !== '') {
            /* translators: %s: address of the page where a Paid key is bought */
            return $message . ' ' . sprintf(__('For unlimited tags, get a Paid key: %s', 'wp-dynamic-tags'), $url);
        }
        return $message . ' ' . __('For unlimited tags, activate a Paid key under Dynamic Tags > Settings.', 'wp-dynamic-tags');
    }

    /**
     * Filter dt_can_create_tag: refuses a tag a person creates on a Free site that already has the limit. Tags another plugin
     * creates (origin api) and every tag on a Paid site pass; a refusal from an earlier filter is left as it is.
     *
     * @param true|false|WP_Error $allowed
     * @param array               $data
     * @param string              $origin 'api' (code calling create_tag($data)) or 'user' (Add New, Duplicate, Import).
     * @return true|false|WP_Error
     */
    public static function enforce_tag_limit($allowed, $data = array(), $origin = 'user')
    {
        if ($allowed !== true || $origin === WP_Dynamic_Tags_Tag_Service::ORIGIN_API || self::is_paid()) {
            return $allowed;
        }
        if (self::count_capped_tags() < self::FREE_TAG_LIMIT) {
            return $allowed;
        }
        return new WP_Error('dt_tag_limit', self::limit_message());
    }

    /**
     * POST {server}/api/payments/activate-remote
     * Body: { license_key, site_url }
     * Expected response: { success, valid, plan, expiry }
     *
     * @return array|WP_Error
     */
    public static function remote_validate($key)
    {
        $server = self::get_license_server();
        if (empty($server)) {
            return new WP_Error('no_server', __('License server not configured.', 'wp-dynamic-tags'));
        }

        $response = wp_remote_post(
            $server . '/api/payments/activate-remote',
            array(
                'timeout' => 15,
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode(array(
                    'license_key' => $key,
                    'site_url'    => home_url(),
                )),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('bad_response', __('Invalid response from license server.', 'wp-dynamic-tags'));
        }
        return $body;
    }

    public static function activate_license($key)
    {
        $key = strtoupper(sanitize_text_field($key));

        if (empty($key)) {
            return array(
                'success' => false,
                'message' => __('License key cannot be empty.', 'wp-dynamic-tags'),
            );
        }

        // A key is only ever accepted by the license server. (The offline "PAID-..." shortcut that once accepted any key with
        // that prefix is gone: it would have made every Free site one typed word away from Paid.)
        $remote = self::remote_validate($key);

        if (is_wp_error($remote)) {
            return array(
                'success' => false,
                'message' => $remote->get_error_message(),
            );
        }
        if (empty($remote['valid'])) {
            return array(
                'success' => false,
                'message' => isset($remote['message']) ? sanitize_text_field($remote['message']) : __('Invalid license key.', 'wp-dynamic-tags'),
            );
        }

        $plan   = isset($remote['plan']) ? sanitize_text_field($remote['plan']) : '';
        $expiry = sanitize_text_field(isset($remote['expiry']) ? $remote['expiry'] : gmdate('Y-m-d', strtotime('+1 year')));

        if ($plan !== self::PLAN_PAID) {
            return array(
                'success' => false,
                'message' => __('Unknown plan returned by server.', 'wp-dynamic-tags'),
            );
        }

        update_option(self::OPTION_LICENSE_KEY, $key);
        update_option(self::OPTION_PLAN, $plan);
        update_option(self::OPTION_EXPIRY, $expiry);

        return array(
            'success' => true,
            'message' => __('License activated! Your plan is now Paid.', 'wp-dynamic-tags'),
            'plan'    => $plan,
            'expiry'  => $expiry,
        );
    }

    /**
     * Deactivate license: notify the backend, then clear local state.
     */
    public static function deactivate_license()
    {
        $key = self::get_license_key();
        if ($key && self::get_license_server()) {
            wp_remote_post(
                self::get_license_server() . '/api/payments/deactivate-remote',
                array(
                    'timeout' => 15,
                    'headers' => array('Content-Type' => 'application/json'),
                    'body'    => wp_json_encode(array(
                        'license_key' => $key,
                        'site_url'    => home_url(),
                    )),
                )
            );
        }
        delete_option(self::OPTION_LICENSE_KEY);
        delete_option(self::OPTION_EXPIRY);
        update_option(self::OPTION_PLAN, self::PLAN_FREE);
        delete_option('dt_last_heartbeat');
    }

    /**
     * Re-validate the stored key against the backend. Downgrades to Free on
     * invalid/expired/revoked. Skips silently with no key or no server.
     */
    public static function heartbeat()
    {
        $key = self::get_license_key();
        if (empty($key) || empty(self::get_license_server())) {
            return;
        }

        $result = self::remote_validate($key);

        if (is_wp_error($result)) {
            set_transient('dt_heartbeat_error', $result->get_error_message(), DAY_IN_SECONDS);
            return;
        }

        $status = isset($result['status']) ? $result['status'] : (empty($result['valid']) ? 'invalid' : 'active');

        if (empty($result['valid']) || in_array($status, array('invalid', 'expired', 'revoked'), true)) {
            delete_option(self::OPTION_LICENSE_KEY);
            delete_option(self::OPTION_EXPIRY);
            update_option(self::OPTION_PLAN, self::PLAN_FREE);
        } else {
            if (!empty($result['plan'])) {
                update_option(self::OPTION_PLAN, sanitize_text_field($result['plan']));
            }
            if (!empty($result['expiry'])) {
                update_option(self::OPTION_EXPIRY, sanitize_text_field($result['expiry']));
            }
        }

        update_option('dt_last_heartbeat', gmdate('Y-m-d H:i:s'));
        delete_transient('dt_heartbeat_error');
    }

    public static function get_plan_features()
    {
        return array(
            array('label' => __('Dynamic tags you create yourself (tags other plugins create do not count)', 'wp-dynamic-tags'), 'free' => sprintf(
                /* translators: %d: the number of tags a Free site can create */
                __('Up to %d', 'wp-dynamic-tags'),
                self::FREE_TAG_LIMIT
            ), 'paid' => __('Unlimited', 'wp-dynamic-tags')),
            array('label' => __('WooCommerce fields {wc:...}', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('ACF repeater/gallery/relationship', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('{user_meta:} / {term_meta:}', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('[dt_loop] array iteration', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('Field-based conditions ({if:meta:x=y}, [dt_if condition="wc:..."]); [dt_if] with a built-in condition is free', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('[dt_template] per-record rendering', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('{query:...} / {api:...} data sources', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('{func:...} developer escape hatch', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('Support', 'wp-dynamic-tags'), 'free' => __('Community', 'wp-dynamic-tags'), 'paid' => __('Priority email', 'wp-dynamic-tags')),
        );
    }

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HEARTBEAT)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HEARTBEAT);
        }
    }

    public static function unschedule_cron()
    {
        $ts = wp_next_scheduled(self::CRON_HEARTBEAT);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HEARTBEAT);
        }
    }

    /**
     * AJAX: activate license key from the admin License box.
     */
    public static function ajax_activate()
    {
        check_ajax_referer('dt_license_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-dynamic-tags')));
        }
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = self::activate_license($key);
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
    }

    /**
     * AJAX: deactivate the current license from the admin License box.
     */
    public static function ajax_deactivate()
    {
        check_ajax_referer('dt_license_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-dynamic-tags')));
        }
        self::deactivate_license();
        wp_send_json_success(array('message' => __('License deactivated.', 'wp-dynamic-tags')));
    }
}

// The Free limit lives in the Tag Service's one seam (filter dt_can_create_tag); priority 10, so a site's own filter can run before or after.
add_filter('dt_can_create_tag', array('DT_License_Manager', 'enforce_tag_limit'), 10, 3);

add_action(DT_License_Manager::CRON_HEARTBEAT, array('DT_License_Manager', 'heartbeat'));
add_action('wp_ajax_dt_activate_license', array('DT_License_Manager', 'ajax_activate'));
add_action('wp_ajax_dt_deactivate_license', array('DT_License_Manager', 'ajax_deactivate'));

// Fallback for dormant WP-Cron: re-check the license at most once per 12h on admin load.
add_action('admin_init', function () {
    if (get_transient('dt_heartbeat_lock')) {
        return;
    }
    set_transient('dt_heartbeat_lock', 1, 12 * HOUR_IN_SECONDS);
    DT_License_Manager::heartbeat();
});
