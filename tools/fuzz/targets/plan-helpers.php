<?php
/**
 * php-fuzzer target for the pure helpers of the Free and Paid plans. Whatever the input, in whatever shape it reaches them
 * (a string, a value, a key, nested, JSON, serialized):
 *
 *  - normalize_meta(): a person's meta data, as stored, never mentions the origin mark (the Free limit finds a plugin's tag by
 *    searching the stored text for it, so a mention anywhere would let a person's tag pass as a plugin's); a plugin's tag carries
 *    the mark last, set by the plugin; no result is ever an object
 *  - paid_features_in(), is_builtin_condition(), mask_key(): never throw, and return what they promise
 *
 *   php tools/fuzz/vendor/bin/php-fuzzer fuzz tools/fuzz/targets/plan-helpers.php <corpus dir> --max-runs 300000
 *
 * A failed check throws an Error, not an Exception: php-fuzzer reports PHP errors, warnings and thrown Errors as crashes and
 * silently ignores a thrown Exception (shown with a planted one, see tools/README.md), so an Exception here would never fail.
 *
 * @var PhpFuzzer\Config $config
 */
define('ABSPATH', __DIR__ . '/');
$includes = dirname(__DIR__, 3) . '/includes/';
// The hook functions the classes call when they load (no-ops, as in tests/bootstrap.php).
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}
require $includes . 'class-database-manager.php';
require $includes . 'class-dynamic-formatters.php';
require $includes . 'class-dynamic-placeholders.php';
require $includes . 'class-tag-service.php';
require $includes . 'class-license-manager.php';
new WP_Dynamic_Tags_Placeholders();

$config->setTarget(function (string $input): void {
    $forms = array(
        $input,
        array('k' => $input),
        array($input => 'v'),
        array('a' => array('b' => array($input))),
        (string) json_encode(array('x' => $input, 'k' => array($input => 1))),
        serialize(array('x' => $input)),
        '{"dt_origin":"api","n":' . json_encode($input) . '}',
        'a:1:{s:' . strlen($input) . ':"' . $input . '";s:3:"api";}',
    );

    foreach ($forms as $raw) {
        $person = WP_Dynamic_Tags_Tag_Service::normalize_meta($raw, false);
        if (is_object($person)) {
            throw new Error('normalize_meta returned an object');
        }
        $stored = is_array($person) ? serialize($person) : (string) $person;
        if (stripos($stored, 'dt_origin') !== false) {
            throw new Error('a person\'s meta data still mentions the mark: ' . $stored);
        }

        $plugin = WP_Dynamic_Tags_Tag_Service::normalize_meta($raw, true);
        if (is_object($plugin)) {
            throw new Error('normalize_meta returned an object for a plugin tag');
        }
        if (is_array($plugin) && (array_key_last($plugin) !== 'dt_origin' || $plugin['dt_origin'] !== 'api')) {
            throw new Error('a plugin tag does not carry the mark last: ' . serialize($plugin));
        }
    }

    $features = DT_License_Manager::paid_features_in($input);
    if (!is_array($features) || $features !== array_values(array_unique(array_map('strval', $features)))) {
        throw new Error('paid_features_in returned something other than a list of distinct names');
    }
    if (!is_bool(WP_Dynamic_Tags_Placeholders::is_builtin_condition($input))) {
        throw new Error('is_builtin_condition did not return a boolean');
    }
    $masked = DT_License_Manager::mask_key($input);
    if (!is_string($masked) || ($input === '' && $masked !== '')) {
        throw new Error('mask_key returned a malformed result');
    }
});
$config->setMaxLen(300);
