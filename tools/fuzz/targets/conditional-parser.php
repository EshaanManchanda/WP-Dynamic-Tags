<?php
/**
 * php-fuzzer target for WP_Dynamic_Tags_Conditional_Parser: any input must return a string, never throw, warn or hang.
 *
 *   php tools/fuzz/vendor/bin/php-fuzzer fuzz tools/fuzz/targets/conditional-parser.php <corpus dir, seeded from tools/fuzz/seeds-parser> --max-runs 100000
 *
 * @var PhpFuzzer\Config $config
 */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__, 3) . '/includes/class-conditional-parser.php';

$config->setTarget(function (string $input): void {
    $asked = 0;
    $out = WP_Dynamic_Tags_Conditional_Parser::process($input, static function (string $c) use (&$asked): bool {
        $asked++;
        return (crc32($c) & 1) === 1;
    });
    // The output can only contain text that was in the input (literal pieces and chosen branches), so it is never longer.
    if (strlen($out) > strlen($input)) {
        throw new Exception('output longer than input');
    }
    if ($asked > strlen($input)) {
        throw new Exception('more conditions evaluated than the input can contain');
    }
});
$config->setMaxLen(400);
