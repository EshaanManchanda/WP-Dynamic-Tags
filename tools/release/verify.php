<?php
/**
 * Release gate: do the version declarations agree, and is the ZIP a clean, installable plugin?
 *
 *   php tools/release/verify.php <x.y.z>                 the repository's version declarations
 *   php tools/release/verify.php <x.y.z> <plugin.zip>    the same, plus the built ZIP: one root folder, the files a plugin needs,
 *                                                        none of the files it must not ship, every PHP file lints, and the
 *                                                        version inside the ZIP is the version being released
 *
 * Used the same way by hand and by .github/workflows (ci.yml "package" job, release.yml). Exit code 0 = every check passed.
 * Nothing is written outside the system temp folder, and that is removed when the script ends.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root    = dirname(__DIR__, 2);
$version = $argv[1] ?? '';
$zip     = $argv[2] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
    fwrite(STDERR, "usage: php tools/release/verify.php <x.y.z> [plugin.zip]\n");
    exit(2);
}

$failed = 0;
$check  = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
};

/** The value of a "Key: value" line in a plugin header or readme.txt header. */
$header = static function (string $text, string $key): string {
    return preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . ':[ \t]*(.*?)[ \t\r]*$/mi', $text, $m) ? $m[1] : '';
};

/** The text of a "== Name ==" section of readme.txt. */
$section = static function (string $readme, string $name): string {
    return preg_match('/^==[ \t]*' . preg_quote($name, '/') . '[ \t]*==[ \t]*\r?\n(.*?)(?=^==[ \t]*[^=\r\n]+==|\z)/msi', $readme, $m) ? $m[1] : '';
};

/**
 * What both the repository and the built ZIP must say. $read(relative path) returns a file's text, or '' when it is absent.
 */
$plugin_files = static function (callable $read, string $where) use ($check, $header, $section, $version): void {
    $main = $read('wp-dynamic-tags.php');
    $docs = $read("docs/RELEASE-NOTES-$version.md");
    $rd   = $read('readme.txt');

    $check($header($main, 'Version') === $version, "$where: plugin header Version is $version");
    $check(preg_match("/define\\(\\s*'WP_DYNAMIC_TAGS_VERSION'\\s*,\\s*'" . preg_quote($version, '/') . "'\\s*\\)/", $main) === 1, "$where: WP_DYNAMIC_TAGS_VERSION is $version");
    $check($header($main, 'Update URI') === 'https://github.com/EshaanManchanda/WP-Dynamic-Tags', "$where: Update URI names the GitHub repository");
    $check(preg_match('/^\d+(\.\d+){0,2}$/D', $header($main, 'Requires at least')) === 1 && preg_match('/^\d+(\.\d+){0,2}$/D', $header($main, 'Requires PHP')) === 1, "$where: Requires at least and Requires PHP are versions");
    $check($header($rd, 'Stable tag') === $version, "$where: readme.txt Stable tag is $version");
    $check($header($rd, 'Requires at least') === $header($main, 'Requires at least'), "$where: readme.txt and the plugin header agree on Requires at least");
    $check($header($rd, 'Requires PHP') === $header($main, 'Requires PHP'), "$where: readme.txt and the plugin header agree on Requires PHP");
    $check(preg_match('/^=[ \t]*' . preg_quote($version, '/') . '[ \t]*=/m', $section($rd, 'Changelog')) === 1, "$where: readme.txt Changelog has an entry for $version");
    $check(preg_match('/^=[ \t]*' . preg_quote($version, '/') . '[ \t]*=/m', $section($rd, 'Upgrade Notice')) === 1, "$where: readme.txt Upgrade Notice has an entry for $version");
    $check(preg_match('/@version[ \t]+' . preg_quote($version, '/') . '\b/', $read('uninstall.php')) === 1, "$where: uninstall.php @version is $version");
    $check(strpos($read('languages/wp-dynamic-tags.pot'), "Project-Id-Version: WP Dynamic Tags $version") !== false, "$where: the translation template is for $version");
    $check($docs !== '' && strpos($docs, $version) !== false, "$where: docs/RELEASE-NOTES-$version.md exists and names the version");
};

// ---------------------------------------------------------------------- the repository
echo "Repository ($root)\n";
$plugin_files(static function (string $rel) use ($root): string {
    $s = @file_get_contents("$root/$rel");
    return $s === false ? '' : $s;
}, 'repo');

$info = json_decode((string) @file_get_contents("$root/plugin-info.json"), true);
$info = is_array($info) ? $info : array();
$check(($info['version'] ?? null) === $version, "repo: plugin-info.json version is $version");
$check(($info['changelog'][0]['version'] ?? null) === $version, "repo: plugin-info.json changelog starts with $version");
$main_text = (string) @file_get_contents("$root/wp-dynamic-tags.php");
$check(($info['requires']['wordpress'] ?? null) === $header($main_text, 'Requires at least') && ($info['requires']['php'] ?? null) === $header($main_text, 'Requires PHP'), 'repo: plugin-info.json requirements match the plugin header');
$readme_md = (string) @file_get_contents("$root/README.md");
$check(preg_match('/badge\/version-' . preg_quote($version, '/') . '-/', $readme_md) === 1, "repo: README.md version badge is $version");
$check(preg_match('/^###[ \t]+Version[ \t]+' . preg_quote($version, '/') . '\b/m', $readme_md) === 1, "repo: README.md has a 'Version $version' section");

// ---------------------------------------------------------------------- the ZIP
if ($zip !== '') {
    echo "\nPackage ($zip)\n";
    $archive = new ZipArchive();
    if (!is_file($zip) || $archive->open($zip) !== true) {
        $check(false, 'the ZIP opens');
    } else {
        $names = array();
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = (string) $archive->getNameIndex($i);
        }
        $prefix = 'wp-dynamic-tags/';

        $roots = array_unique(array_map(static fn(string $n): string => explode('/', $n)[0], $names));
        $check($roots === array('wp-dynamic-tags'), 'exactly one root folder, wp-dynamic-tags/ (found: ' . implode(', ', $roots) . ')');
        $unsafe = array_filter($names, static fn(string $n): bool => strpos($n, '..') !== false || $n[0] === '/' || strpos($n, '\\') !== false);
        $check(!$unsafe, 'no entry climbs out of the folder');

        $must = array('wp-dynamic-tags.php', 'readme.txt', 'uninstall.php', 'includes/class-updater.php', 'includes/class-license-manager.php',
            'includes/class-database-manager.php', 'admin/class-admin.php', 'languages/wp-dynamic-tags.pot', 'docs/API.md', 'docs/BUG-REGISTER.md', "docs/RELEASE-NOTES-$version.md");
        $missing = array_filter($must, static fn(string $f): bool => !in_array($prefix . $f, $names, true));
        $check(!$missing, 'the files a plugin needs are there' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        $assets = array_filter($names, static fn(string $n): bool => strpos($n, $prefix . 'assets/') === 0 && substr($n, -1) !== '/');
        $check(count($assets) > 0, 'the assets folder has files');

        $forbidden = array(
            '#^(tests|tools|vendor|build|node_modules|\.github|\.git)/#',
            '#^(composer\.(json|lock)|phpunit[^/]*\.xml(\.dist)?|phpcs[^/]*\.xml|phpstan[^/]*\.neon|plugin-info\.json|README\.md|ROADMAP\.md|TESTING\.md|HANDOFF\.md|LICENSE-[^/]*\.md|\.gitattributes|\.gitignore|\.gitleaksignore)$#',
            '#\.(zip|log|neon|sh|ps1|bak|orig|swp)$#i',
            '#(^|/)(\.env[^/]*|\.DS_Store|Thumbs\.db|after\.json)$#',
        );
        $shipped = array();
        foreach ($names as $n) {
            $rel = strpos($n, $prefix) === 0 ? substr($n, strlen($prefix)) : $n;
            foreach ($forbidden as $pattern) {
                if ($rel !== '' && preg_match($pattern, $rel)) {
                    $shipped[] = $rel;
                    break;
                }
            }
        }
        $check(!$shipped, 'no development, test, scratch or secret file ships' . ($shipped ? ' (found: ' . implode(', ', array_slice($shipped, 0, 8)) . ')' : ''));

        // Extract (every name was checked above) to a throw-away folder: version declarations and lint of the real packaged files.
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dt-verify-' . getmypid();
        $rm  = static function (string $d) use (&$rm): void {
            if (!is_dir($d)) {
                return;
            }
            foreach (scandir($d) as $i) {
                if ($i !== '.' && $i !== '..') {
                    is_dir("$d/$i") && !is_link("$d/$i") ? $rm("$d/$i") : unlink("$d/$i");
                }
            }
            rmdir($d);
        };
        register_shutdown_function(static function () use ($tmp, $rm): void {
            $rm($tmp);
        });
        $rm($tmp);
        if (!$unsafe && mkdir($tmp, 0700, true) && $archive->extractTo($tmp)) {
            $plugin_files(static function (string $rel) use ($tmp): string {
                $s = @file_get_contents("$tmp/wp-dynamic-tags/$rel");
                return $s === false ? '' : $s;
            }, 'zip');

            $bad   = array();
            $count = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$tmp/wp-dynamic-tags", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $count++;
                $proc = proc_open(array(PHP_BINARY, '-n', '-l', $file->getPathname()), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
                $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                if (proc_close($proc) !== 0) {
                    $bad[] = substr($file->getPathname(), strlen($tmp) + 1) . ': ' . trim($out);
                }
            }
            $check($count > 0 && !$bad, "every one of the $count packaged PHP files lints on PHP " . PHP_VERSION . ($bad ? ' (' . implode('; ', $bad) . ')' : ''));
        } else {
            $check(false, 'the ZIP extracts');
        }
        $archive->close();
    }
}

echo "\n" . ($failed ? "$failed check(s) FAILED\n" : "all checks passed\n");
exit($failed ? 1 : 0);
