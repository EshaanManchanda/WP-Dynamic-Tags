<?php
/**
 * php-fuzzer target for the pure token/condition parsers of the placeholder engine and for decode_meta().
 * Any input must give a result of the documented shape and never throw, warn or hang.
 *
 *   php tools/fuzz/vendor/bin/php-fuzzer fuzz tools/fuzz/targets/token-parsers.php <corpus dir> --max-runs 100000
 *
 * @var PhpFuzzer\Config $config
 */
define('ABSPATH', __DIR__ . '/');
$includes = dirname(__DIR__, 3) . '/includes/';
require $includes . 'class-dynamic-placeholders.php';
require $includes . 'class-database-manager.php';

$config->setTarget(function (string $input): void {
    $t = WP_Dynamic_Tags_Placeholders::parse_token_body($input);
    if (!is_string($t['key']) || !is_array($t['formatters']) || !array_key_exists('arg', $t) || !array_key_exists('fallback', $t)) {
        throw new Error('parse_token_body returned a malformed result');
    }

    $c = WP_Dynamic_Tags_Placeholders::parse_condition_expression($input);
    if (!in_array($c['type'], ['comparison', 'truthy'], true) || !is_string($c['key'])) {
        throw new Error('parse_condition_expression returned a malformed result');
    }
    if ($c['type'] === 'comparison') {
        WP_Dynamic_Tags_Placeholders::compare_values($input, (string) $c['op'], (string) $c['value']);
    }

    WP_Dynamic_Tags_Placeholders::trim_formatter_spec($input);
    WP_Dynamic_Tags_Placeholders::walk_path(['a' => ['b' => [1, 2, ['c' => 'd']]], 'x' => 'y'], $input);

    // decode_meta must never create an object, whatever it is fed
    $m = WP_Dynamic_Tags_Database_Manager::decode_meta($input);
    if (is_object($m) && !($m instanceof __PHP_Incomplete_Class)) {
        throw new Error('decode_meta returned an object');
    }
});
$config->setMaxLen(300);
