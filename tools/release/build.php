<?php
/**
 * Builds the release files from a commit, the same way on a developer's PC and in GitHub Actions.
 *
 *   php tools/release/build.php <x.y.z> [--ref=HEAD] [--out=build] [--allow-dirty]
 *
 * Writes three files into the output folder, which are exactly what a GitHub release publishes:
 *   wp-dynamic-tags-<x.y.z>.zip            the plugin (git archive: only what is committed, minus what .gitattributes export-ignores)
 *   wp-dynamic-tags-<x.y.z>.zip.sha256     "<sha256>  <file>", the format sha256sum -c reads
 *   wp-dynamic-tags-<x.y.z>.manifest.json  what the plugin's updater reads: the ZIP's SHA-256 and the WordPress/PHP requirements
 *
 * The build is reproducible: file times come from the commit, so the same commit gives the same ZIP, byte for byte. The version
 * is read from the commit being built and must be the one asked for, so a 3.1.1 ZIP cannot be made from a 3.1.0 tree.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root    = dirname(__DIR__, 2);
$version = $argv[1] ?? '';
$ref     = 'HEAD';
$out     = $root . DIRECTORY_SEPARATOR . 'build';
$dirty_ok = false;
foreach (array_slice($argv, 2) as $arg) {
    if (strpos($arg, '--ref=') === 0) {
        $ref = substr($arg, 6);
    } elseif (strpos($arg, '--out=') === 0) {
        $out = substr($arg, 6);
    } elseif ($arg === '--allow-dirty') {
        $dirty_ok = true;
    } else {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(2);
    }
}
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
    fwrite(STDERR, "usage: php tools/release/build.php <x.y.z> [--ref=HEAD] [--out=build] [--allow-dirty]\n");
    exit(2);
}

/** Runs git in the repository (no shell: the arguments are never parsed). */
$git = static function (array $args) use ($root): array {
    $proc = proc_open(array_merge(array('git', '-C', $root), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($proc)) {
        return array(1, '', 'git could not be started');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    return array(proc_close($proc), trim((string) $stdout), trim((string) $stderr));
};
$fail = static function (string $message): void {
    fwrite(STDERR, "build: $message\n");
    exit(1);
};

[$code, $commit, $err] = $git(array('rev-parse', '--verify', '--end-of-options', $ref . '^{commit}'));
if ($code !== 0 || !preg_match('/^[0-9a-f]{40}$/D', $commit)) {
    $fail("'$ref' is not a commit ($err)");
}

// A ZIP is made from the commit, so uncommitted changes would be silently left out of it.
if (!$dirty_ok && $ref === 'HEAD') {
    [, $status] = $git(array('status', '--porcelain', '--untracked-files=no'));
    if ($status !== '') {
        $fail("the working tree has uncommitted changes that the ZIP would not contain; commit them, or pass --allow-dirty\n$status");
    }
}

[$code, $main] = $git(array('show', $commit . ':wp-dynamic-tags.php'));
[, $readme]    = $git(array('show', $commit . ':readme.txt'));
$header = static function (string $text, string $key): string {
    return preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . ':[ \t]*(.*?)[ \t\r]*$/mi', $text, $m) ? $m[1] : '';
};
if ($code !== 0 || $header($main, 'Version') !== $version) {
    $fail("the plugin header at $ref says Version '" . $header($main, 'Version') . "', not $version");
}
if ($header($readme, 'Stable tag') !== $version) {
    $fail("readme.txt at $ref says Stable tag '" . $header($readme, 'Stable tag') . "', not $version");
}
$requires     = $header($main, 'Requires at least');
$requires_php = $header($main, 'Requires PHP');
$tested       = $header($readme, 'Tested up to');
foreach (array('Requires at least' => $requires, 'Requires PHP' => $requires_php, 'Tested up to' => $tested) as $name => $value) {
    if (!preg_match('/^\d+(\.\d+){0,2}$/D', $value)) {
        $fail("$name is '$value': the manifest needs a version number");
    }
}
[, $time] = $git(array('show', '-s', '--format=%ct', $commit));

if (!is_dir($out) && !mkdir($out, 0777, true)) {
    $fail("cannot create $out");
}
$name     = "wp-dynamic-tags-$version";
$zip_path = rtrim($out, '/\\') . DIRECTORY_SEPARATOR . "$name.zip";
if (is_file($zip_path)) {
    unlink($zip_path); // never append to, or silently keep, an earlier build
}
// core.autocrlf=false: git archive otherwise converts text files to this machine's line endings (CRLF on a Windows checkout with
// autocrlf=true), and the same commit would give a different ZIP here than on the Linux runner that builds the release.
[$code, , $err] = $git(array('-c', 'core.autocrlf=false', '-c', 'core.eol=lf', 'archive', '--format=zip', '--prefix=wp-dynamic-tags/', '-o', $zip_path, $commit));
if ($code !== 0 || !is_file($zip_path)) {
    $fail("git archive failed: $err");
}

$sha = hash_file('sha256', $zip_path);
file_put_contents($zip_path . '.sha256', "$sha  $name.zip\n");

$manifest = array(
    'plugin'       => 'wp-dynamic-tags',
    'version'      => $version,
    'tag'          => 'v' . $version,
    'zip'          => "$name.zip",
    'sha256'       => $sha,
    'commit'       => $commit,
    'requires'     => $requires,
    'tested'       => $tested,
    'requires_php' => $requires_php,
    'built_at'     => gmdate('Y-m-d\TH:i:s\Z', (int) $time), // the commit's time, so the same commit always gives the same manifest
);
file_put_contents(rtrim($out, '/\\') . DIRECTORY_SEPARATOR . "$name.manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "built from $commit\n";
echo "  $zip_path (" . filesize($zip_path) . " bytes)\n";
echo "  sha256 $sha\n";
echo "  $zip_path.sha256\n";
echo '  ' . rtrim($out, '/\\') . DIRECTORY_SEPARATOR . "$name.manifest.json\n";
