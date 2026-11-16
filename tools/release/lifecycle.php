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

echo "\n" . ($failed ? "$failed step(s) FAILED\n" : "lifecycle passed\n");
exit($failed ? 1 : 0);
