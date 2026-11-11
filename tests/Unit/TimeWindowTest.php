<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-table-shortcode-processor.php';

/**
 * L2: {if:time_between(HH:MM,HH:MM)} windows. The old comparison of 'H:i' strings could never be true for a window that
 * runs through midnight.
 */
class TimeWindowTest extends TestCase
{
    private static function m(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    /** @dataProvider windows */
    public function test_time_in_window(string $now, string $start, string $end, bool $expected): void
    {
        $this->assertSame($expected, WP_Dynamic_Tags_Table_Shortcode_Processor::time_in_window(self::m($now), self::m($start), self::m($end)));
    }

    public static function windows(): array
    {
        return [
            'inside a daytime window'          => ['12:00', '09:00', '17:00', true],
            'window start is inclusive'        => ['09:00', '09:00', '17:00', true],
            'window end is inclusive'          => ['17:00', '09:00', '17:00', true],
            'before a daytime window'          => ['08:59', '09:00', '17:00', false],
            'after a daytime window'           => ['17:01', '09:00', '17:00', false],
            'late evening in a night window'   => ['23:30', '22:00', '06:00', true],
            'early morning in a night window'  => ['03:00', '22:00', '06:00', true],
            'midday outside a night window'    => ['12:00', '22:00', '06:00', false],
            'night window start is inclusive'  => ['22:00', '22:00', '06:00', true],
            'night window end is inclusive'    => ['06:00', '22:00', '06:00', true],
            'just after a night window'        => ['06:01', '22:00', '06:00', false],
            'single minute window'             => ['10:15', '10:15', '10:15', true],
            'single minute window, other time' => ['10:16', '10:15', '10:15', false],
        ];
    }
}
