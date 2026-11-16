<?php
/**
 * What a site owner's whole experience of the BUILT plugin has to be, checked inside a real WordPress.
 *
 * Not run directly: tools/release/lifecycle.php installs the release ZIP on a disposable site and calls this file through
 * `wp eval-file`, once per phase, in a fresh PHP process each time (so shortcodes are registered the way a page request does it):
 *
 *   fresh   <x.y.z>               installed, active, the right version, the Update URI, the table, the Free plan
 *   seed                          a group and tags; the Free limit refuses the 16th tag a person creates, never a plugin's
 *   render                        the tags render, [dt_group] and [dt_count] work, a Paid token renders nothing on Free
 *   paid                          (a later request, after the plan was changed) the same Paid token shows its value; no tag limit
 *   update  <x.y.z> <build dir>   the updater accepts the REAL manifest and ZIP the build produced, and refuses a tampered ZIP
 *   upgrade <x.y.z> <build dir> <ok|tampered> <folder>
 *                                 WordPress's own upgrader updates an install of the previous version, in the folder it lives in,
 *                                 keeping the tags and the activation; a changed ZIP is refused and nothing changes
 *   kept                         after an uninstall that keeps the data: tags and groups are all still there
 *   gone                          after an uninstall that deletes the data: no table, no options, no groups; the private backup stays
 *
 * Exit code 1 if any check fails. Needs a disposable site: it creates and deletes tags and changes the plan.
 */
if (!defined('ABSPATH') || !isset($args)) {
    fwrite(STDERR, "Run through: wp eval-file tools/release/lifecycle-checks.php <phase> [...]\n");
    exit(2);
}
global $wpdb;

$phase  = (string) ($args[0] ?? '');
$failed = 0;
$t      = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
};
$table = $wpdb->prefix . 'dynamic_tags';
$count = static function (string $sql) use ($wpdb): int {
    return (int) $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed statements built in this file
};
$plugin_file = 'wp-dynamic-tags/wp-dynamic-tags.php';
$manager     = static function () {
    return WP_Dynamic_Tags_Table_Manager::get_instance();
};

/**
 * Replaces GitHub (and WordPress.org's update check) with the files the build wrote; nothing leaves this machine.
 *
 * @param string $zip_bytes What the mock serves for the release ZIP; passed by reference so a phase can tamper with it.
 * @return array|null The manifest, or null when the build output is incomplete.
 */
$serve_github = static function (string $version, string $dir, string &$zip_bytes): ?array {
    $zip = "$dir/wp-dynamic-tags-$version.zip";
    $man = "$dir/wp-dynamic-tags-$version.manifest.json";
    if (!is_file($zip) || !is_file($man)) {
        return null;
    }
    $manifest  = json_decode((string) file_get_contents($man), true);
    $tag       = 'v' . $version;
    $base      = "https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/$tag/";
    $release   = array(
        'tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'body' => "Release $version.", 'published_at' => '2026-11-20T10:00:00Z',
        'html_url' => "https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/tag/$tag",
        'assets'   => array(
            array('name' => "wp-dynamic-tags-$version.zip", 'size' => filesize($zip), 'state' => 'uploaded', 'browser_download_url' => $base . "wp-dynamic-tags-$version.zip"),
            array('name' => "wp-dynamic-tags-$version.manifest.json", 'size' => filesize($man), 'state' => 'uploaded', 'browser_download_url' => $base . "wp-dynamic-tags-$version.manifest.json"),
        ),
    );
    $zip_bytes = (string) file_get_contents($zip);
    add_filter('pre_http_request', static function ($pre, $a, $url) use (&$zip_bytes, $release, $man, $base, $version) {
        $ok = static fn(string $body) => array('headers' => array(), 'body' => $body, 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => $a['filename'] ?? null);
        if ($url === WP_Dynamic_Tags_Updater::API_URL) {
            return $ok(wp_json_encode($release));
        }
        if ($url === $base . "wp-dynamic-tags-$version.manifest.json") {
            return $ok((string) file_get_contents($man));
        }
        if ($url === $base . "wp-dynamic-tags-$version.zip" && !empty($a['filename'])) {
            file_put_contents($a['filename'], $zip_bytes);
            return $ok('');
        }
        if (strpos($url, 'api.wordpress.org/plugins/update-check') !== false) { // WordPress's own check: nothing else to update
            return $ok(wp_json_encode(array('plugins' => array(), 'translations' => array(), 'no_update' => array())));
        }
        return new WP_Error('unexpected', "unexpected request to $url");
    }, 10, 3);
    return is_array($manifest) ? $manifest : null;
};

switch ($phase) {
    case 'fresh':
        $version = (string) ($args[1] ?? '');
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
        $t(is_plugin_active($plugin_file), 'the plugin is active');
        $t(defined('WP_DYNAMIC_TAGS_VERSION') && WP_DYNAMIC_TAGS_VERSION === $version, "the running version is $version");
        $t(($data['Version'] ?? '') === $version, "the plugin header says $version");
        $t(($data['UpdateURI'] ?? '') === 'https://github.com/EshaanManchanda/WP-Dynamic-Tags', 'WordPress reads the Update URI from the header');
        $t(has_filter('update_plugins_github.com') !== false && has_filter('upgrader_pre_download') !== false, 'the updater is hooked into WordPress');
        $t(WP_Dynamic_Tags_Database_Manager::get_instance()->table_exists(true), 'the tag table was created');
        $t(DT_License_Manager::get_plan() === 'free' && DT_License_Manager::get_license_key() === '', 'a new site is on the Free plan with no license key');
        $t($count("SELECT COUNT(*) FROM $table") === 0, 'there are no tags yet');
        break;

    case 'seed':
        $term = wp_insert_term('Lifecycle Group', 'tag_groups', array('slug' => 'lc-group'));
        $t(!is_wp_error($term), 'a group can be created');
        $group = is_wp_error($term) ? 0 : (int) $term['term_id'];
        $made  = array();
        // Each tag has its own content: [dt_group] lists the contents of a group's tags, not their names.
        foreach (array('lc_one' => array('Lc Alpha', 'Alpha'), 'lc_two' => array('Lc Bravo', 'Bravo'), 'lc_three' => array('Lc Charlie', 'Charlie')) as $code => $named) {
            $made[] = $manager()->create_tag(array('tag_name' => $named[0], 'shortcode' => $code, 'content' => $named[1] . ' {site_name|upper}', 'category_id' => $group), 'user');
        }
        $made[] = $manager()->create_tag(array('tag_name' => 'Lc Probe', 'shortcode' => 'lc_probe', 'content' => '{func:lc_probe??Not available}'), 'user');
        $t(count(array_filter($made, 'is_int')) === 4, 'four tags are created through the plugin API, three of them in the group');

        $n       = 0;
        $refused = null;
        while ($n < 40) { // the Free limit is 15 tags a person creates; the loop only guards against a limit that never comes
            $result = $manager()->create_tag(array('tag_name' => 'Lc Fill ' . $n, 'shortcode' => 'lc_fill_' . $n, 'content' => 'x'), 'user');
            if (is_wp_error($result)) {
                $refused = $result;
                break;
            }
            $n++;
        }
        $t($refused !== null && $refused->get_error_code() === 'dt_tag_limit', 'the Free plan refuses a person\'s 16th tag (dt_tag_limit)');
        $t($count("SELECT COUNT(*) FROM $table") === 15, 'and exactly 15 tags exist');
        $plugin_tag = $manager()->create_tag(array('tag_name' => 'Lc Sibling', 'shortcode' => 'lc_sibling', 'content' => 'from another plugin'));
        $t(is_int($plugin_tag), 'a tag another plugin creates is never refused by the limit');
        $t($count("SELECT COUNT(*) FROM $table") === 16, 'and it does not count toward it');
        break;

    case 'render':
        $site = strtoupper(get_bloginfo('name'));
        $t(do_shortcode('[lc_one]') === 'Alpha ' . $site, 'a tag renders as its own shortcode');
        $t(do_shortcode('[dt tag="lc_two"]') === 'Bravo ' . $site, 'and through [dt]');
        $t(do_shortcode('[dt_group group="lc-group"]') === "Alpha $site, Bravo $site, Charlie $site", '[dt_group] lists the group');
        $t(do_shortcode('[dt_count group="lc-group"]') === '3', '[dt_count] counts it');
        $t(DT_License_Manager::get_plan() === 'free', 'the site is on the Free plan');
        WP_Dynamic_Tags_Placeholders::get_instance()->register_allowed_function('lc_probe', static fn($arg = null) => 'PROBE');
        $t(do_shortcode('[lc_probe]') === 'Not available', 'a Paid token ({func:}) renders nothing on Free: the author\'s fallback shows');
        break;

    case 'paid':
        // A separate request after the plan changed (as it is when a license is activated): the Paid value shows, and no limit.
        $t(DT_License_Manager::get_plan() === 'paid', 'the site is on the Paid plan');
        WP_Dynamic_Tags_Placeholders::get_instance()->register_allowed_function('lc_probe', static fn($arg = null) => 'PROBE');
        $t(do_shortcode('[lc_probe]') === 'PROBE', 'the same tag now renders its Paid value');
        $extra = $manager()->create_tag(array('tag_name' => 'Lc Paid Extra', 'shortcode' => 'lc_paid_extra', 'content' => 'x'), 'user');
        $t(is_int($extra), 'and Paid has no tag limit');
        if (is_int($extra)) {
            $manager()->delete_tag($extra);
        }
        break;

    case 'update':
        $version = (string) ($args[1] ?? '');
        $dir     = rtrim((string) ($args[2] ?? ''), '/\\');
        $zip_bytes = '';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $manifest = $serve_github($version, $dir, $zip_bytes);
        $t($manifest !== null, 'the build output has the ZIP and its manifest');
        if ($manifest === null) {
            break;
        }
        $base = "https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/v$version/";

        delete_site_option(WP_Dynamic_Tags_Updater::OPTION);
        $older  = array('Version' => '0.0.1');
        $offer  = apply_filters('update_plugins_github.com', false, $older, WP_Dynamic_Tags_Updater::plugin_file(), array());
        $t(is_array($offer) && ($offer['version'] ?? '') === $version, "the updater accepts the build's own manifest and offers $version to an older install");
        $t(is_array($offer) && ($offer['package'] ?? '') === $base . "wp-dynamic-tags-$version.zip", 'at the GitHub release URL');
        $same = apply_filters('update_plugins_github.com', false, array('Version' => $version), WP_Dynamic_Tags_Updater::plugin_file(), array());
        $t(!is_array($same) || !isset($same['package']), 'and offers nothing to a site already on that version');

        $package = (string) ($offer['package'] ?? '');
        $file    = apply_filters('upgrader_pre_download', false, $package, null, array());
        $t(is_string($file) && is_file($file) && hash_file('sha256', $file) === ($manifest['sha256'] ?? ''), 'the downloaded ZIP matches the manifest checksum and is handed to WordPress');
        $t(is_string($file) && $zip_bytes !== '' && hash_file('sha256', $file) === hash('sha256', $zip_bytes), 'and it is the file the build wrote');
        if (is_string($file) && is_file($file)) {
            wp_delete_file($file);
        }

        $zip_bytes .= 'tampered';
        $bad = apply_filters('upgrader_pre_download', false, $package, null, array());
        $t(is_wp_error($bad) && $bad->get_error_code() === 'dt_update_checksum', 'a ZIP that was changed after the build is refused');
        $t(get_site_option(WP_Dynamic_Tags_Updater::OPTION) !== false, 'the checked release is cached (an uninstall must remove this)');
        break;

    case 'upgrade':
        // An update by WordPress's own upgrader (the code path of the Plugins screen, WP-CLI and auto-updates) on an install of the
        // PREVIOUS version that holds the 16 tags of the 'seed' phase, with GitHub replaced by the build output.
        // $mode: ok | tampered (the ZIP is changed after the build). $folder: the folder the plugin is installed in.
        $version = (string) ($args[1] ?? '');
        $dir     = rtrim((string) ($args[2] ?? ''), '/\\');
        $mode    = (string) ($args[3] ?? 'ok');
        $folder  = (string) ($args[4] ?? 'wp-dynamic-tags');
        $file    = $folder . '/wp-dynamic-tags.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        WP_Filesystem();

        $zip_bytes = '';
        $manifest  = $serve_github($version, $dir, $zip_bytes);
        $t($manifest !== null, 'the build output has the ZIP and its manifest');
        if ($manifest === null) {
            break;
        }
        if ($mode === 'tampered') {
            $zip_bytes .= 'tampered';
        }
        $header = static function () use ($file): string {
            return (string) (get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false)['Version'] ?? '');
        };

        $before = $header();
        $t($before !== '' && version_compare($before, $version, '<'), "the installed copy in $folder/ is an older version ($before)");
        $t(is_plugin_active($file), 'it is active');
        $t($count("SELECT COUNT(*) FROM $table") === 16, 'and holds its 16 tags');

        delete_site_option(WP_Dynamic_Tags_Updater::OPTION);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $found   = get_site_transient('update_plugins');
        $offered = is_object($found) && isset($found->response[$file]) ? $found->response[$file] : null;
        $t($offered !== null && ($offered->new_version ?? '') === $version, "WordPress's own update check offers $version for it");
        if ($offered === null) {
            break;
        }

        $result = (new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->upgrade($file);
        wp_clean_plugins_cache(true);
        $copies = array_map(static fn(string $p): string => basename(dirname($p)), (array) glob(WP_PLUGIN_DIR . '/*/wp-dynamic-tags.php'));

        if ($mode === 'tampered') {
            $t($result !== true, 'a ZIP that was changed after the build is not installed');
            $t($header() === $before, 'the installed version is untouched');
        } else {
            $t($result === true, 'WordPress installs the update');
            $t($header() === $version, "the plugin is now $version");
            $t(is_file(WP_PLUGIN_DIR . "/$folder/includes/class-updater.php"), 'with the new files');
            // The upgrader takes the plugin offline while it replaces the files and leaves re-activating it to its caller: the
            // Plugins screen does it right after an update. Activating loads the new files and runs the activation hook.
            $activated = activate_plugin($file);
            $t(!is_wp_error($activated), 'WordPress activates the new version, as the Plugins screen does after an update' . (is_wp_error($activated) ? ': ' . $activated->get_error_message() : ''));
        }
        $t($copies === array($folder), 'and there is no second copy of the plugin in another folder' . ($copies === array($folder) ? '' : ' (found: ' . implode(', ', $copies) . ')'));
        $t(is_plugin_active($file), 'the plugin is still active');
        $t($count("SELECT COUNT(*) FROM $table") === 16, 'and every tag is still there');
        break;

    case 'kept':
        $t($count("SELECT COUNT(*) FROM $table") === 16, 'an uninstall that keeps the data left all 16 tags');
        $t($count("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tag_groups'") === 1, 'and the group');
        $t(get_option('wp_dynamic_tags_data_preserved') !== false, 'and noted that the data was preserved');
        $t(get_site_option('dt_update_state') === false, 'but the update checker\'s cache is gone');
        break;

    case 'gone':
        // SHOW TABLES answers the table's name when it exists and nothing when it does not (counting it would cast the name to 0).
        $t($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($table) . "'") === null, 'an uninstall that deletes the data dropped the tag table'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $t($count("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tag_groups'") === 0, 'and the groups');
        $left = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '^(_transient_(timeout_)?)?(dt_|wp_dynamic_tags_)'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $t(!$left, 'and every option and transient of the plugin' . ($left ? ' (left behind: ' . implode(', ', $left) . ')' : ''));
        $backups = (array) glob(wp_upload_dir()['basedir'] . '/wp-dynamic-tags-private/uninstall-backup-*.json');
        $t(count($backups) >= 1 && strpos((string) file_get_contents(end($backups)), 'lc_one') !== false, 'the private backup the uninstall writes first holds the tags');
        foreach ($backups as $backup) {
            wp_delete_file($backup); // a disposable site: leave nothing behind
        }
        break;

    default:
        fwrite(STDERR, "unknown phase '$phase'\n");
        exit(2);
}

exit($failed ? 1 : 0);
