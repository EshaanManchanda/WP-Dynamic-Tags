<?php
/**
 * L3 (hardcoded test data in the placeholder engine) and L5 (date, relative and currency formatters).
 */
class DT_Formatter_Test extends WP_UnitTestCase
{
    private function fmt(string $value, string $spec)
    {
        return WP_Dynamic_Tags_Formatters::format($value, $spec);
    }

    // ------------------------------------------------------------------ L3

    public function test_square_bracket_text_is_not_replaced_with_hardcoded_demo_values(): void
    {
        $engine = WP_Dynamic_Tags_Placeholders::get_instance();
        $this->assertSame('See [date1] and [date2] for [tagname].', $engine->process_placeholders('See [date1] and [date2] for [tagname].'));
    }

    // ------------------------------------------------------------------ L5

    public function test_date_formatter_returns_unparsable_input_unchanged(): void
    {
        $this->assertSame('not a date', $this->fmt('not a date', 'date:Y'));
        $this->assertSame('', $this->fmt('', 'date:Y'));
    }

    public function test_date_formatter_formats_a_wall_clock_string_as_written(): void
    {
        update_option('timezone_string', 'Asia/Kolkata');
        $this->assertSame('2026-11-11 10:00', $this->fmt('2026-11-11 10:00:00', 'date:Y-m-d H:i'));
    }

    public function test_date_formatter_converts_an_explicit_timezone_to_the_site_timezone(): void
    {
        update_option('timezone_string', 'Asia/Kolkata');   // UTC+5:30
        $this->assertSame('2026-11-11 15:30', $this->fmt('2026-11-11T10:00:00+00:00', 'date:Y-m-d H:i'));
    }

    public function test_relative_formatter_is_correct_for_a_value_with_an_explicit_timezone(): void
    {
        update_option('timezone_string', 'Asia/Kolkata');
        $two_hours_ago_utc = gmdate('c', time() - 2 * HOUR_IN_SECONDS);
        $this->assertSame('2 hours ago', $this->fmt($two_hours_ago_utc, 'relative'), 'UTC and site-offset timestamps used to be mixed');
    }

    public function test_relative_formatter_returns_unparsable_input_unchanged(): void
    {
        $this->assertSame('soon-ish', $this->fmt('soon-ish', 'relative'));
    }

    public function test_currency_symbol_can_be_changed_with_a_filter(): void
    {
        $this->assertSame('$1,234.50', $this->fmt('1234.5', 'currency'));
        add_filter('dt_currency_symbol', static fn() => '€');
        $this->assertSame('€1,234.50', $this->fmt('1234.5', 'currency'));
        remove_all_filters('dt_currency_symbol');
    }

    public function test_number_formatters_follow_the_site_locale_separators(): void
    {
        add_filter('number_format_i18n', static fn($formatted) => $formatted);   // en_US default: identical to number_format
        $this->assertSame('1,234.57', $this->fmt('1234.567', 'decimal:2'));
        $this->assertSame('1,235', $this->fmt('1234.567', 'thousands'));
    }
}
