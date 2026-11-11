<?php
/**
 * Reproduces L8 (no recursion guard): table tags that reference each other, directly or in a cycle, are rendered
 * until PHP runs out of memory. Run it in a child process with a small memory limit and a timeout:
 *
 *   $env:WP_DB_NAME='local_mgtest'; php -d memory_limit=128M recursion-probe.php <self|pair|chain|deep>
 *
 * Writes rows into the database it runs against, so like every script in tests/live it refuses to touch anything but
 * the disposable clone `local_mgtest`. The rows it creates (shortcode prefix `rec_`) are deleted again at shutdown.
 *
 * Exit codes of the process tell the story: 0 = rendered and returned (guard works), 255 = PHP fatal (memory exhausted).
 */
require dirname(__DIR__) . '/golden-master/wp-boot.php';

if (DB_NAME !== 'local_mgtest') {
    fwrite(STDERR, "Refusing to run: DB_NAME is '" . DB_NAME . "', expected the disposable clone 'local_mgtest'.\n");
    exit(2);
}

global $wpdb;
$table = $wpdb->prefix . 'dynamic_tags';
register_shutdown_function(static function () use ($wpdb, $table): void {
    $wpdb->query("DELETE FROM {$table} WHERE shortcode LIKE 'rec\\_%'");
});

$scenario = $argv[1] ?? 'self';
$rows = match ($scenario) {
    'self'  => ['rec_a' => 'A[rec_a]'],
    'pair'  => ['rec_a' => 'A[rec_b]', 'rec_b' => 'B[rec_a]'],
    'chain' => ['rec_a' => 'A[rec_b]', 'rec_b' => 'B[rec_c]', 'rec_c' => 'C[rec_a]'],
    // 25 levels deep, no cycle: longer than any sane guard depth but finite
    'deep'  => (static function (): array {
        $r = [];
        for ($i = 1; $i <= 25; $i++) {
            $r['rec_' . $i] = $i < 25 ? 'L' . $i . '[rec_' . ($i + 1) . ']' : 'END';
        }
        return $r;
    })(),
    default => exit(2),
};

$wpdb->query("DELETE FROM {$table} WHERE shortcode LIKE 'rec\\_%'");
$now = current_time('mysql');
foreach ($rows as $code => $content) {
    $wpdb->insert($table, [
        'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'probe', 'category_id' => 0,
        'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
    ]);
}
WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
delete_transient('dt_table_registration_lock');
delete_transient('dt_registration_lock');
WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();

$first = array_key_first($rows);
$out = do_shortcode('[' . $first . ']');
echo json_encode(['scenario' => $scenario, 'length' => strlen($out), 'head' => substr($out, 0, 80)]), "\n";
