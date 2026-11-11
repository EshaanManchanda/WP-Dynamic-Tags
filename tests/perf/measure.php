<?php
/**
 * Measures what one front-end style request costs: boot (registration of every tag) and rendering of N tags.
 *
 *   WP_DB_NAME=local_perf php measure.php <render_count> <out.json>
 *
 * READ-ONLY in intent, but rendering can write (usage tracking) on the current code; that is exactly what is
 * measured. It therefore refuses to run against anything but the disposable clone `local_perf`.
 * Run it in a fresh PHP process per sample (see run.ps1); the numbers of one process are not a benchmark.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
define('SAVEQUERIES', true);
$t0 = microtime(true);
require dirname(__DIR__) . '/golden-master/wp-boot.php';
$boot_ms = (microtime(true) - $t0) * 1000;

if (DB_NAME !== 'local_perf') {
    fwrite(STDERR, "Refusing to run: DB_NAME is '" . DB_NAME . "', expected 'local_perf'.\n");
    exit(2);
}

global $wpdb, $shortcode_tags;

/** Classifies the queries recorded so far. */
$classify = static function (array $queries): array {
    // The clone runs every plugin of the site, so the totals include the sibling plugins' queries (the Certificate
    // Generator alone issues 22 SHOW TABLES per boot). The ours_* counters keep only queries whose call stack passes
    // through this plugin (class names start with WP_Dynamic_Tags): that is what gate 4 is about.
    $out = ['total' => 0, 'select' => 0, 'insert' => 0, 'update' => 0, 'delete' => 0, 'show' => 0, 'other' => 0,
            'writes' => 0, 'transient_writes' => 0, 'schema_checks' => 0, 'time_ms' => 0.0,
            'ours_total' => 0, 'ours_writes' => 0, 'ours_transient_writes' => 0, 'ours_schema_checks' => 0];
    foreach ($queries as $q) {
        $sql = ltrim($q[0]);
        $ours = stripos((string) ($q[2] ?? ''), 'WP_Dynamic_Tags') !== false;
        $out['total']++;
        $out['ours_total'] += $ours ? 1 : 0;
        $out['time_ms'] += $q[1] * 1000;
        $verb = strtolower(strtok($sql, " \t\n("));
        $key = in_array($verb, ['select', 'insert', 'update', 'delete', 'show'], true) ? $verb : 'other';
        $out[$key]++;
        if (in_array($verb, ['insert', 'update', 'delete', 'replace'], true)) {
            $out['writes']++;
            $out['ours_writes'] += $ours ? 1 : 0;
            if (str_contains($sql, '_transient_') || str_contains($sql, '_site_transient_')) {
                $out['transient_writes']++;
                $out['ours_transient_writes'] += $ours ? 1 : 0;
            }
        }
        if ($verb === 'show' || str_contains(strtolower($sql), 'information_schema') || str_starts_with(strtolower($sql), 'create table')) {
            $out['schema_checks']++;
            $out['ours_schema_checks'] += $ours ? 1 : 0;
        }
    }
    $out['time_ms'] = round($out['time_ms'], 2);
    return $out;
};

$boot_queries = $classify($wpdb->queries);
$boot_peak_mb = round(memory_get_peak_usage(true) / 1048576, 2);
$perf_shortcodes = count(array_filter(array_keys($shortcode_tags), static fn($k) => str_starts_with($k, 'perf_')));

// ---- render phase: N distinct tags + N repeats of the first tag (cache behaviour)
$n = max(1, (int) ($argv[1] ?? 20));
$before = count($wpdb->queries);
$mem0 = memory_get_usage();
$r0 = microtime(true);
$outputs = [];
for ($i = 1; $i <= $n; $i++) {
    $outputs[] = do_shortcode(sprintf('[perf_%06d]', $i * 7));
}
for ($i = 0; $i < $n; $i++) {
    $outputs[] = do_shortcode('[perf_000007]');
}
$render_ms = (microtime(true) - $r0) * 1000;
$render_queries = $classify(array_slice($wpdb->queries, $before));
$non_empty = count(array_filter($outputs, static fn($o) => $o !== '' && !str_starts_with($o, '[perf_')));

$result = [
    'db'                    => DB_NAME,
    'php'                   => PHP_VERSION,
    'tag_rows'              => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dynamic_tags WHERE shortcode LIKE 'perf\_%'"),
    'boot'                  => ['ms' => round($boot_ms, 1), 'peak_mb' => $boot_peak_mb, 'queries' => $boot_queries, 'perf_shortcodes_registered' => $perf_shortcodes],
    'render'                => ['calls' => $n * 2, 'ms' => round($render_ms, 2), 'mem_growth_kb' => round((memory_get_usage() - $mem0) / 1024, 1), 'queries' => $render_queries, 'rendered_non_empty' => $non_empty],
];

$out = $argv[2] ?? null;
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($out) {
    file_put_contents($out, $json);
} else {
    echo $json, "\n";
}
