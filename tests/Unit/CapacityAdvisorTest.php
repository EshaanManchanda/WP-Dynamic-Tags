<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-capacity-advisor.php';

/**
 * The Settings page tells a site owner how many tags the site can carry. The numbers come from the Phase 9c measurements
 * (about 2.05 MB and 11 ms per 1,000 active tags on every uncached request); this class turns a tag count, the PHP memory
 * limit and the presence of a page cache into a level. Pure: no WordPress calls.
 */
class CapacityAdvisorTest extends TestCase
{
    private const MB = 1048576;

    public function test_the_estimate_grows_with_the_tag_count_and_is_zero_for_none(): void
    {
        $this->assertSame(array('memory_mb' => 0.0, 'extra_ms' => 0), WP_Dynamic_Tags_Capacity_Advisor::estimate(0));
        $this->assertSame(array('memory_mb' => 0.0, 'extra_ms' => 0), WP_Dynamic_Tags_Capacity_Advisor::estimate(-5000), 'a negative count is treated as none (-5 would round to zero anyway)');
        $this->assertSame(array('memory_mb' => 10.3, 'extra_ms' => 55), WP_Dynamic_Tags_Capacity_Advisor::estimate(5000));
        $this->assertSame(array('memory_mb' => 102.5, 'extra_ms' => 550), WP_Dynamic_Tags_Capacity_Advisor::estimate(50000));
    }

    /** @return array<string, array{0:int,1:bool,2:string}> */
    public static function levels(): array
    {
        return array(
            'no tags'                       => array(0, false, 'ok'),
            'a typical site'                => array(20, false, 'ok'),
            'at the safe maximum'           => array(5000, false, 'ok'),
            'one over the safe maximum'     => array(5001, false, 'watch'),
            'cached, at its maximum'        => array(10000, true, 'ok'),
            'cached, one over'              => array(10001, true, 'watch'),
            'at the watch ceiling'          => array(20000, false, 'watch'),
            'one over the watch ceiling'    => array(20001, false, 'high'),
            'cached does not rescue 20,001' => array(20001, true, 'high'),
            'fifty thousand'                => array(50000, true, 'high'),
        );
    }

    /** @dataProvider levels */
    public function test_the_level_follows_the_count_and_the_page_cache(int $tags, bool $cache, string $expected): void
    {
        $result = WP_Dynamic_Tags_Capacity_Advisor::assess($tags, -1, $cache);
        $this->assertSame($expected, $result['level']);
        $this->assertSame($cache ? 10000 : 5000, $result['safe_max']);
    }

    public function test_the_reason_says_why_the_level_is_not_ok(): void
    {
        $this->assertSame('', WP_Dynamic_Tags_Capacity_Advisor::assess(100, -1, false)['reason']);
        $this->assertSame('count', WP_Dynamic_Tags_Capacity_Advisor::assess(8000, -1, false)['reason']);
    }

    public function test_a_small_memory_limit_lowers_the_level_when_the_tags_would_take_a_quarter_of_it(): void
    {
        // 5,000 tags take about 10.3 MB: more than a quarter of 32 MB, less than a quarter of 64 MB
        $small = WP_Dynamic_Tags_Capacity_Advisor::assess(5000, 32 * self::MB, false);
        $this->assertSame('watch', $small['level']);
        $this->assertSame('memory', $small['reason']);

        $roomy = WP_Dynamic_Tags_Capacity_Advisor::assess(5000, 64 * self::MB, false);
        $this->assertSame('ok', $roomy['level']);

        // WordPress's own front-end minimum of 40 MB is fine for an ordinary tag count
        $this->assertSame('ok', WP_Dynamic_Tags_Capacity_Advisor::assess(20, 40 * self::MB, false)['level']);
        $this->assertSame('ok', WP_Dynamic_Tags_Capacity_Advisor::assess(1000, 40 * self::MB, false)['level']);
    }

    public function test_an_unlimited_or_unknown_memory_limit_never_lowers_the_level(): void
    {
        $this->assertSame('ok', WP_Dynamic_Tags_Capacity_Advisor::assess(5000, -1, false)['level'], '-1 is unlimited');
        $this->assertSame('ok', WP_Dynamic_Tags_Capacity_Advisor::assess(5000, 0, false)['level'], '0 means the limit could not be read');
    }

    public function test_memory_never_improves_a_level_the_count_has_already_lowered(): void
    {
        $this->assertSame('high', WP_Dynamic_Tags_Capacity_Advisor::assess(30000, 4096 * self::MB, true)['level']);
        $this->assertSame('high', WP_Dynamic_Tags_Capacity_Advisor::assess(30000, 32 * self::MB, true)['level']);
    }

    public function test_the_level_never_improves_as_the_count_grows(): void
    {
        $rank = array('ok' => 0, 'watch' => 1, 'high' => 2);
        foreach (array(false, true) as $cache) {
            $previous = 0;
            foreach (array(0, 1, 100, 1000, 4999, 5000, 5001, 9999, 10000, 10001, 19999, 20000, 20001, 50000, 200000) as $tags) {
                $now = $rank[WP_Dynamic_Tags_Capacity_Advisor::assess($tags, -1, $cache)['level']];
                $this->assertGreaterThanOrEqual($previous, $now, "level at $tags tags (cache: " . ($cache ? 'yes' : 'no') . ')');
                $previous = $now;
            }
        }
    }

    public function test_the_hosting_table_agrees_with_the_levels_and_rises_with_the_hosting(): void
    {
        $tiers = WP_Dynamic_Tags_Capacity_Advisor::tiers();
        $this->assertSame(array('entry', 'mid', 'vps', 'dedicated'), array_keys($tiers));

        $entry = $tiers['entry'];
        $this->assertSame(5000, $entry['max'], 'the first row is the safe maximum without a page cache');
        $this->assertSame(10000, $entry['max_cached'], 'and with one');
        $this->assertTrue($entry['measured'], 'only the first row is backed by a measurement on a real host plan');

        $previous = 0;
        foreach ($tiers as $key => $tier) {
            $this->assertGreaterThan($previous, $tier['max'], "$key allows more than the row above");
            $this->assertGreaterThanOrEqual($tier['max'], $tier['max_cached'] ?? $tier['max'], "$key: a page cache never lowers the maximum");
            if ($key !== 'entry') {
                $this->assertFalse($tier['measured'], "$key is an estimate");
            }
            $previous = $tier['max'];
        }
    }

    public function test_the_measured_table_is_ordered_and_matches_the_estimate_within_a_few_percent(): void // 8% memory, 12% time
    {
        $rows = WP_Dynamic_Tags_Capacity_Advisor::measured();
        $this->assertSame(array(1000, 5000, 10000, 20000, 50000), array_column($rows, 'tags'));
        $previous = array('memory_mb' => 0, 'extra_ms' => 0);
        foreach ($rows as $row) {
            $estimate = WP_Dynamic_Tags_Capacity_Advisor::estimate($row['tags']);
            // the estimate is rounded to 0.1 MB (2.05 shows as 2.1), so a delta of exactly 5% would fail on float noise
            $this->assertEqualsWithDelta($row['memory_mb'], $estimate['memory_mb'], $row['memory_mb'] * 0.08, 'memory at ' . $row['tags']);
            $this->assertEqualsWithDelta($row['extra_ms'], $estimate['extra_ms'], $row['extra_ms'] * 0.12, 'time at ' . $row['tags']);
            $this->assertGreaterThan($previous['memory_mb'], $row['memory_mb']);
            $this->assertGreaterThan($previous['extra_ms'], $row['extra_ms']);
            $previous = $row;
        }
    }
}
