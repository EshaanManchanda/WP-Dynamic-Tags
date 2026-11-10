<?php
/**
 * Live scenarios for the posts -> table migration engine, run against the real Local site.
 *
 *   php migration-scenarios.php
 *
 * Seeds fixture posts and rows, drives WP_Dynamic_Tags_Migration_Engine through its whole lifecycle
 * (dry run, lock, resumable batches, idempotency, verification, conflict resolution, guarded cleanup,
 * backup tamper detection, restore, transactional rollback), then puts the site back exactly as it
 * was and proves it against a snapshot taken at the start. Exit code 1 if any check fails.
 *
 * Fixtures are inserted with direct SQL so none of the plugin's own save hooks can interfere.
 */
require __DIR__ . '/../golden-master/wp-boot.php';

// These scenarios delete tag posts, replace table rows and restore backups. They must never run against a
// site in use. Run them only against a disposable clone of the database (see README: tests/live).
if (!defined('DB_NAME') || DB_NAME !== 'local_mgtest') {
    fwrite(STDERR, "REFUSING TO RUN: these scenarios are destructive and only run against the disposable clone database 'local_mgtest' (set WP_DB_NAME=local_mgtest). Current database: " . (defined('DB_NAME') ? DB_NAME : '?') . "\n");
    exit(2);
}

global $wpdb;
$T      = $wpdb->prefix . 'dynamic_tags';
$engine = WP_Dynamic_Tags_Migration_Engine::get_instance();
$pass   = 0;
$fail   = 0;

function ok($cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}
function section(string $title): void
{
    echo "\n== $title\n";
}
function rows_hash(): string
{
    global $wpdb, $T;
    return md5(json_encode($wpdb->get_results("SELECT * FROM {$T} ORDER BY id", ARRAY_A)));
}
function row_by_shortcode(string $shortcode): ?array
{
    global $wpdb, $T;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$T} WHERE shortcode = %s", $shortcode), ARRAY_A);
    return is_array($row) ? $row : null;
}
function mk_post(string $title, string $content, string $status, string $modified, array $groups = [], array $meta = []): int
{
    global $wpdb;
    $wpdb->insert($wpdb->posts, [
        'post_author' => 0, 'post_date' => $modified, 'post_date_gmt' => $modified, 'post_content' => $content, 'post_title' => $title,
        'post_excerpt' => '', 'post_status' => $status, 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
        'post_name' => sanitize_title($title), 'to_ping' => '', 'pinged' => '', 'post_modified' => $modified, 'post_modified_gmt' => $modified,
        'post_content_filtered' => '', 'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => 'dynamic_tag', 'post_mime_type' => '', 'comment_count' => 0,
    ]);
    $id = (int) $wpdb->insert_id;
    foreach ($meta as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    if ($groups) {
        wp_set_object_terms($id, $groups, 'tag_groups');
    }
    return $id;
}
function mk_row(string $shortcode, string $name, string $content, string $modified, int $post_id = 0, string $status = 'active'): int
{
    global $wpdb, $T;
    $wpdb->insert($T, [
        'tag_name' => $name, 'shortcode' => $shortcode, 'content' => $content, 'description' => '', 'category_id' => 0, 'priority' => 0,
        'usage_count' => 0, 'created_date' => $modified, 'modified_date' => $modified, 'status' => $status, 'meta_data' => '', 'post_id' => $post_id,
    ]);
    return (int) $wpdb->insert_id;
}
function edit_post_sql(int $id, string $content, string $modified): void
{
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $content, 'post_modified' => $modified, 'post_modified_gmt' => $modified], ['ID' => $id]);
}
function state(): array
{
    return WP_Dynamic_Tags_Migration_Engine::get_instance()->get_state();
}
function err_code($r): string
{
    return is_wp_error($r) ? $r->get_error_code() : '(not an error)';
}

// ------------------------------------------------------------------ snapshot of the real site

$orig      = $wpdb->get_results("SELECT * FROM {$T} ORDER BY id", ARRAY_A);
$orig_hash = md5(json_encode($orig));
$orig_ids  = array_map('intval', array_column($orig, 'id'));
$orig_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'dynamic_tag'");
$orig_orphans = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
$upload    = wp_upload_dir();
$private   = trailingslashit($upload['basedir']) . WP_Dynamic_Tags_Migration_Engine::BACKUP_DIR;
$private_existed = is_dir($private);
$files_before = $private_existed ? array_map(static fn($f) => $private . '/' . $f, array_values(array_diff((array) scandir($private), ['.', '..']))) : [];
$had_state = get_option(WP_Dynamic_Tags_Migration_Engine::OPTION_STATE, null);

echo "Site snapshot: " . count($orig) . " rows, $orig_posts legacy posts, $orig_orphans orphan meta rows.\n";
if ($orig_posts !== 0 || $had_state !== null) {
    echo "REFUSING TO RUN: the site already has legacy posts or migration state.\n";
    exit(2);
}

// While seeding fixtures, keep the legacy save/term sync out of the way. The legacy DELETE hooks are
// deliberately left in place: cleanup has to suspend them itself, and scenario 7 proves it does.
$bridge = WP_Dynamic_Tags_Post_Table_Bridge::get_instance();
$plugin = WP_Dynamic_Tags_Plugin::get_instance();
foreach ([['save_post', 'sync_post_to_table', 20], ['set_object_terms', 'sync_post_terms', 10]] as [$h, $m, $p]) {
    remove_action($h, [$bridge, $m], $p);
}

$engine->reset_state();
delete_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOCK);

/**
 * Put the site back exactly as it was. Runs at the end and, if the script dies half way, from a shutdown handler.
 */
function restore_site(): void
{
    global $wpdb, $T, $orig, $g1, $engine, $plugin, $private, $files_before;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'dynamic_tag'") as $pid) {
        wp_delete_post((int) $pid, true);
    }
    $wpdb->query("DELETE FROM {$T}");
    foreach ($orig as $row) {
        $wpdb->insert($T, $row);                                                     // the original rows, exactly as they were
    }
    if (isset($g1)) {
        wp_delete_term($g1, 'tag_groups');
    }
    $engine->reset_state();
    delete_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOCK);
    delete_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOG);
    delete_option(WP_Dynamic_Tags_Migration_Engine::OPTION_CLEANED);
    delete_option('wp_dynamic_tags_hard_delete_on_post_delete');
    if (is_dir($private)) {
        foreach ((array) scandir($private) as $f) {                                  // scandir, not glob: glob('*') skips dotfiles such as .htaccess
            if ($f !== '.' && $f !== '..' && !in_array($private . '/' . $f, $files_before, true)) {
                @unlink($private . '/' . $f);
            }
        }
        if (!$GLOBALS['private_existed']) {
            @rmdir($private);                                                        // the directory did not exist before the run
        }
    }
    delete_transient('dt_registration_lock');
    delete_transient('dt_table_registration_lock');
    $plugin->force_refresh_shortcodes();                                             // do not leave the post path's hour-long tag cache pointing at fixtures
    delete_transient('dt_registration_lock');
    delete_transient('dt_table_registration_lock');
    $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 343 AND pm.meta_key IN ('_dt_usage_count','_dt_last_used','foo','_edit_lock','_dt_priority','_dt_shortcode')");
}
register_shutdown_function('restore_site');

// ------------------------------------------------------------------ seed

$term = wp_insert_term('MG Group', 'tag_groups', ['slug' => 'mg-group']);
$g1   = (int) $term['term_id'];

$A  = mk_post('Alpha Tag', 'Alpha body {site_name}', 'publish', '2026-02-01 10:00:00', [$g1], ['_dt_priority' => '30', 'foo' => 'bar', '_edit_lock' => '1:1']);
$B  = mk_post('Beta Tag', 'Beta body', 'draft', '2026-02-01 10:00:00');
$C  = mk_post('Gamma Post', 'Gamma body', 'publish', '2026-01-01 00:00:00', [], ['_dt_shortcode' => 'cg_gamma_1']);
$rC = mk_row('cg_gamma_1', 'Gamma 1', 'Gamma body', '2026-03-01 00:00:00');
$D  = mk_post('Delta', 'Delta post body', 'publish', '2026-02-01 10:00:00');
$rD = mk_row('delta', 'Delta Existing', 'x', '2026-02-01 10:00:00');
$rE = mk_row('orphan_e', 'Orphan E', 'orph', '2026-02-01 10:00:00', 999999);
$F1 = mk_post('Echo Dup', 'Echo one', 'publish', '2026-02-01 10:00:00');
$F2 = mk_post('Echo-Dup', 'Echo two', 'publish', '2026-02-01 10:00:00');
$G  = mk_post('Trashed Tag', 'trash body', 'trash', '2026-02-01 10:00:00');
$H  = mk_post('Hotel', 'Hotel NEW', 'publish', '2026-05-01 00:00:00');
$rH = mk_row('hotel', 'Hotel', 'Hotel OLD', '2026-01-01 00:00:00', $H);
$I  = mk_post('India', 'India post version', 'publish', '2026-01-01 00:00:00');
$rI = mk_row('india', 'India', 'India table edit', '2026-06-01 00:00:00', $I);

$seed_hash  = rows_hash();
$seed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$T}");

// ================================================================== 1. dry run
section('1. Dry run writes nothing');
$plan = $engine->dry_run();
ok($plan['posts'] === 8, 'looks at the 8 non-trash legacy posts (trash is excluded)');
ok(($plan['actions']['insert'] ?? 0) === 3 && ($plan['actions']['link'] ?? 0) === 1 && ($plan['actions']['update'] ?? 0) === 1
    && ($plan['actions']['skip'] ?? 0) === 1 && ($plan['actions']['conflict'] ?? 0) === 2, 'plans 3 inserts, 1 link, 1 update, 1 skip, 2 conflicts: ' . json_encode($plan['actions']));
ok(rows_hash() === $seed_hash, 'no table row changed');
ok(state()['status'] === 'pending' && state()['backup'] === null, 'no state, no backup created');
ok(count(array_diff((array) glob($private . '/*'), $files_before)) === 0, 'no backup file written');

// ================================================================== 2. lock + first batch
section('2. Locking, then the first batch (size 3)');
update_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOCK, ['token' => 'someone-else', 'at' => time()], false);
$r = $engine->run_batch(3);
ok(err_code($r) === 'locked', 'a fresh lock held by someone else refuses to run');
ok(rows_hash() === $seed_hash && state()['backup'] === null, 'and nothing was written or backed up');
update_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOCK, ['token' => 'crashed', 'at' => time() - 1000], false);
$r = $engine->run_batch(3);
ok(!is_wp_error($r) && $r['processed'] === 3, 'an abandoned lock is taken over; first batch processed 3 posts');
$st = state();
ok($st['status'] === 'running' && $st['last_post_id'] === $C, 'status running, cursor at the third post');
ok(is_array($st['backup']) && $st['backup']['posts'] === 9 && $st['backup']['rows'] === $seed_count, 'backup written first: 9 posts (incl. trash), ' . $seed_count . ' rows');
$backup_check = $engine->validate_backup($st['backup']);
ok($backup_check['exists'] && $backup_check['sha_ok'] && $backup_check['counts_ok'], 'backup re-read: file present, checksum and record counts match');
ok(get_option(WP_Dynamic_Tags_Migration_Engine::OPTION_LOCK) === false, 'lock released');

// ================================================================== 3. resume to completion
section('3. Resume to completion');
$r = $engine->run(30, 3);
ok(!is_wp_error($r) && $r['done'] === true, 'run() resumes from the cursor and finishes');
$st = state();
ok($st['status'] === 'completed', 'status completed');
ok($st['counters']['inserted'] === 3 && $st['counters']['linked'] === 1 && $st['counters']['updated'] === 1, 'counters: 3 inserted, 1 linked, 1 updated');

$a = row_by_shortcode('alpha_tag');
$meta_a = $a ? maybe_unserialize($a['meta_data']) : [];
ok($a && $a['status'] === 'active' && (int) $a['post_id'] === $A && (int) $a['category_id'] === $g1 && (int) $a['priority'] === 30 && $a['content'] === 'Alpha body {site_name}', 'post-native tag inserted under its title-derived shortcode with status, group, priority, content');
ok(($meta_a['foo'] ?? '') === 'bar' && !isset($meta_a['_edit_lock']) && ($meta_a['legacy']['post_id'] ?? 0) === $A, 'extra meta kept, WordPress housekeeping meta dropped, provenance recorded');
$b = row_by_shortcode('beta_tag');
ok($b && $b['status'] === 'inactive', 'a draft becomes an inactive row');
$c = row_by_shortcode('cg_gamma_1');
$meta_c = $c ? maybe_unserialize($c['meta_data']) : [];
ok($c && (int) $c['post_id'] === $C && $c['content'] === 'Gamma body' && $c['modified_date'] === '2026-03-01 00:00:00', 'mirror post linked to the existing row, row untouched (modified_date kept despite the auto-update column)');
ok(($meta_c['legacy']['aliases'] ?? []) === ['gamma_post'], 'the shortcode the old post path published is kept as a legacy alias');
$h = row_by_shortcode('hotel');
ok($h && $h['content'] === 'Hotel NEW' && $h['modified_date'] === '2026-05-01 00:00:00', 'a newer post updates its older row, with the post\'s modified date');
$i = row_by_shortcode('india');
ok($i && $i['content'] === 'India table edit' && $i['modified_date'] === '2026-06-01 00:00:00', 'a row edited after its post is never overwritten');
$e1 = row_by_shortcode('echo_dup');
ok($e1 && (int) $e1['post_id'] === $F1, 'of two posts deriving the same shortcode the lowest ID wins');
ok($wpdb->get_var("SELECT COUNT(*) FROM {$T} WHERE post_id = {$F2}") === '0' && $wpdb->get_var("SELECT COUNT(*) FROM {$T} WHERE post_id = {$D}") === '0', 'the two conflicting posts got no row');
ok($wpdb->get_var("SELECT COUNT(*) FROM {$T} WHERE shortcode = 'trashed_tag'") === '0', 'the trashed post was not migrated');
ok((int) row_by_shortcode('orphan_e')['post_id'] === 0, 'a row pointing at a missing post was detached');
ok($st['conflicts'][$D]['status'] === 'open' && $st['conflicts'][$D]['reason'] === 'shortcode_taken' && $st['conflicts'][$F2]['reason'] === 'shortcode_taken', 'both conflicts are recorded, open, with their reason');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$T}") === $seed_count + 3, 'row count = seed + 3 inserts');

// ================================================================== 4. idempotency
section('4. Running again changes nothing (crash-after-commit and rescan)');
$after_run = rows_hash();
$st = state();
$st['last_post_id'] = 0;                                     // as if the cursor was never saved after the last commit
update_option(WP_Dynamic_Tags_Migration_Engine::OPTION_STATE, $st, false);
$engine->run(30, 100);
ok(rows_hash() === $after_run, 'cursor reset to 0 and re-run: the table is byte-identical');
$engine->rescan();
$engine->run(30, 100);
ok(rows_hash() === $after_run, 'rescan and re-run: the table is byte-identical');
ok(state()['counters']['inserted'] === 3, 'no duplicate inserts counted');

// ================================================================== 5. verification and cleanup are gated
section('5. Verification fails while conflicts are open; cleanup refuses');
$v = $engine->verify();
$failed_ids = array_column(array_filter($v['checks'], fn($c) => !$c['passed']), 'id');
sort($failed_ids);
ok(!$v['passed'] && $failed_ids === ['conflicts', 'processed'], 'fails on exactly "processed" and "conflicts": ' . json_encode($failed_ids));
ok(err_code($engine->cleanup_batch(50)) === 'not_verified', 'cleanup refuses without a passing verification');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'dynamic_tag'") === 9, 'no post was deleted');

// ================================================================== 6. resolve conflicts, verify
section('6. Resolve conflicts, then verification passes');
ok(err_code($engine->resolve_conflict(999999, 'rename')) === 'no_conflict', 'resolving a non-existent conflict is rejected');
ok($engine->resolve_conflict($D, 'rename') === true, 'Delta: migrate under a new name');
$rd = row_by_shortcode('delta_' . $D);
ok($rd && (int) $rd['post_id'] === $D && $rd['tag_name'] === 'Delta (' . $D . ')' && $rd['content'] === 'Delta post body', 'the renamed row exists, linked, with the post\'s content');
ok(row_by_shortcode('delta')['tag_name'] === 'Delta Existing', 'the row that caused the conflict is untouched');
ok($engine->resolve_conflict($F2, 'accept') === true, 'Echo-Dup: keep the post, skip it');
$v = $engine->verify();
ok($v['passed'], 'all five checks pass: ' . json_encode(array_map(fn($c) => $c['id'] . '=' . ($c['passed'] ? 'ok' : $c['detail']), $v['checks'])));
$s = $engine->status();
ok($s['can_cleanup'] === true && $s['verification_fresh'] === true, 'cleanup is now allowed');

// ================================================================== 7. cleanup is safe
section('7. Cleanup deletes only what is safe');
edit_post_sql($A, 'Alpha EDITED AFTER VERIFY', '2026-07-01 00:00:00');   // a post edited between verification and cleanup
$files_pre = (array) glob($private . '/backup-*.json');
$status_before = [];
foreach (['echo_dup', 'beta_tag'] as $sc) {
    $status_before[$sc] = row_by_shortcode($sc)['status'];
}
// With this option on, the legacy bridge DELETES a tag's row when its post is deleted. If cleanup did not
// suspend that hook, the rows of every deleted post would vanish below.
update_option('wp_dynamic_tags_hard_delete_on_post_delete', true);
ok(has_action('before_delete_post', [$bridge, 'sync_post_deletion']) !== false && has_action('delete_post', [$plugin, 'refresh_shortcodes_on_delete']) !== false, 'precondition: both legacy delete hooks are active');
$r1 = $engine->cleanup_batch(4);
$res = $engine->cleanup_batch(4);
delete_option('wp_dynamic_tags_hard_delete_on_post_delete');
ok(!is_wp_error($r1) && $r1['done'] === false, 'first batch of 4 reports more to do');
ok(!is_wp_error($res) && $res['done'] === true, 'the batch that handles the last post finishes cleanup by itself (no extra empty call needed)');
ok(get_option(WP_Dynamic_Tags_Migration_Engine::OPTION_CLEANED) !== false && state()['cleanup']['status'] === 'done', 'the completion marker is set and the cleanup state is "done"');
$st = state();
ok($st['cleanup']['deleted'] === 6 && $st['cleanup']['kept'] === 2, 'deleted 6 (B, C, D, F1, H, I), kept 2 (A changed since verification, F2 accepted): ' . json_encode($st['cleanup']));
ok(get_post($A) !== null && get_post($F2) !== null, 'the edited post and the accepted-conflict post still exist');
ok(get_post($B) === null && get_post($C) === null && get_post($D) === null && get_post($F1) === null && get_post($H) === null && get_post($I) === null, 'the six verified posts are gone');
ok(get_post($G) !== null, 'the trashed post is not touched');
ok(is_array($st['cleanup_backup']) && count(array_diff((array) glob($private . '/backup-*.json'), $files_pre)) === 1, 'a fresh pre-cleanup backup was written before deleting');
ok(row_by_shortcode('echo_dup')['status'] === $status_before['echo_dup'] && row_by_shortcode('beta_tag')['status'] === $status_before['beta_tag'], 'deleting posts did not deactivate their rows (legacy delete hooks suspended)');
ok((int) row_by_shortcode('hotel')['post_id'] === 0 && row_by_shortcode('hotel')['modified_date'] === '2026-05-01 00:00:00', 'a deleted post\'s row is detached without touching its modified_date');
ok((int) row_by_shortcode('alpha_tag')['post_id'] === $A && row_by_shortcode('alpha_tag')['content'] === 'Alpha body {site_name}', 'the kept post\'s row is still linked and unchanged');
ok(do_shortcode('[cg_gamma_1]') === 'Gamma body' && do_shortcode('[hotel]') === 'Hotel NEW', 'migrated tags still render after their posts are gone');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL") === $orig_orphans, 'no orphaned post meta was left behind');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$T} WHERE shortcode IN ('beta_tag','cg_gamma_1','delta_{$D}','echo_dup','hotel','india')") === 6, 'with the bridge\'s hard-delete option on, no row was deleted along with its post: cleanup suspended the legacy delete hook');
ok(has_action('before_delete_post', [$bridge, 'sync_post_deletion']) !== false && has_action('delete_post', [$plugin, 'refresh_shortcodes_on_delete']) !== false, 'and both legacy delete hooks were put back afterwards');

// ================================================================== 8. backup tamper + restore
section('8. A tampered backup is rejected; a genuine one restores exactly');
$good     = state()['backup']['file'];
$good_path = $engine->backup_path($good);
$fake     = 'backup-20200101-000000-' . str_repeat('a', 32) . '.json';
$fake_path = $engine->backup_path($fake);
copy($good_path, $fake_path);
copy($good_path . '.sha256', $fake_path . '.sha256');
file_put_contents($fake_path, ' ', FILE_APPEND);                                  // one byte of tampering
$hash_before = rows_hash();
ok(err_code($engine->restore($fake)) === 'checksum', 'restore refuses a file that no longer matches its checksum');
ok(rows_hash() === $hash_before, 'and the table is unchanged');
unlink($fake_path);
unlink($fake_path . '.sha256');
ok(err_code($engine->restore('../../wp-config.php')) === 'no_backup', 'a path-traversal name is rejected outright');

$restored = $engine->restore($good);
ok(!is_wp_error($restored) && $restored['restored_rows'] === $seed_count, 'restore of the pre-migration backup: ' . (is_wp_error($restored) ? $restored->get_error_message() : $restored['restored_rows'] . ' rows'));
ok(rows_hash() === $seed_hash, 'the table is byte-identical to its state before the migration started');
ok(is_wp_error($restored) ? false : is_file($engine->backup_path($restored['safety_backup'])), 'a safety backup of the pre-restore state was written');
ok(state()['status'] === 'pending' && state()['verification'] === null, 'migration state reset so the next run re-evaluates everything');

// ================================================================== 9. transactional rollback + resume
section('9. A failing batch rolls back completely and can be resumed');
// back to a pristine fixture set
foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'dynamic_tag'") as $pid) {
    wp_delete_post((int) $pid, true);
}
$wpdb->query("DELETE FROM {$T} WHERE id NOT IN (" . implode(',', $orig_ids) . ')');
$engine->reset_state();
$R0  = mk_post('Rb Zero', 'zero', 'publish', '2026-02-01 10:00:00');
$R1  = mk_post('Rb One', 'one NEW', 'publish', '2026-05-01 00:00:00');
$rR1 = mk_row('rb_one', 'Rb One', 'one OLD', '2026-01-01 00:00:00', $R1);
$wpdb->suppress_errors(true);
$fail_updates = static function ($sql) use ($T) {
    return strpos($sql, "UPDATE `{$T}`") === 0 ? 'SELECT * FROM mg_no_such_table_xyz' : $sql;
};
add_filter('query', $fail_updates);
$r = $engine->run_batch(100);
remove_filter('query', $fail_updates);
$wpdb->suppress_errors(false);
$st = state();
ok(err_code($r) === 'batch_failed' && $st['status'] === 'failed' && $st['last_error'] !== null, 'the batch fails cleanly: status failed, error recorded');
ok($wpdb->get_var("SELECT COUNT(*) FROM {$T} WHERE shortcode = 'rb_zero'") === '0', 'the insert made earlier in the same batch was rolled back');
ok($st['last_post_id'] === 0 && row_by_shortcode('rb_one')['content'] === 'one OLD', 'cursor did not advance and the older row is untouched');
$r = $engine->run_batch(100);
ok(!is_wp_error($r) && $r['done'] === true && state()['status'] === 'completed', 'resuming succeeds, and the batch that handles the last post also completes the migration (no stuck "running" state if the caller goes away)');
ok(row_by_shortcode('rb_zero') !== null && row_by_shortcode('rb_one')['content'] === 'one NEW', 'both posts are now migrated');
$rev = state()['revision'];
$r   = $engine->run_batch(100);
ok(!is_wp_error($r) && $r['done'] === true && state()['revision'] === $rev, 'a redundant call after completion changes nothing (it must not rewrite state and invalidate a verification)');

// ================================================================== 10. compatibility facade
section('10. The compatibility facade keeps old screens working and is no longer destructive');
$mm = WP_Dynamic_Tags_Migration_Manager::get_instance();
ok($mm->get_migration_status() === 'completed', 'get_migration_status() maps to the old vocabulary');
$stats = $mm->get_migration_statistics();
ok(isset($stats['total_posts'], $stats['total_table_records'], $stats['migration_status'], $stats['integrity_issues']), 'get_migration_statistics() keeps its keys');
$rows_before_reset = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$T}");
$mm->reset_migration();
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$T}") === $rows_before_reset && $rows_before_reset > 0, 'reset_migration() no longer truncates the table (it only forgets progress)');
ok(strpos((string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-table-manager.php'), 'migrate_posts_to_table') === false, 'the Table Manager no longer starts a migration on frontend requests');

// ================================================================== put the site back
section('Restoring the site and proving it');
restore_site();

ok(rows_hash() === $orig_hash, 'the original table rows are byte-identical to the snapshot');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'dynamic_tag'") === 0, 'no legacy posts remain');
ok(count(array_diff((array) glob($private . '/*'), $files_before)) === 0 && ($private_existed || !is_dir($private)), 'no backup files, guard files or directory were left behind');
ok(get_option(WP_Dynamic_Tags_Migration_Engine::OPTION_STATE, null) === null, 'no migration state left');
ok(!term_exists('mg-group', 'tag_groups'), 'fixture group removed');

echo "\n" . ($fail === 0 ? 'ALL ' . $pass . ' CHECKS PASSED' : $fail . ' FAILED, ' . $pass . ' passed') . "\n";
exit($fail === 0 ? 0 : 1);
