<?php
/**
 * Golden-master renderer. One PHP process = one render path x one request context.
 *
 *   php render.php <path: post|table|unified> <ctx: out_singular|in_singular|out_archive> <out.json>
 *
 * post    : tags exist ONLY as dynamic_tag posts (bridge disabled), post-path registrar renders them.
 * table   : tags exist ONLY as wp_dynamic_tags rows, table-path registrar renders them.
 * unified : (post-refactor) tags exist ONLY as table rows, the single registrar renders them.
 *
 * Everything created here is removed in a shutdown handler.
 */
require __DIR__ . '/wp-boot.php';

[$self, $path, $ctx, $outfile] = $argv + [null, 'post', 'out_singular', __DIR__ . '/render-out.json'];
if (!in_array($path, ['post', 'table', 'unified'], true)) {
    fwrite(STDERR, "bad path\n");
    exit(2);
}

global $wpdb;
$tbl     = $wpdb->prefix . 'dynamic_tags';
$plugin  = WP_Dynamic_Tags_Plugin::get_instance();
$created = ['posts' => [], 'terms' => [], 'ctx_post' => 0, 'shortcodes' => []];

// The harness acts as the first administrator (fixtures must not be rewritten by kses).
$admin_ids = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
$admin_id  = (int) ($admin_ids[0] ?? 0);
if ($admin_id < 1) {
    fwrite(STDERR, "no administrator user found\n");
    exit(2);
}

// ---------------------------------------------------------------- fixtures
$F = [
    'plain'       => 'Hello World',
    'html'        => '<p class="x">Hi &amp; <b>bye</b></p>',
    'site'        => '{site_name}|{site_url}|{admin_email}|{current_year}|{current_date}',
    'post'        => '{post_title}|{post_id}|{post_url}|{post_author}',
    'user'        => '{user_name}|{user_email}|{user_id}|{user_role}',
    'table_only'  => '{tag_name}|{tag_id}|{usage_count}',
    'meta'        => '{meta:gm_price}|{meta:gm_price|currency}|{meta:gm_missing??N/A}',
    'fmt'         => '{post_title|upper}|{post_title|lower}|{site_name|upper}',
    'fallback'    => '{meta:gm_missing??Default Text}',
    'cond_in'     => '{if:user_logged_in}IN{else}OUT{/if}',
    'cond_noelse' => 'A{if:user_logged_in}IN{/if}B',
    'cond_role'   => '{if:user_role:administrator}ADMIN{else}NOTADMIN{/if}',
    'cond_field'  => '{if:meta:gm_price>10}BIG{else}SMALL{/if}',
    'nested'      => 'before [gm_plain] after',
    'dt_nested'   => '[dt tag="gm_plain"]',
    'dt_if'       => '[dt_if condition="user_logged_in"]YES[dt_else]NO[/dt_if]',
    'dt_if_field' => '[dt_if condition="meta:gm_price&gt;10"]BIG[dt_else]SMALL[/dt_if]',
    'loop'        => '[dt_loop source="query:post_type=post&posts_per_page=2&orderby=ID&order=ASC" separator="|"]{item:title}[/dt_loop]',
    'tpl'         => '[dt_template tag="gm_tplrow" query="post_type=post&posts_per_page=2&orderby=ID&order=ASC" separator="|"]',
    'tplrow'      => '<i>{post_title}</i>',
    'wc'          => '{wc:price}|{wc:sku}',
    'acf'         => '{acf:gm_sub}|{acf:gm_sub??none}',
    'unknown'     => '{not_a_token}|{meta:}|{',
    'escape'      => '<script>alert(1)</script> {post_title}',
    'grouped'     => 'In group',
];
$VARIANTS = ['', ' format="uppercase"', ' default="DEF"', ' cache="false"', ' processing="false"'];

// ---------------------------------------------------------------- cleanup
$cleanup = function () use (&$created, $tbl, $wpdb, $admin_id, $plugin) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    wp_set_current_user($admin_id);
    if (class_exists('WP_Dynamic_Tags_Post_Table_Bridge')) {
        remove_action('before_delete_post', [WP_Dynamic_Tags_Post_Table_Bridge::get_instance(), 'sync_post_deletion'], 10);
    }
    foreach ($created['shortcodes'] as $sc) {
        remove_shortcode($sc);
    }
    $all_posts = $created['posts'];
    if ($created['ctx_post']) {
        $all_posts[] = $created['ctx_post'];
    }
    foreach ($all_posts as $pid) {
        wp_delete_post((int) $pid, true);
    }
    $wpdb->query("DELETE FROM {$tbl} WHERE shortcode LIKE 'gm\\_%'");
    foreach ($created['terms'] as $tid) {
        wp_delete_term($tid, 'tag_groups');
    }
    // The post path caches its tag list in a one-hour transient. If that cache still lists the fixtures, every later
    // web request re-registers and test-renders them, writing usage meta against posts that no longer exist.
    // Rebuild the cache from the real database now that the fixtures are gone.
    delete_transient('dt_registration_lock');
    delete_transient('dt_table_registration_lock');
    $plugin->force_refresh_shortcodes();
    // force_refresh_shortcodes() re-arms the plugin's 3-second registration lock; leave none behind for the next process.
    delete_transient('dt_registration_lock');
    delete_transient('dt_table_registration_lock');
    // The post path writes usage meta against a tag's post ID during rendering; sweep it by ID so nothing is orphaned.
    $sweep = function () use ($wpdb, $all_posts) {
        if ($all_posts) {
            $in = implode(',', array_map('intval', $all_posts));
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)");
        }
    };
    $sweep();
    register_shutdown_function($sweep);   // once more after any late writes
};
register_shutdown_function($cleanup);

// ---------------------------------------------------------------- setup
// A previous process can leave the plugin's 3-second registration lock set, which makes init skip registering the
// core shortcodes ([dt], [dt_if], [dt_loop], ...). Start from the state of an isolated request instead.
delete_transient('dt_registration_lock');
delete_transient('dt_table_registration_lock');
$plugin->register_shortcodes();

wp_set_current_user($admin_id);
$_SERVER['HTTP_USER_AGENT'] = 'GoldenMaster/1.0';

$ctx_id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'GM Context Post',
    'post_content' => 'ctx body', 'post_author' => $admin_id, 'post_name' => 'gm-context-post',
]);
$created['ctx_post'] = (int) $ctx_id;
update_post_meta($ctx_id, 'gm_price', '29.99');
update_post_meta($ctx_id, 'gm_sub', 'Sub value');
if (function_exists('acf_add_local_field_group')) {
    acf_add_local_field_group([
        'key' => 'group_gm', 'title' => 'GM',
        'fields' => [['key' => 'field_gm_sub', 'label' => 'gm_sub', 'name' => 'gm_sub', 'type' => 'text']],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    ]);
}

$term    = wp_insert_term('GM Group', 'tag_groups', ['slug' => 'gm-group']);
$term_id = is_wp_error($term) ? 0 : (int) $term['term_id'];
if ($term_id) {
    $created['terms'][] = $term_id;
}

$reflect_clear = function ($obj) {
    $m = new ReflectionMethod($obj, 'clear_cache');
    $m->setAccessible(true);
    $m->invoke($obj);
};
$ids = [];   // fixture key => post id | row id

if ($path === 'post') {
    $bridge = WP_Dynamic_Tags_Post_Table_Bridge::get_instance();
    remove_action('save_post', [$bridge, 'sync_post_to_table'], 20);
    remove_action('before_delete_post', [$bridge, 'sync_post_deletion'], 10);
    remove_action('set_object_terms', [$bridge, 'sync_post_terms'], 10);
    foreach ($F as $k => $content) {
        $pid = wp_insert_post(['post_type' => 'dynamic_tag', 'post_status' => 'publish', 'post_title' => "gm_$k", 'post_content' => $content]);
        if (is_wp_error($pid) || !$pid) {
            fwrite(STDERR, "post insert failed for $k\n");
            continue;
        }
        $created['posts'][] = $pid;
        $ids[$k]            = $pid;
        if ($k === 'grouped' && $term_id) {
            wp_set_object_terms($pid, [$term_id], 'tag_groups');
        }
    }
    delete_transient('dt_registration_lock');
    $plugin->force_refresh_shortcodes();
} else {
    $db = WP_Dynamic_Tags_Database_Manager::get_instance();
    foreach ($F as $k => $content) {
        $r = $db->insert_tag([
            'tag_name' => "GM $k", 'shortcode' => "gm_$k", 'content' => $content,
            'category_id' => ($k === 'grouped' ? $term_id : 0), 'status' => 'active',
        ]);
        if (is_wp_error($r) || !$r) {
            fwrite(STDERR, "row insert failed for $k\n");
            continue;
        }
        $ids[$k] = (int) $r;
    }
    $reflect_clear(WP_Dynamic_Tags_Table_Manager::get_instance());
    delete_transient('dt_table_registration_lock');
    WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
}
foreach ($F as $k => $_) {
    $created['shortcodes'][] = "gm_$k";
}

// ---------------------------------------------------------------- request context
if ($ctx === 'in_singular') {
    wp_set_current_user($admin_id);
} else {
    wp_set_current_user(0);
}
if ($ctx === 'out_archive') {
    $q = new WP_Query(['post_type' => 'post', 'posts_per_page' => 1]);
    $GLOBALS['wp_query'] = $q;
    $GLOBALS['wp_the_query'] = $q;
    unset($GLOBALS['post']);
} else {
    $q = new WP_Query(['p' => $ctx_id, 'post_type' => 'post', 'post_status' => 'any']);
    $GLOBALS['wp_query'] = $q;
    $GLOBALS['wp_the_query'] = $q;
    $q->the_post();
}

// ---------------------------------------------------------------- render + normalize
$today     = gmdate('Y-m-d');
$today_fmt = wp_date(get_option('date_format'));
$emails    = array_values(array_filter(array_unique([(string) get_option('admin_email'), (string) get_userdata($admin_id)->user_email])));
$norm      = function (string $s) use ($ctx_id, $ids, $today, $today_fmt, $emails): string {
    $s = str_replace([$today, $today_fmt], '<TODAY>', $s);
    $s = str_ireplace($emails, '<EMAIL>', $s);   // keep real addresses out of the committed baseline (case-insensitive: formatters may upper-case)
    $s = preg_replace('/(?<!\d)' . $ctx_id . '(?!\d)/', '<CTXID>', $s);
    foreach ($ids as $k => $id) {
        $s = preg_replace('/(?<!\d)' . $id . '(?!\d)/', "<ID:$k>", $s);
    }
    return $s;
};

$invocations = [];
foreach ($F as $k => $_) {
    foreach ($VARIANTS as $v) {
        $invocations[] = "[gm_$k$v]";
    }
}
$invocations = array_merge($invocations, [
    '[dt tag="gm_plain"]', '[dt key="gm_plain"]', '[dt tag="gm_plain" default="D"]', '[dt tag="gm_nope" default="FALLBACK"]', '[dt key="gm_nope"]',
    '[dt_group group="GM Group"]', '[dt_group group="gm-group"]',
]);

$result = ['meta' => [
    'path' => $path, 'ctx' => $ctx, 'php' => PHP_VERSION, 'wp' => get_bloginfo('version'),
    'logged_in' => is_user_logged_in(), 'singular' => is_singular(),
    'acf_active' => function_exists('get_field_object'), 'wc_active' => class_exists('WooCommerce'),
], 'out' => []];
$q0 = $wpdb->num_queries;
foreach ($invocations as $inv) {
    $result['out'][$inv] = $norm((string) do_shortcode($inv));
}
$result['meta']['queries_render_only'] = $wpdb->num_queries - $q0;
$result['meta']['renders']             = count($invocations);

file_put_contents($outfile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "OK $path/$ctx: " . count($result['out']) . " renders -> $outfile\n";
$cleanup();
