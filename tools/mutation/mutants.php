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
 *   php tools/mutation/mutants.php plan       # only the mutants whose name contains "plan"
 *
 * THE PLUGIN FOLDER IS NEVER MODIFIED. The site serves that folder live, so a mutant written into it is a defect that can reach
 * a real page (and an infinite-loop mutant stalls the site). The runner copies the plugin (without .git, build, tools and
 * node_modules) to a temporary directory, breaks the copy, and deletes the copy when it ends, also when interrupted.
 */
$live = dirname(__DIR__, 2);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dt-mutation-' . getmypid();
$root = $work . DIRECTORY_SEPARATOR . 'plugin';

/** Recursive copy that skips the folders a unit run does not need. */
$copy = static function (string $from, string $to) use (&$copy): void {
    static $skip = ['.git', 'build', 'node_modules', 'tools', 'exported', '.phpunit.cache'];
    if (!is_dir($to) && !mkdir($to, 0777, true)) {
        fwrite(STDERR, "cannot create $to\n");
        exit(2);
    }
    foreach (scandir($from) as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $skip, true)) {
            continue;
        }
        is_dir("$from/$item") ? $copy("$from/$item", "$to/$item") : copy("$from/$item", "$to/$item");
    }
};
$remove = static function (string $dir) use (&$remove): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item !== '.' && $item !== '..') {
            is_dir("$dir/$item") ? $remove("$dir/$item") : @unlink("$dir/$item");
        }
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($work, $remove): void {
    $remove($work);
});
$copy($live, $root);
$only = array_slice($argv, 1);
echo "mutating a copy: $root\n";
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

    // ---- the Free and Paid plans, includes/class-tag-service.php normalize_meta()  (tests/Unit/PlanHelpersTest.php)
    ['plan: a claimed mark is kept', 'includes/class-tag-service.php', 'unset($meta[self::ORIGIN_KEY]);', '', 'PlanHelpersTest'],
    ['plan: a plugin tag gets no mark', 'includes/class-tag-service.php', '$meta[self::ORIGIN_KEY] = self::ORIGIN_API;', '', 'PlanHelpersTest'],
    ['plan: the mark is not moved to the end', 'includes/class-tag-service.php', "unset(\$meta[self::ORIGIN_KEY]);\n            \$meta[self::ORIGIN_KEY] = self::ORIGIN_API;", "\$meta[self::ORIGIN_KEY] = self::ORIGIN_API;", 'PlanHelpersTest'],
    ['plan: empty meta of a person gets the mark', 'includes/class-tag-service.php', "return \$api ? array(self::ORIGIN_KEY => self::ORIGIN_API) : '';", 'return array(self::ORIGIN_KEY => self::ORIGIN_API);', 'PlanHelpersTest'],
    ['plan: empty meta of a plugin gets no mark', 'includes/class-tag-service.php', "return \$api ? array(self::ORIGIN_KEY => self::ORIGIN_API) : '';", "return '';", 'PlanHelpersTest'],
    ['plan: a person\'s meta is not cleaned at all', 'includes/class-tag-service.php', "if (!\$api) {\n                return self::without_origin_text(\$meta);", "if (false) {\n                return self::without_origin_text(\$meta);", 'PlanHelpersTest'],
    ['plan: a mark nested deeper is kept', 'includes/class-tag-service.php', '$value = self::without_origin_text($value);', '', 'PlanHelpersTest'],
    ['plan: a mark written out in a value is kept', 'includes/class-tag-service.php', "\$value = str_ireplace(self::ORIGIN_KEY, 'dt-origin', \$value);", '', 'PlanHelpersTest'],
    ['plan: a key that contains the mark is kept', 'includes/class-tag-service.php', "if (is_string(\$key) && stripos(\$key, self::ORIGIN_KEY) !== false) {\n                continue;", "if (false) {\n                continue;", 'PlanHelpersTest'],
    ['plan: the mark is matched case-sensitively in keys', 'includes/class-tag-service.php', 'stripos($key, self::ORIGIN_KEY)', 'strpos($key, self::ORIGIN_KEY)', 'PlanHelpersTest'],
    ['plan: the mark is matched case-sensitively in values', 'includes/class-tag-service.php', 'is_string($value) && stripos($value, self::ORIGIN_KEY) !== false', 'is_string($value) && strpos($value, self::ORIGIN_KEY) !== false', 'PlanHelpersTest'],
    ['plan: the mark is replaced case-sensitively', 'includes/class-tag-service.php', 'str_ireplace(self::ORIGIN_KEY', 'str_replace(self::ORIGIN_KEY', 'PlanHelpersTest'],
    ['plan: text that fakes the mark is kept', 'includes/class-tag-service.php', 'if (is_string($raw) && stripos($raw, self::ORIGIN_KEY) !== false) {', 'if (false) {', 'PlanHelpersTest'],
    ['plan: all text is dropped', 'includes/class-tag-service.php', 'if (is_string($raw) && stripos($raw, self::ORIGIN_KEY) !== false) {', 'if (is_string($raw)) {', 'PlanHelpersTest'],
    ['plan: text that mentions the mark in another case is kept', 'includes/class-tag-service.php', 'is_string($raw) && stripos($raw, self::ORIGIN_KEY) !== false', 'is_string($raw) && strpos($raw, self::ORIGIN_KEY) !== false', 'PlanHelpersTest'],

    // ---- includes/class-license-manager.php  (tests/Unit/PlanHelpersTest.php)
    ['plan: func is no longer a Paid prefix', 'includes/class-license-manager.php', "const GATED_PREFIXES = array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func');", "const GATED_PREFIXES = array('wc', 'user_meta', 'term_meta', 'query', 'api');", 'PlanHelpersTest'],
    ['plan: meta becomes a Paid prefix', 'includes/class-license-manager.php', "const GATED_PREFIXES = array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func');", "const GATED_PREFIXES = array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func', 'meta');", 'PlanHelpersTest'],
    ['plan: a short key is shown in full', 'includes/class-license-manager.php', 'if (strlen($key) <= 12) {', 'if (false) {', 'PlanHelpersTest'],
    ['plan: a 12-character key counts as long', 'includes/class-license-manager.php', 'if (strlen($key) <= 12) {', 'if (strlen($key) < 12) {', 'PlanHelpersTest'],
    ['plan: the mask shows one character too many at the end', 'includes/class-license-manager.php', 'substr($key, -4);', 'substr($key, -5);', 'PlanHelpersTest'],
    ['plan: the mask shows one character too many at the start', 'includes/class-license-manager.php', 'substr($key, 0, 5) . str_repeat', 'substr($key, 0, 6) . str_repeat', 'PlanHelpersTest'],
    ['plan: the mask changes the key length', 'includes/class-license-manager.php', 'strlen($key) - 9', 'strlen($key) - 8', 'PlanHelpersTest'],
    ['plan: [dt_template] is case sensitive', 'includes/class-license-manager.php', "stripos(\$content, '[dt_template')", "strpos(\$content, '[dt_template')", 'PlanHelpersTest'],
    ['plan: [dt_loop] is never found', 'includes/class-license-manager.php', "if (stripos(\$content, '[dt_loop') !== false) {", 'if (false) {', 'PlanHelpersTest'],
    ['plan: ACF sub-fields are never found', 'includes/class-license-manager.php', "if (preg_match('/\\{acf:[^{}|?]*\\./', \$content)) {", 'if (false) {', 'PlanHelpersTest'],
    ['plan: single-quoted [dt_if] conditions are missed', 'includes/class-license-manager.php', '|\\\'([^\\\']*)\\\')/i\'', ')/i\'', 'PlanHelpersTest'],
    ['plan: {if:} conditions are never read', 'includes/class-license-manager.php', "preg_match_all('/\\{if:([^{}]+)\\}/i', \$content, \$m)", 'false', 'PlanHelpersTest'],
    ['plan: every condition counts as a field condition', 'includes/class-license-manager.php', "if (\$placeholders->is_field_condition(html_entity_decode(\$condition))) {", 'if (true) {', 'PlanHelpersTest'],
    ['plan: an encoded condition is not decoded', 'includes/class-license-manager.php', 'is_field_condition(html_entity_decode($condition))', 'is_field_condition($condition)', 'PlanHelpersTest'],

    // ---- includes/class-dynamic-placeholders.php the gate  (tests/Unit/PlanHelpersTest.php)
    ['plan: the gate of resolve_token is open', 'includes/class-dynamic-placeholders.php', "if (!self::plan_allows(\$key)) {\n                return '';", "if (false) {\n                return '';", 'PlanHelpersTest'],
    ['plan: every prefix is allowed', 'includes/class-dynamic-placeholders.php', "return !class_exists('DT_License_Manager') || !DT_License_Manager::is_gated_prefix(\$key) || DT_License_Manager::is_paid();", 'return true;', 'PlanHelpersTest'],
    ['plan: no prefix is allowed', 'includes/class-dynamic-placeholders.php', "return !class_exists('DT_License_Manager') || !DT_License_Manager::is_gated_prefix(\$key) || DT_License_Manager::is_paid();", 'return false;', 'PlanHelpersTest'],
    ['plan: Free is allowed through', 'includes/class-dynamic-placeholders.php', "|| !DT_License_Manager::is_gated_prefix(\$key) || DT_License_Manager::is_paid();", "|| !DT_License_Manager::is_gated_prefix(\$key) || !DT_License_Manager::is_paid();", 'PlanHelpersTest'],
    ['plan: the fallback of a blocked token is lost', 'includes/class-dynamic-placeholders.php', "return \$parsed['fallback'] ?? '';", "return '';", 'PlanHelpersTest'],

    // ---- includes/class-dynamic-placeholders.php is_builtin_condition()  (tests/Unit/PlanHelpersTest.php)
    ['plan: user_role is not a built-in condition', 'includes/class-dynamic-placeholders.php', "|| strpos(\$condition, 'user_role:') === 0", '', 'PlanHelpersTest'],
    ['plan: a negation is not stripped', 'includes/class-dynamic-placeholders.php', "\$condition = ltrim(\$condition, '!');", '', 'PlanHelpersTest'],
    ['plan: time_between may start anywhere', 'includes/class-dynamic-placeholders.php', "'/^time_between\\(\\s*", "'/time_between\\(\\s*", 'PlanHelpersTest'],
    ['plan: time_between may end with anything', 'includes/class-dynamic-placeholders.php', "\\d{1,2}:\\d{2}\\s*\\)\$/', \$condition) === 1;", "\\d{1,2}:\\d{2}\\s*\\)/', \$condition) === 1;", 'PlanHelpersTest'],
    ['plan: is_page is not built in', 'includes/class-dynamic-placeholders.php', "'is_front_page', 'is_home', 'is_single', 'is_page');", "'is_front_page', 'is_home', 'is_single');", 'PlanHelpersTest'],

    // ---- includes/class-dynamic-formatters.php, the array formatters  (tests/Unit/ArrayFormattersTest.php)
    ['formatter: join default separator', 'includes/class-dynamic-formatters.php', "\$sep = \$arg !== null ? \$arg : ', ';", "\$sep = \$arg !== null ? \$arg : '; ';", 'ArrayFormattersTest'],
    ['formatter: join ignores its separator', 'includes/class-dynamic-formatters.php', "\$sep = \$arg !== null ? \$arg : ', ';", "\$sep = ', ';", 'ArrayFormattersTest'],
    ['formatter: nested rows joined with a comma', 'includes/class-dynamic-formatters.php', "implode(' ', array_map(array(__CLASS__, 'scalarize')", "implode(',', array_map(array(__CLASS__, 'scalarize')", 'ArrayFormattersTest'],
    ['formatter: count of a scalar inverted', 'includes/class-dynamic-formatters.php', "(self::scalarize(\$value) !== '' ? 1 : 0)", "(self::scalarize(\$value) !== '' ? 0 : 1)", 'ArrayFormattersTest'],
    ['formatter: first returns the last', 'includes/class-dynamic-formatters.php', '$first = reset($value);', '$first = end($value);', 'ArrayFormattersTest'],
    ['formatter: last returns the first', 'includes/class-dynamic-formatters.php', '$last = end($value);', '$last = reset($value);', 'ArrayFormattersTest'],
    ['formatter: first of nothing is not empty', 'includes/class-dynamic-formatters.php', "\$first === false ? '' : self::scalarize(\$first)", "\$first === false ? 'x' : self::scalarize(\$first)", 'ArrayFormattersTest'],
    ['formatter: last is not array aware', 'includes/class-dynamic-formatters.php', "array('join', 'count', 'first', 'last')", "array('join', 'count', 'first')", 'ArrayFormattersTest'],
];

$killed = 0;
$escaped = [];
$errors = [];
foreach ($mutants as [$name, $file, $needle, $replacement, $filter]) {
    if ($only && !array_filter($only, static fn($o) => strpos($name, $o) !== false)) {
        continue;
    }
    $path = $root . '/' . $file;
    $original = file_get_contents($path);
    if (substr_count($original, $needle) < 1) {
        $errors[] = "$name: needle not found in $file";
        continue;
    }
    $restore = static function () use ($path, $original): void {
        file_put_contents($path, $original);
    };
    // (no shutdown restore: the file is restored below, right after the run, and the whole copy is deleted at the end)
    file_put_contents($path, preg_replace('/' . preg_quote($needle, '/') . '/', str_replace('\\', '\\\\', $replacement), $original, 1));
    $ini = php_ini_loaded_file() ? ' -c ' . escapeshellarg(php_ini_loaded_file()) : ''; // the child needs the same extensions
    // A mutant that is not valid PHP "dies" for the wrong reason and would count as killed: refuse it and say so.
    exec(escapeshellarg(PHP_BINARY) . $ini . ' -l ' . escapeshellarg($path) . ' 2>&1', $lint, $lint_code);
    if ($lint_code !== 0) {
        $restore();
        $errors[] = "$name: the mutant is not valid PHP (" . trim((string) end($lint)) . ')';
        $lint = [];
        continue;
    }
    $lint = [];
    $cmd = escapeshellarg(PHP_BINARY) . $ini . ' ' . escapeshellarg($phpunit) . ' --filter ' . escapeshellarg($filter) . ' --no-progress 2>&1';
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
