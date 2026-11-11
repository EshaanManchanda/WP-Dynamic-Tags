<?php
/**
 * Hand-written mutation check for the pure-logic classes.
 *
 * Infection (tools/mutation) could not complete on this machine: it stops silently while processing the first source file
 * under Windows + Xdebug. This runner does the essential part of the job with a list of deliberate mutants: it breaks the
 * code in one small way, runs the unit tests that are supposed to protect it, and expects them to FAIL. A mutant that
 * survives ("escaped") means a missing test (or an equivalent mutant, marked below).
 *
 *   php tools/mutation/mutants.php            # exit code 1 if a non-equivalent mutant escapes
 *
 * Every file is restored afterwards, also when the run is interrupted.
 */
$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/phpunit/phpunit/phpunit';

/** [name, file, needle, replacement, test filter, equivalent?] */
$mutants = [
    // ---- includes/class-conditional-parser.php  (tests/Unit/ConditionalParserTest.php)
    ['parser: no-block shortcut inverted', 'includes/class-conditional-parser.php', "strpos(\$content, '{if:') === false", "strpos(\$content, '{if:') !== false", 'ConditionalParserTest'],
    ['parser: else detection inverted', 'includes/class-conditional-parser.php', "\$text === '{else}'", "\$text !== '{else}'", 'ConditionalParserTest'],
    ['parser: first-else rule inverted', 'includes/class-conditional-parser.php', "\$stack[\$top]['false'] === null) {\n                    \$stack[\$top]['false'] = array();", "\$stack[\$top]['false'] !== null) {\n                    \$stack[\$top]['false'] = array();", 'ConditionalParserTest'],
    ['parser: closing tag ignored', 'includes/class-conditional-parser.php', "\$text === '{/if}'", "\$text === '{/ifx}'", 'ConditionalParserTest'],
    ['parser: stray closing tag handling inverted', 'includes/class-conditional-parser.php', "                if (\$stack) {\n                    \$block = array_pop(\$stack);", "                if (!\$stack) {\n                    \$block = array_pop(\$stack);", 'ConditionalParserTest'],
    ['parser: depth guard off', 'includes/class-conditional-parser.php', "count(\$stack) >= self::MAX_DEPTH", "false && count(\$stack) >= self::MAX_DEPTH", 'ConditionalParserTest'],
    ['parser: depth limit becomes 0', 'includes/class-conditional-parser.php', "public const MAX_DEPTH = 32;", "public const MAX_DEPTH = 0;", 'ConditionalParserTest'],
    ['parser: condition taken with the tag', 'includes/class-conditional-parser.php', "'cond' => \$set[1][0]", "'cond' => \$set[0][0]", 'ConditionalParserTest'],
    ['parser: branch choice inverted', 'includes/class-conditional-parser.php', "\$evaluate(\$part['cond']) ? \$part['true']", "!\$evaluate(\$part['cond']) ? \$part['true']", 'ConditionalParserTest'],
    ['parser: else branch dropped', 'includes/class-conditional-parser.php', "(\$part['false'] ?? array())", "array()", 'ConditionalParserTest'],
    ['parser: unclosed block loses its else text', 'includes/class-conditional-parser.php', "array_merge(\$flat, array('{else}'), \$block['false'])", "array_merge(\$flat, \$block['false'])", 'ConditionalParserTest'],
    ['parser: unclosed block loses its opening tag', 'includes/class-conditional-parser.php', "array_merge(array(\$block['open']), \$block['true'])", "array_merge(array(''), \$block['true'])", 'ConditionalParserTest'],
    ['parser: trailing literal dropped', 'includes/class-conditional-parser.php', "if (\$cursor < strlen(\$content)) {", "if (false) {", 'ConditionalParserTest'],
    ['parser: literal before a tag dropped', 'includes/class-conditional-parser.php', "if (\$pos > \$cursor) {", "if (false) {", 'ConditionalParserTest'],
    ['parser: strings are rendered as blocks', 'includes/class-conditional-parser.php', "if (is_string(\$part)) {", "if (!is_string(\$part)) {", 'ConditionalParserTest'],
    ['parser: literal appended to the wrong branch', 'includes/class-conditional-parser.php', "} elseif (\$stack[\$top]['false'] === null) {\n            \$stack[\$top]['true'][] = \$part;", "} elseif (\$stack[\$top]['false'] !== null) {\n            \$stack[\$top]['true'][] = \$part;", 'ConditionalParserTest'],

    // ---- includes/class-table-shortcode-processor.php time window  (tests/Unit/TimeWindowTest.php)
    ['window: start no longer inclusive', 'includes/class-table-shortcode-processor.php', "return \$now >= \$start && \$now <= \$end;", "return \$now > \$start && \$now <= \$end;", 'TimeWindowTest'],
    ['window: end no longer inclusive', 'includes/class-table-shortcode-processor.php', "return \$now >= \$start && \$now <= \$end;", "return \$now >= \$start && \$now < \$end;", 'TimeWindowTest'],
    ['window: midnight branch uses AND', 'includes/class-table-shortcode-processor.php', "return \$now >= \$start || \$now <= \$end;", "return \$now >= \$start && \$now <= \$end;", 'TimeWindowTest'],
    ['window: midnight branch start exclusive', 'includes/class-table-shortcode-processor.php', "return \$now >= \$start || \$now <= \$end;", "return \$now > \$start || \$now <= \$end;", 'TimeWindowTest'],
    ['window: same-minute window treated as midnight window', 'includes/class-table-shortcode-processor.php', "if (\$start <= \$end) {", "if (\$start < \$end) {", 'TimeWindowTest'],

    // ---- includes/class-database-manager.php decode_meta  (tests/Unit/MetaDecodeTest.php)
    ['decode: objects allowed', 'includes/class-database-manager.php', "array('allowed_classes' => false)", "array('allowed_classes' => true)", 'MetaDecodeTest'],
    ['decode: JSON never decoded', 'includes/class-database-manager.php', "(\$trimmed[0] === '{' || \$trimmed[0] === '[')", "(\$trimmed[0] === 'x')", 'MetaDecodeTest'],
    ['decode: serialized scalar returned as is', 'includes/class-database-manager.php', "return is_array(\$value) ? \$value : array();", "return \$value;", 'MetaDecodeTest'],
    ['decode: corrupt data returned as is', 'includes/class-database-manager.php', "if (\$value === false && \$trimmed !== 'b:0;') {\n                return array();\n            }", "if (false) {\n                return array();\n            }", 'MetaDecodeTest'],
    ['decode: plain text swallowed', 'includes/class-database-manager.php', "        return \$raw;\n    }\n\n    /**\n     * Get dynamic tag by name", "        return array();\n    }\n\n    /**\n     * Get dynamic tag by name", 'MetaDecodeTest'],
];

$killed = 0;
$escaped = [];
$errors = [];
foreach ($mutants as [$name, $file, $needle, $replacement, $filter]) {
    $path = $root . '/' . $file;
    $original = file_get_contents($path);
    if (substr_count($original, $needle) < 1) {
        $errors[] = "$name: needle not found in $file";
        continue;
    }
    $restore = static function () use ($path, $original): void {
        file_put_contents($path, $original);
    };
    register_shutdown_function($restore);
    file_put_contents($path, preg_replace('/' . preg_quote($needle, '/') . '/', str_replace('\\', '\\\\', $replacement), $original, 1));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' --filter ' . escapeshellarg($filter) . ' --no-progress 2>&1';
    exec($cmd, $out, $code);
    $restore();
    $out = [];
    if ($code !== 0) {
        $killed++;
        echo "  killed   $name\n";
    } else {
        $escaped[] = $name;
        echo "  ESCAPED  $name\n";
    }
}

$total = $killed + count($escaped);
printf("\n%d mutants: %d killed, %d escaped (%.0f%% mutation score)%s\n", $total, $killed, count($escaped), $total ? 100 * $killed / $total : 0, $errors ? ', ' . count($errors) . ' not applied' : '');
foreach ($errors as $e) {
    echo "  ERROR    $e\n";
}
exit($escaped || $errors ? 1 : 0);
