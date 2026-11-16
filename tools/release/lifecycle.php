<?php
/**
 * Installs the BUILT release ZIP on a disposable WordPress site and walks it through its whole life with WP-CLI: install, use,
 * update check, uninstall that keeps the data, use again, uninstall that deletes the data. The assertions are in
 * lifecycle-checks.php; this file only does the ordering and stops with exit code 1 if any step fails.
 *
 *   php tools/release/lifecycle.php --wp="php /path/wp-cli.phar" --path=/path/to/blank/wordpress \
 *       --zip=build/wp-dynamic-tags-3.1.1.zip --version=3.1.1 --build=build
 *
 *   --wp       the WP-CLI command (split on spaces: no spaces inside paths). "wp" when WP-CLI is on the PATH.
 *   --path     an installed WordPress that nothing else needs: it gets tags, a changed plan and deleted tables.
 *   --zip      the release ZIP;  --build  the folder that holds it and its manifest;  --version  what they are.
 *
 * NEVER point it at a real site. It refuses a site whose address is not local or test-like unless --i-know-this-site-is-disposable.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opts = array('wp' => 'wp', 'path' => '', 'zip' => '', 'version' => '', 'build' => '');
$sure = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--i-know-this-site-is-disposable') {
        $sure = true;
    } elseif (preg_match('/^--(wp|path|zip|version|build)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
}
foreach (array('path', 'zip', 'version', 'build') as $need) {
    if ($opts[$need] === '') {
        fwrite(STDERR, "missing --$need (see the top of this file)\n");
        exit(2);
    }
}
if (!preg_match('/^\d+\.\d+\.\d+$/D', $opts['version']) || !is_file($opts['zip'])) {
    fwrite(STDERR, "--version must be x.y.z and --zip must be a file\n");
    exit(2);
}

$here   = __DIR__;
$failed = 0;

/** Runs WP-CLI against the site. @return array{0: int, 1: string} exit code and the output */
$wp = static function (array $args) use ($opts): array {
    $cmd  = array_merge(preg_split('/\s+/', trim($opts['wp'])), $args, array('--path=' . $opts['path'], '--quiet'));
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return array(1, 'WP-CLI could not be started');
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return array(proc_close($proc), (string) $out);
};
$step = static function (string $title, array $args, bool $show = false) use ($wp, &$failed): bool {
    echo "\n== $title\n";
    [$code, $out] = $wp($args);
    $out = trim($out);
    if ($out !== '' && ($show || $code !== 0)) {
        echo $out . "\n";
    }
    if ($code !== 0) {
        echo "  FAIL  exit code $code\n";
        $failed++;
    }
    return $code === 0;
};
$checks = static function (string $title, array $phase) use ($wp, $here, &$failed): void {
    echo "\n== $title\n";
    [$code, $out] = $wp(array_merge(array('eval-file', $here . '/lifecycle-checks.php'), $phase));
    echo rtrim($out) . "\n";
    if ($code !== 0) {
        $failed++;
    }
};

/** The value a WP-CLI command printed: its last line (a newer PHP makes WP-CLI's own libraries print deprecation notices before it). */
$value = static function (string $output): string {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
    return $lines ? end($lines) : '';
};

// The site must be installed, and must look disposable.
[$code, $home] = $wp(array('option', 'get', 'home'));
$home = $value($home);
if ($code !== 0 || $home === '') {
    fwrite(STDERR, "WP-CLI cannot read a WordPress site at {$opts['path']}: $home\n");
    exit(2);
}
$host = (string) parse_url($home, PHP_URL_HOST);
if (!$sure && !preg_match('/(^localhost$|^127\.|\.local$|\.test$|\.localhost$|^example\.|ci-|disposable|lifecycle)/i', $host)) {
    fwrite(STDERR, "Refusing to run: $host does not look like a disposable site. This check deletes tags and tables.\n");
    exit(2);
}
echo "Site $home, WordPress " . $value($wp(array('core', 'version'))[1]) . ', PHP ' . PHP_VERSION . ", plugin {$opts['version']}\n";

$slug = 'wp-dynamic-tags';
$v    = $opts['version'];

// Start from nothing: a previous run (or a probe) may have left the plugin, its tags and options on this disposable site.
$wp(array('option', 'update', 'wp_dynamic_tags_delete_on_uninstall', '1'));
$wp(array('plugin', 'deactivate', $slug));
$wp(array('plugin', 'uninstall', $slug));
$wp(array('option', 'delete', 'wp_dynamic_tags_delete_on_uninstall'));
$step('Install and activate the ZIP', array('plugin', 'install', realpath($opts['zip']), '--force', '--activate'));
$checks('A new site', array('fresh', $v));
$checks('Create tags and a group; the Free limit', array('seed'));
$checks('Render', array('render'));
$step('A license is activated: the plan changes to Paid', array('option', 'update', 'dt_plan', 'paid'));
$checks('A later request on the Paid plan', array('paid'));
$step('Back to Free', array('option', 'update', 'dt_plan', 'free'));
$checks('The update check against the real build output', array('update', $v, realpath($opts['build'])));

$step('Deactivate and uninstall, KEEPING the data (the default)', array('plugin', 'deactivate', $slug));
$step('Run uninstall.php', array('plugin', 'uninstall', $slug, '--skip-delete'));
$checks('After a keep-data uninstall', array('kept'));

$step('Activate again', array('plugin', 'activate', $slug));
$checks('The same tags render after reinstalling', array('render'));

$step('Choose "delete data on uninstall"', array('option', 'update', 'wp_dynamic_tags_delete_on_uninstall', '1'));
$step('Deactivate', array('plugin', 'deactivate', $slug));
$step('Run uninstall.php', array('plugin', 'uninstall', $slug, '--skip-delete'));
$checks('After a delete-data uninstall', array('gone'));
$wp(array('plugin', 'delete', $slug));

// ---------------------------------------------------------------- the update itself, done by WordPress's own upgrader
// A real earlier release cannot be used (3.1.0 had no updater, and no earlier build exists for a first release), so the previous
// version is this very build with its version lowered by one step: it can update itself, which is the point.
$lowered = static function (string $version): string {
    [$major, $minor, $patch] = array_map('intval', explode('.', $version));
    if ($patch > 0) {
        return "$major.$minor." . ($patch - 1);
    }
    return $minor > 0 ? "$major." . ($minor - 1) . '.0' : max(0, $major - 1) . '.0.0';
};
$previous = $lowered($v);
$old_zip  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dt-previous-' . getmypid() . '.zip';
register_shutdown_function(static function () use ($old_zip): void {
    if (is_file($old_zip)) {
        unlink($old_zip);
    }
});
$made = copy($opts['zip'], $old_zip);
$zip  = new ZipArchive();
if (!$made || $zip->open($old_zip) !== true) {
    fwrite(STDERR, "cannot make the previous-version ZIP\n");
    exit(2);
}
$main = (string) $zip->getFromName('wp-dynamic-tags/wp-dynamic-tags.php');
$main = preg_replace('/^( \* Version:[ \t]*)' . preg_quote($v, '/') . '(?=[ \t\r]*$)/m', '${1}' . $previous, $main, 1, $n1);
$main = preg_replace("/(define\\('WP_DYNAMIC_TAGS_VERSION',\\s*')" . preg_quote($v, '/') . "('\\))/", '${1}' . $previous . '${2}', (string) $main, 1, $n2);
if ($n1 !== 1 || $n2 !== 1 || !$zip->addFromString('wp-dynamic-tags/wp-dynamic-tags.php', (string) $main) || !$zip->close()) {
    fwrite(STDERR, "cannot lower the version in the previous-version ZIP\n");
    exit(2);
}

$build = realpath($opts['build']);
$step("Install the PREVIOUS version ($previous: this build with its version lowered)", array('plugin', 'install', $old_zip, '--force', '--activate'));
$checks('Tags for the update scenarios', array('seed'));
$checks("UPDATE $previous to $v, by WordPress's own upgrader", array('upgrade', $v, $build, 'ok', $slug));
$checks('The updated plugin renders the tags it already had', array('render'));
$step("Back to $previous", array('plugin', 'install', $old_zip, '--force', '--activate'));
$checks('UPDATE with a ZIP that was changed after the build', array('upgrade', $v, $build, 'tampered', $slug));

// A copy that was not installed from the release ZIP lives in another folder (GitHub's "Source code" ZIP, a renamed folder).
$step('Deactivate, and move the plugin to a folder with another name', array('plugin', 'deactivate', $slug));
[, $plugins_dir] = $wp(array('eval', 'echo WP_PLUGIN_DIR;'));
$plugins_dir     = $value($plugins_dir);
if (!@rename("$plugins_dir/$slug", "$plugins_dir/dt-renamed")) {
    echo "  FAIL  could not rename the plugin folder\n";
    $failed++;
}
$step('Activate it there', array('plugin', 'activate', 'dt-renamed'));
$checks('UPDATE of the copy installed in dt-renamed/', array('upgrade', $v, $build, 'ok', 'dt-renamed'));

$wp(array('option', 'update', 'wp_dynamic_tags_delete_on_uninstall', '1'));
$wp(array('plugin', 'deactivate', 'dt-renamed'));
$wp(array('plugin', 'uninstall', 'dt-renamed'));

echo "\n" . ($failed ? "$failed step(s) FAILED\n" : "lifecycle passed\n");
exit($failed ? 1 : 0);
