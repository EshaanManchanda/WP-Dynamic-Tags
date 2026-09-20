<?php
/**
 * Unit tests for WP_Dynamic_Tags_Placeholders::trim_formatter_spec() and the
 * &lt;/&gt; operator-alias decoding in parse_condition_expression().
 *
 * Both fix real bugs found via live testing:
 * - A formatter spec like "join:; " had its whole string trimmed, silently
 *   destroying a meaningful trailing space in the separator argument.
 * - A literal "<" with no later ">" inside a [dt_if condition="..."]
 *   shortcode attribute confuses WordPress core's own shortcode-in-HTML-tag
 *   detection (do_shortcode() silently fails to match the shortcode at all -
 *   unrelated to this plugin, a WP core limitation) - &lt;/&gt; sidesteps it.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class FormatterSpecTrimTest extends TestCase {

    public function test_no_colon_trims_normally(): void {
        $this->assertSame('upper', WP_Dynamic_Tags_Placeholders::trim_formatter_spec(' upper '));
    }

    public function test_colon_arg_trailing_space_preserved(): void {
        $this->assertSame('join:; ', WP_Dynamic_Tags_Placeholders::trim_formatter_spec('join:; '));
    }

    public function test_colon_arg_leading_space_on_name_trimmed(): void {
        $this->assertSame('round:2', WP_Dynamic_Tags_Placeholders::trim_formatter_spec(' round :2'));
    }

    public function test_full_pipeline_preserves_separator_whitespace(): void {
        // Regression test for the exact bug: {token|join:; } must join with
        // "; " (semicolon-space), not "join:;" with the space stripped.
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('dt_verify_colors|join:; ');
        $this->assertSame(['join:; '], $parsed['formatters']);
    }

    public function test_lt_gt_entities_decoded_as_operators(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('wc:price&lt;50');
        $this->assertSame('comparison', $parsed['type']);
        $this->assertSame('<', $parsed['op']);
        $this->assertSame('50', $parsed['value']);

        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('wc:price&gt;50');
        $this->assertSame('>', $parsed['op']);
    }

    public function test_literal_lt_gt_still_work_for_non_shortcode_callers(): void {
        // {if:...} tokens aren't parsed via do_shortcode(), so a literal
        // "<"/">" works fine there - only the [dt_if] shortcode attribute
        // path needs the entity workaround.
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('wc:price<50');
        $this->assertSame('<', $parsed['op']);
        $this->assertSame('50', $parsed['value']);
    }
}
