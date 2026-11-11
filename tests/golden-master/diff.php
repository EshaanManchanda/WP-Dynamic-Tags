<?php
/**
 * Compare two golden-master result sets.
 *
 *   php diff.php <dirA> <pathA> <dirB> <pathB> <ctx> [--all] [--allow=allowed-differences.json]
 *   php diff.php baseline post baseline table in_singular      # how the two legacy paths differ
 *   php diff.php baseline post current unified in_singular     # post-origin tags, before vs after
 *
 * Without --all the attribute variants (format=, default=, cache=, processing=) are skipped.
 * Exit code 1 when any output differs, so it can gate a release.
 *
 * With --allow every difference must be listed in the allow-list with the EXACT new output, a bug id and a reason
 * (a security or bug fix is never approved in bulk). The run fails on
 *   - a difference that is not listed (or listed with a different output), and
 *   - a listed difference that no longer occurs (a stale entry hides future regressions).
 * Entries are matched on pathB, ctx and the invocation text; pathA is the baseline side.
 */
[$s, $dirA, $pa, $dirB, $pb, $ctx] = $argv + [null, 'baseline', 'post', 'baseline', 'table', 'out_singular'];
$all = in_array('--all', $argv, true);
$allowFile = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--allow=')) {
        $allowFile = substr($a, 8);
    }
}

$A = json_decode(file_get_contents(__DIR__ . "/$dirA/{$pa}__{$ctx}.json"), true);
$B = json_decode(file_get_contents(__DIR__ . "/$dirB/{$pb}__{$ctx}.json"), true);

$allowed = [];
if ($allowFile !== null) {
    $doc = json_decode(file_get_contents(str_contains($allowFile, DIRECTORY_SEPARATOR) || str_contains($allowFile, '/') ? $allowFile : __DIR__ . '/' . $allowFile), true);
    foreach ($doc['differences'] ?? [] as $e) {
        if (($e['path'] ?? null) === $pb && ($e['ctx'] ?? null) === $ctx) {
            $allowed[$e['invocation']] = $e;
        }
    }
}

$same = 0;
$diff = 0;
$approved = 0;
$unlisted = 0;
$seen = [];
foreach ($A['out'] as $inv => $va) {
    if (!$all && preg_match('/ (format|default|cache|processing)="/', $inv)) {
        continue;
    }
    $vb = $B['out'][$inv] ?? '<missing>';
    if ($va === $vb) {
        $same++;
        continue;
    }
    $diff++;
    if ($allowFile !== null && isset($allowed[$inv]) && $allowed[$inv]['expected'] === $vb) {
        $approved++;
        $seen[$inv] = true;
        continue;
    }
    $unlisted++;
    printf("%s\n   %-8s %s\n   %-8s %s\n", $inv, $pa, json_encode($va, JSON_UNESCAPED_SLASHES), $pb, json_encode($vb, JSON_UNESCAPED_SLASHES));
}

$stale = 0;
foreach ($allowed as $inv => $e) {
    if (!isset($seen[$inv])) {
        $stale++;
        printf("STALE allow-list entry (%s %s %s): the difference no longer occurs or the output changed\n", $e['bug'] ?? '?', $ctx, $inv);
    }
}

if ($allowFile !== null) {
    printf("== %s: identical=%d approved=%d unlisted=%d stale=%d (queries during render: A=%d B=%d)\n", $ctx, $same, $approved, $unlisted, $stale, $A['meta']['queries_render_only'] ?? -1, $B['meta']['queries_render_only'] ?? -1);
    exit($unlisted > 0 || $stale > 0 ? 1 : 0);
}
printf("== %s: identical=%d different=%d (queries during render: A=%d B=%d)\n", $ctx, $same, $diff, $A['meta']['queries_render_only'] ?? -1, $B['meta']['queries_render_only'] ?? -1);
exit($diff > 0 ? 1 : 0);
