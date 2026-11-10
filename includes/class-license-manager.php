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

    /**
     * Resolves the portfolio backend URL. Defaults to a DT-specific setting,
     * but falls back to Certificate Generator's configured server so both
     * plugins share one backend account by default (see LICENSE-PLAN.md §6)
     * without Dynamic Tags depending on that plugin being installed.
     */
    public static function get_license_server()
    {
        if (defined('DT_LICENSE_SERVER') && DT_LICENSE_SERVER) {
            return rtrim((string) DT_LICENSE_SERVER, '/');
        }
        $opt = (string) get_option('dt_license_server_url', '');
        if ($opt !== '') {
            return rtrim($opt, '/');
        }
        return rtrim((string) get_option('cg_license_server_url', ''), '/');
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

        if (self::get_license_server()) {
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

        // Local-only fallback for dev/offline sites with no server configured.
        $is_lifetime = substr($key, -9) === '-LIFETIME' || substr($key, -6) === '-LOCAL';

        if (strpos($key, 'PAID-') !== 0) {
            return array(
                'success' => false,
                'message' => __('Invalid license key. Keys must start with PAID-.', 'wp-dynamic-tags'),
            );
        }

        $expiry = $is_lifetime ? '2099-12-31' : gmdate('Y-m-d', strtotime('+1 year'));

        update_option(self::OPTION_LICENSE_KEY, $key);
        update_option(self::OPTION_PLAN, self::PLAN_PAID);
        update_option(self::OPTION_EXPIRY, $expiry);

        return array(
            'success' => true,
            'message' => __('License activated! Your plan is now Paid.', 'wp-dynamic-tags'),
            'plan'    => self::PLAN_PAID,
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
            array('label' => __('Dynamic tags', 'wp-dynamic-tags'), 'free' => __('Up to 15', 'wp-dynamic-tags'), 'paid' => __('Unlimited', 'wp-dynamic-tags')),
            array('label' => __('WooCommerce fields {wc:...}', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('ACF repeater/gallery/relationship', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('{user_meta:} / {term_meta:}', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('[dt_loop] array iteration', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
            array('label' => __('Field-based conditionals & [dt_if]', 'wp-dynamic-tags'), 'free' => false, 'paid' => true),
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
