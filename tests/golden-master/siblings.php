<?php
/**
 * Characterization of the public API the sibling plugins call (WP_Dynamic_Tags_Table_Manager),
 * using their real payloads:
 *   - Certificate-Generator-V7.5  includes/Core/post-types.php:86-97   get_tag_by_shortcode / update_tag / create_tag
 *   - certificate-generator-gema  includes/integration-dashboard.php:133-144   get_tags(search, limit=0)
 *   - chatbot-by-eshaan           includes/class-chatbot-integrations.php:455-470, 686-688
 *
 *   php siblings.php <out.json>
 *
 * Records return types, WP_Error codes, returned-object keys and side effects. It documents CURRENT behaviour
 * (including known bugs); it does not assert correctness. Everything it creates is removed on exit.
 */
require __DIR__ . '/wp-boot.php';

$outfile = $argv[1] ?? __DIR__ . '/siblings-out.json';
global $wpdb;
$tbl = $wpdb->prefix . 'dynamic_tags';

// Act as the first administrator (looked up by role, not a hard-coded ID).
$admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
$admin_id  = (int) ($admin_ids[0] ?? 0);
if ($admin_id < 1) { fwrite(STDERR, "no administrator user found\n"); exit(2); }
wp_set_current_user($admin_id);

$m = WP_Dynamic_Tags_Table_Manager::get_instance();
$plugin = WP_Dynamic_Tags_Plugin::get_instance();

$cleanup = function () use ($wpdb, $tbl, $admin_id, $plugin) {
    static $done = false; if ($done) { return; } $done = true;
    wp_set_current_user($admin_id);
    $wpdb->query("DELETE FROM {$tbl} WHERE shortcode LIKE 'cg\\_gm\\_%' OR shortcode LIKE 'event\\_gm\\_%' OR shortcode LIKE 'gm\\_chat\\_%'");
    $post_ids = [];
    foreach (get_posts(['post_type' => 'dynamic_tag', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Gm ']) as $p) { $post_ids[] = (int) $p->ID; wp_delete_post($p->ID, true); }
    $sweep = function () use ($wpdb, $post_ids) {   // nothing may be left orphaned against the removed mirror posts
        if ($post_ids) { $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $post_ids) . ")"); }
    };
    $sweep(); register_shutdown_function($sweep);
    foreach (['cg_gm_teacher_999', 'cg_gm_school_998', 'event_gm_title', 'gm_chat_evt'] as $sc) { remove_shortcode($sc); }
    // The post path's one-hour tag-list cache must not keep listing the mirror posts that were just removed.
    delete_transient('dt_registration_lock');
    if (method_exists($plugin, 'force_refresh_shortcodes')) { $plugin->force_refresh_shortcodes(); }   // pre-refactor code only
    delete_transient('dt_registration_lock');            // force_refresh re-arms the 3-second lock; leave none behind
    delete_transient('dt_table_registration_lock');
};
// Start from the state of an isolated request (a previous process may have left the registration lock set).
delete_transient('dt_registration_lock');
delete_transient('dt_table_registration_lock');
$plugin->register_shortcodes();
register_shutdown_function($cleanup);

$describe = function ($v) {
    if (is_wp_error($v)) { return ['type' => 'WP_Error', 'code' => $v->get_error_code(), 'message' => $v->get_error_message()]; }
    if (is_object($v))   { $a = get_object_vars($v); ksort($a); return ['type' => 'object:' . get_class($v), 'keys' => array_keys($a), 'scalar_types' => array_map('gettype', $a)]; }
    if (is_array($v))    { return ['type' => 'array', 'count' => count($v)]; }
    return ['type' => gettype($v), 'is_int_gt0' => is_int($v) && $v > 0, 'value' => is_scalar($v) && !is_int($v) ? $v : null];
};
$dt_posts = fn() => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='dynamic_tag'");
$norm = fn($s) => $s;

$r = ['meta' => ['php' => PHP_VERSION, 'wp' => get_bloginfo('version'), 'dt_posts_before' => $dt_posts(), 'rows_before' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl}")]];

$cert = get_term_by('slug', 'certificates', 'tag_groups');
$cert_id = ($cert && !is_wp_error($cert)) ? (int) $cert->term_id : 0;
$r['meta']['certificates_group_exists'] = $cert_id > 0;

// ---- Certificate-Generator-V7.5: sync_to_dynamic_tags() path ------------------------------------------------
$sc = 'cg_gm_teacher_999';
$r['cg']['lookup_missing'] = $describe($m->get_tag_by_shortcode($sc));                      // before create
$created = $m->create_tag(['tag_name' => 'Gm Teacher Name 999', 'shortcode' => $sc, 'content' => 'Jane Doe', 'category_id' => $cert_id]);
$r['cg']['create'] = $describe($created);
$r['cg']['dt_posts_after_create'] = $dt_posts() - $r['meta']['dt_posts_before'];            // dual-write side effect
$tag = $m->get_tag_by_shortcode($sc);
$r['cg']['lookup_after_create'] = $describe($tag);
$r['cg']['render_after_create'] = do_shortcode("[$sc]");
$upd = $tag ? $m->update_tag($tag->id, ['content' => 'John Smith']) : null;
$r['cg']['update_content'] = $describe($upd);
$r['cg']['render_after_update'] = do_shortcode("[$sc]");
$r['cg']['stale_cache_lookup_content'] = ($m->get_tag_by_shortcode($sc)->content ?? null);   // facade-level cache after update
$r['cg']['create_duplicate_shortcode'] = $describe($m->create_tag(['tag_name' => 'Gm Teacher Name 999 b', 'shortcode' => $sc, 'content' => 'x']));
$r['cg']['create_second'] = $describe($m->create_tag(['tag_name' => 'Gm School Name 998', 'shortcode' => 'cg_gm_school_998', 'content' => 'Acme', 'category_id' => $cert_id]));

// ---- certificate-generator-gema: dashboard counts ------------------------------------------------------------
$cg_tags = $m->get_tags(['search' => 'cg_', 'limit' => 0]);
$ev_tags = $m->get_tags(['search' => 'event_', 'limit' => 0]);
$r['gema']['get_tags_cg']    = $describe($cg_tags) + ['first_item' => $describe($cg_tags[0] ?? null)];
$r['gema']['get_tags_event'] = $describe($ev_tags);
$r['gema']['cg_matches_our_two'] = count(array_filter($cg_tags, fn($t) => in_array($t->shortcode, [$sc, 'cg_gm_school_998'], true)));

// ---- chatbot-by-eshaan: auto_setup + sync_to_dynamic_tags ---------------------------------------------------
$r['chatbot']['create_legacy_keys'] = $describe($m->create_tag(['title' => 'Event Gm Title', 'shortcode' => 'event_gm_title', 'content' => 'Waiting for event sync...', 'group_id' => $cert_id]));
$r['chatbot']['lookup_after_legacy_create'] = $describe($m->get_tag_by_shortcode('event_gm_title'));
$r['chatbot']['create_correct_keys'] = $describe($m->create_tag(['tag_name' => 'Gm Chat Evt', 'shortcode' => 'gm_chat_evt', 'content' => 'Waiting', 'category_id' => 0]));
$t2 = $m->get_tag_by_shortcode('gm_chat_evt');
$r['chatbot']['update_by_id'] = $describe($t2 ? $m->update_tag($t2->id, ['content' => 'Synced']) : null);
$r['chatbot']['add_tag_method_exists'] = method_exists($m, 'add_tag');
$r['chatbot']['post_type_exists_dynamic_tag'] = post_type_exists('dynamic_tag');
$cpt = get_posts(['post_type' => 'dynamic_tag', 'posts_per_page' => 1, 'post_status' => 'any', 'meta_key' => '_dt_shortcode', 'meta_value' => 'gm_chat_evt']);
$r['chatbot']['cpt_lookup_by__dt_shortcode_finds_mirror_post'] = !empty($cpt);

// ---- other public reads / writes ---------------------------------------------------------------------------
$r['other']['get_tag_by_name'] = $describe($m->get_tag_by_name('Gm School Name 998'));
$r['other']['get_tag_missing_id'] = $describe($m->get_tag(999999999));
$r['other']['update_missing_id'] = $describe($m->update_tag(999999999, ['content' => 'x']));
$r['other']['delete_missing_id'] = $describe($m->delete_tag(999999999));
$r['other']['invalid_tag_name_chars'] = $describe($m->create_tag(['tag_name' => 'Bad <name>', 'shortcode' => 'gm_chat_bad', 'content' => 'x']));
$r['other']['bad_shortcode_chars'] = $describe($m->create_tag(['tag_name' => 'Gm Bad Sc', 'shortcode' => 'gm bad!', 'content' => 'x']));
$r['other']['auto_shortcode_when_missing'] = (function () use ($m, $describe) {
    $id = $m->create_tag(['tag_name' => 'Gm Chat Auto Name', 'content' => 'x']);
    $t = is_wp_error($id) ? null : $m->get_tag($id);
    return ['create' => $describe($id), 'generated_shortcode' => $t->shortcode ?? null];
})();
$r['other']['bulk_deactivate'] = (function () use ($m, $sc) { $t = $m->get_tag_by_shortcode($sc); return $t ? array_map(fn($x) => is_wp_error($x) ? $x->get_error_code() : gettype($x), $m->bulk_action('deactivate', [$t->id])) : null; })();
$r['other']['render_when_inactive'] = do_shortcode("[$sc]");
$d = $m->get_tag_by_shortcode($sc);
$r['other']['delete'] = $describe($d ? $m->delete_tag($d->id) : null);
$r['other']['dt_posts_after_delete'] = $dt_posts() - $r['meta']['dt_posts_before'];

$r['meta']['actions_fired'] = [
    'created' => did_action('wp_dynamic_tags_tag_created'), 'updated' => did_action('wp_dynamic_tags_tag_updated'),
    'deleted' => did_action('wp_dynamic_tags_tag_deleted'), 'used' => did_action('wp_dynamic_tags_tag_used'),
];

file_put_contents($outfile, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "OK siblings -> $outfile\n";
$cleanup();
