<?php
/**
 * Compare two golden-master result sets.
 *
 *   php diff.php <dirA> <pathA> <dirB> <pathB> <ctx> [--all]
 *   php diff.php baseline post baseline table in_singular      # how the two legacy paths differ
 *   php diff.php baseline post current unified in_singular     # post-origin tags, before vs after
 *
 * Without --all the attribute variants (format=, default=, cache=, processing=) are skipped.
 * Exit code 1 when any output differs, so it can gate a release.
 */
[$s, $dirA, $pa, $dirB, $pb, $ctx] = $argv + [null, 'baseline', 'post', 'baseline', 'table', 'out_singular'];
$all = in_array('--all', $argv, true);
$A = json_decode(file_get_contents(__DIR__ . "/$dirA/{$pa}__{$ctx}.json"), true);
$B = json_decode(file_get_contents(__DIR__ . "/$dirB/{$pb}__{$ctx}.json"), true);

$same = 0;
$diff = 0;
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
    printf("%s\n   %-8s %s\n   %-8s %s\n", $inv, $pa, json_encode($va, JSON_UNESCAPED_SLASHES), $pb, json_encode($vb, JSON_UNESCAPED_SLASHES));
}
printf("== %s: identical=%d different=%d (queries during render: A=%d B=%d)\n", $ctx, $same, $diff, $A['meta']['queries_render_only'] ?? -1, $B['meta']['queries_render_only'] ?? -1);
exit($diff > 0 ? 1 : 0);
