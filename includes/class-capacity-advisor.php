<?php
/**
 * Capacity advisor for WP Dynamic Tags.
 *
 * Every request registers every active tag as a shortcode, so what a tag costs is paid on every uncached page, in the
 * admin, in AJAX, in cron and on REST requests. The Settings page explains this and tells the site owner where the site
 * stands. The figures are the ones measured in Phase 9c (docs/BUG-REGISTER.md): about 2.05 MB and 11 ms per 1,000 active
 * tags on a development PC without a page cache; a shared host can be about twice as slow.
 *
 * Pure: no WordPress calls, so the root unit tests can run it. The page passes in what it can read (the tag count, the PHP
 * memory limit, whether a page cache is in front).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Capacity_Advisor
{
    /** Memory one thousand active tags add to every request. */
    public const MB_PER_1000_TAGS = 2.05;

    /** Time one thousand active tags add to an uncached page (registration plus WordPress's own shortcode scan). */
    public const MS_PER_1000_TAGS = 11;

    /** The most tags an entry shared host (one CPU core, about 1 GB of RAM) carries comfortably without a page cache. */
    public const SAFE_MAX = 5000;

    /** The same with a page cache in front: visitors then skip PHP, so only cache misses, the admin and AJAX pay. */
    public const SAFE_MAX_CACHED = 10000;

    /** Above the safe maximum and up to here is "watch"; above it is "high". */
    public const WATCH_MAX = 20000;

    /** The tags may take this share of the PHP memory limit before the memory limit becomes the concern. */
    private const TAG_MEMORY_SHARE = 0.25;

    /**
     * What a number of active tags adds to one request.
     *
     * @return array{memory_mb: float, extra_ms: int}
     */
    public static function estimate(int $tags): array
    {
        $thousands = max(0, $tags) / 1000;
        return array(
            'memory_mb' => round($thousands * self::MB_PER_1000_TAGS, 1),
            'extra_ms'  => (int) round($thousands * self::MS_PER_1000_TAGS),
        );
    }

    /**
     * Where a site stands.
     *
     * level: ok (up to the safe maximum), watch (up to WATCH_MAX, or the tags would take a quarter of the memory limit),
     * high (above WATCH_MAX). The memory limit can lower a level, never raise it. A limit of -1 (unlimited) or 0 (could not
     * be read) is ignored.
     *
     * @param int  $active_tags        Active tags: inactive ones are not loaded.
     * @param int  $memory_limit_bytes PHP memory limit in bytes; -1 unlimited, 0 unknown.
     * @param bool $page_cache         A page cache is in front of the site.
     * @return array{level: string, safe_max: int, reason: string} reason: '' (ok), 'count' or 'memory'
     */
    public static function assess(int $active_tags, int $memory_limit_bytes, bool $page_cache): array
    {
        $safe = $page_cache ? self::SAFE_MAX_CACHED : self::SAFE_MAX;
        $level = $active_tags <= $safe ? 'ok' : ($active_tags <= self::WATCH_MAX ? 'watch' : 'high');
        $reason = $level === 'ok' ? '' : 'count';

        if ($memory_limit_bytes > 0 && self::estimate($active_tags)['memory_mb'] * 1048576 > self::TAG_MEMORY_SHARE * $memory_limit_bytes) {
            $level = $level === 'high' ? 'high' : 'watch';
            $reason = 'memory';
        }

        return array('level' => $level, 'safe_max' => $safe, 'reason' => $reason);
    }

    /**
     * Recommended tag counts by kind of hosting, smallest first. Only the entry row rests on a measurement on a real host
     * plan's limits (Hostinger Single, 1 CPU core, 1 GB RAM); the others are scaled from it and are estimates.
     *
     * @return array<string, array{comfortable: ?int, max: int, max_cached: int, measured: bool}>
     */
    public static function tiers(): array
    {
        return array(
            'entry'     => array('comfortable' => 1000, 'max' => self::SAFE_MAX, 'max_cached' => self::SAFE_MAX_CACHED, 'measured' => true),
            'mid'       => array('comfortable' => 2500, 'max' => 10000, 'max_cached' => 20000, 'measured' => false),
            'vps'       => array('comfortable' => 5000, 'max' => 20000, 'max_cached' => 20000, 'measured' => false),
            'dedicated' => array('comfortable' => null, 'max' => 50000, 'max_cached' => 50000, 'measured' => false),
        );
    }

    /**
     * What was measured per uncached page (docs/BUG-REGISTER.md, Phase 9c): extra memory and extra time at a tag count.
     *
     * @return array<int, array{tags: int, memory_mb: int, extra_ms: int}>
     */
    public static function measured(): array
    {
        return array(
            array('tags' => 1000, 'memory_mb' => 2, 'extra_ms' => 10),
            array('tags' => 5000, 'memory_mb' => 10, 'extra_ms' => 55),
            array('tags' => 10000, 'memory_mb' => 20, 'extra_ms' => 110),
            array('tags' => 20000, 'memory_mb' => 40, 'extra_ms' => 210),
            array('tags' => 50000, 'memory_mb' => 106, 'extra_ms' => 550),
        );
    }
}
