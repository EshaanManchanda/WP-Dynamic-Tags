<?php
/**
 * Scale measurements (release gate 4 / Phase 9c): what the admin data paths cost with N tags.
 *
 *   WP_DB_NAME=local_perf php scale.php <out.json>
 *
 * Seed the clone first (seed.php N). Measured: the list page's queries (counts, pages, search, sort), bulk actions,
 * export in every format, import (with the default row limit and with it lifted) and its clean-up. It WRITES to the
 * disposable clone (bulk actions, one delete, an import that is removed again), so it refuses any other database;
 * re-seed before the next run. One fresh process: compare runs on the same machine, the absolute numbers are not a benchmark.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
// No SAVEQUERIES here: it keeps a stack trace per query, which at hundreds of thousands of import queries would measure itself.
require dirname(__DIR__) . '/golden-master/wp-boot.php';

if (DB_NAME !== 'local_perf') {
    fwrite(STDERR, "Refusing to run: DB_NAME is '" . DB_NAME . "', expected 'local_perf'.\n");
    exit(2);
}

global $wpdb;
$table = $wpdb->prefix . 'dynamic_tags';
$db = WP_Dynamic_Tags_Database_Manager::get_instance();
$tm = WP_Dynamic_Tags_Table_Manager::get_instance();
$ie = WP_Dynamic_Tags_Table_Import_Export::get_instance();

$results = array();

/** One line for an import answer: its error code, or what the report says was created, skipped and refused. */
$outcome = static function ($r): string {
    if (is_wp_error($r)) {
        return 'error ' . $r->get_error_code();
    }
    $s = $r['stats'] ?? $r;
    return sprintf('created %s, updated %s, skipped %s, errors %s (of %s)', $s['created'] ?? '?', $s['updated'] ?? '?', $s['skipped'] ?? '?', $s['errors'] ?? '?', $s['total_records'] ?? ($r['total_records'] ?? '?'));
};

/** Runs $fn, records wall time, peak memory above the starting point and the number of queries it issued. */
$measure = static function (string $name, callable $fn) use (&$results, $wpdb): mixed {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $mem0 = memory_get_usage();
    $q0 = $wpdb->num_queries;
    $t0 = microtime(true);
    $value = $fn();
    $ms = (microtime(true) - $t0) * 1000;
    $results[$name] = array(
        'ms'      => round($ms, 1),
        'peak_mb' => round((memory_get_peak_usage() - $mem0) / 1048576, 2),
        'queries' => $wpdb->num_queries - $q0,
    );
    return $value;
};

$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$per_page = 20;
$last_offset = max(0, (int) (floor(($total - 1) / $per_page) * $per_page));
$mid_offset = (int) (floor($total / 2 / $per_page) * $per_page);
$list = array('status' => '', 'orderby' => 'modified_date', 'order' => 'DESC', 'limit' => $per_page);

// ---- the All Tags page: three view counts, the total, then one page of rows
$measure('list_views_counts', static function () use ($db) {
    foreach (array('', 'active', 'inactive') as $status) {
        $db->get_tags_count(array('status' => $status));
    }
});
foreach (array('first' => 0, 'middle' => $mid_offset, 'last' => $last_offset) as $label => $offset) {
    $measure("list_page_$label", static function () use ($db, $list, $offset) {
        $db->get_tags_count(array('status' => ''));
        return count($db->get_tags($list + array('offset' => $offset)));
    });
}
$measure('list_sort_name_asc_middle', static function () use ($db, $list, $mid_offset) {
    return count($db->get_tags(array('orderby' => 'tag_name', 'order' => 'ASC', 'offset' => $mid_offset) + $list));
});
$measure('list_sort_usage_desc_first', static function () use ($db, $list) {
    return count($db->get_tags(array('orderby' => 'usage_count', 'order' => 'DESC', 'offset' => 0) + $list));
});

// ---- search: one hit, many hits, no hit (the search looks in four columns with LIKE %term%)
foreach (array('search_one_hit' => 'perf_000123', 'search_many_hits' => 'perf', 'search_no_hit' => 'zzz_no_such_tag') as $name => $term) {
    $measure($name, static function () use ($db, $list, $term) {
        $args = array('status' => '', 'search' => $term);
        $count = $db->get_tags_count($args);
        $rows = count($db->get_tags($args + $list + array('offset' => 0)));
        return array($count, $rows);
    });
}

// ---- bulk actions (a page of the list is at most 20-100 rows; 1,000 is the stress case)
$ids = array_map('intval', $wpdb->get_col("SELECT id FROM {$table} WHERE shortcode LIKE 'perf\\_%' ORDER BY id LIMIT 1000"));
foreach (array(100, 1000) as $n) {
    $slice = array_slice($ids, 0, $n);
    $measure("bulk_deactivate_$n", static fn () => $tm->bulk_action('deactivate', $slice));
    $measure("bulk_activate_$n", static fn () => $tm->bulk_action('activate', $slice));
}
$measure('bulk_delete_100', static fn () => $tm->bulk_action('delete', array_slice($ids, 0, 100)));

// ---- export, every format, the whole table
$json_text = null;
foreach (array('json', 'csv', 'xml', 'sql') as $format) {
    $out = $measure("export_$format", static function () use ($ie, $format) {
        $r = $ie->export_data($format);
        return is_wp_error($r) ? $r : array('bytes' => strlen((string) $r['data']), 'records' => $r['stats']['total_records'], 'data' => $r['data']);
    });
    $results["export_$format"]['bytes'] = is_wp_error($out) ? $out->get_error_code() : $out['bytes'];
    if ($format === 'json' && !is_wp_error($out)) {
        $json_text = $out['data'];
    }
    unset($out);
}

// ---- import: the exported rows under new names, with the default row limit and with it lifted
$payload = null;
if ($json_text !== null) {
    $decoded = json_decode($json_text, true);
    foreach ($decoded['tags'] as $i => &$row) {
        $name = sprintf('imp_%06d', $i + 1);
        $row['tag_name'] = $name;
        $row['shortcode'] = $name;
    }
    unset($row);
    $payload = wp_json_encode($decoded);
    unset($decoded, $json_text);
}
$import_rows = $payload === null ? 0 : count(json_decode($payload, true)['tags']);
if ($payload !== null) {
    $r = $measure('import_default_limit', static fn () => $ie->import_data($payload, 'json', array('skip_duplicates' => true)));
    $results['import_default_limit']['outcome'] = $outcome($r);
    $wpdb->query("DELETE FROM {$table} WHERE shortcode LIKE 'imp\\_%'");
    unset($r);

    add_filter('dt_import_max_rows', static fn () => PHP_INT_MAX);
    $r = $measure('import_dry_run_lifted_limit', static fn () => $ie->import_data($payload, 'json', array('validate_only' => true)));
    $results['import_dry_run_lifted_limit']['outcome'] = is_wp_error($r) ? 'error ' . $r->get_error_code() : 'validated ' . ($r['total_records'] ?? '?') . ' rows';
    unset($r);
    $r = $measure('import_lifted_limit', static fn () => $ie->import_data($payload, 'json', array('skip_duplicates' => true)));
    $results['import_lifted_limit']['outcome'] = $outcome($r);
    unset($r);
    $results['import_lifted_limit']['rows_in_table_after'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE shortcode LIKE 'imp\\_%'");
    $wpdb->query("DELETE FROM {$table} WHERE shortcode LIKE 'imp\\_%'");
}

$doc = array(
    'php' => PHP_VERSION, 'db_server' => $wpdb->db_server_info(), 'tag_rows' => $total, 'import_file_rows' => $import_rows,
    'after_run_rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
    'final_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'results' => $results,
);
$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!empty($argv[1])) {
    file_put_contents($argv[1], $json);
} else {
    echo $json, "\n";
}
