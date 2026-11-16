<?php
/**
 * Syntax-checks every PHP file the repository tracks with the PHP that runs this script (so CI covers 8.2 to 8.5 file by file).
 *
 *   php tools/release/lint.php
 *
 * Exit code 0 = every file parses. Reads the list from git, so untracked scratch files and vendor/ are not looked at.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$proc = proc_open(array('git', '-C', $root, 'ls-files', '-z', '*.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "git could not be started\n");
    exit(2);
}
$list = stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
if (proc_close($proc) !== 0 || $list === '') {
    fwrite(STDERR, "git ls-files returned nothing: run this inside the repository\n");
    exit(2);
}

$files  = array_filter(explode("\0", $list), 'strlen');
$failed = 0;
foreach ($files as $file) {
    $check = proc_open(array(PHP_BINARY, '-n', '-l', "$root/$file"), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out   = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (proc_close($check) !== 0) {
        echo "FAIL  $file\n" . trim($out) . "\n";
        $failed++;
    }
}
echo count($files) . ' files on PHP ' . PHP_VERSION . ($failed ? ", $failed with errors\n" : ", all parse\n");
exit($failed ? 1 : 0);
