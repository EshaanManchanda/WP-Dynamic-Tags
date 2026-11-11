<?php
/**
 * Seeds N rows into wp_dynamic_tags for the performance baseline.
 *
 *   WP_DB_NAME=local_perf php seed.php 5000
 *
 * DESTRUCTIVE (it replaces every row whose shortcode starts with "perf_"). Refuses to run unless the database
 * is the disposable clone `local_perf`. Never point this at the live site database.
 */
require dirname(__DIR__) . '/golden-master/wp-boot.php';

if (DB_NAME !== 'local_perf') {
    fwrite(STDERR, "Refusing to run: DB_NAME is '" . DB_NAME . "', expected the disposable clone 'local_perf'.\n");
    exit(2);
}

$count = max(1, (int) ($argv[1] ?? 5000));
global $wpdb;
$table = $wpdb->prefix . 'dynamic_tags';

$wpdb->query("DELETE FROM {$table} WHERE shortcode LIKE 'perf\_%'");

$now   = current_time('mysql');
$batch = [];
$flush = static function () use (&$batch, $wpdb, $table): void {
    if (!$batch) {
        return;
    }
    $sql = "INSERT INTO {$table} (tag_name, shortcode, content, description, category_id, status, usage_count, post_id, meta_data, created_date, modified_date) VALUES " . implode(',', $batch);
    if ($wpdb->query($sql) === false) {
        fwrite(STDERR, 'insert failed: ' . $wpdb->last_error . "\n");
        exit(1);
    }
    $batch = [];
};

for ($i = 1; $i <= $count; $i++) {
    $key = sprintf('perf_%06d', $i);
    // Three content shapes so the renderer is exercised: plain, token, conditional.
    $content = match ($i % 3) {
        0 => 'Plain value ' . $i,
        1 => 'Hello {site_name} tag {tag_name} year {current_year}',
        default => '{if:user_logged_in}in{else}out{/if} ' . $i,
    };
    $batch[] = $wpdb->prepare('(%s,%s,%s,%s,%d,%s,%d,%d,%s,%s,%s)', $key, $key, $content, 'perf seed', 0, 'active', 0, 0, '', $now, $now);
    if (count($batch) >= 500) {
        $flush();
    }
}
$flush();

echo 'seeded ' . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE shortcode LIKE 'perf\_%'") . " rows\n";
