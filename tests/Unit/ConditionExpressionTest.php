<?php
/**
 * Unit tests for the V3 conditional-visibility parsing/comparison logic:
 * WP_Dynamic_Tags_Placeholders::parse_condition_expression() and
 * ::compare_values(). Both are pure (no WordPress calls), same convention as
 * parse_token_body() - the WP-dependent glue (evaluate_field_condition(),
 * which calls resolve_token()) is verified live instead.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class ConditionExpressionTest extends TestCase {

    public function test_bare_truthy_flat_key(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('post_featured_image');
        $this->assertSame('truthy', $parsed['type']);
        $this->assertSame('post_featured_image', $parsed['key']);
        $this->assertNull($parsed['arg']);
    }

    public function test_bare_truthy_prefixed_key(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('acf:featured');
        $this->assertSame('truthy', $parsed['type']);
        $this->assertSame('acf', $parsed['key']);
        $this->assertSame('featured', $parsed['arg']);
    }

    public function test_equals_comparison(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('wc:stock_status=instock');
        $this->assertSame('comparison', $parsed['type']);
        $this->assertSame('wc', $parsed['key']);
        $this->assertSame('stock_status', $parsed['arg']);
        $this->assertSame('=', $parsed['op']);
        $this->assertSame('instock', $parsed['value']);
    }

    public function test_greater_than_or_equal_not_split_as_greater_than(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('wc:price>=50');
        $this->assertSame('>=', $parsed['op']);
        $this->assertSame('50', $parsed['value']);
    }

    public function test_not_equal(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('meta:status!=draft');
        $this->assertSame('!=', $parsed['op']);
    }

    public function test_contains(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_condition_expression('post_title contains Sale');
        $this->assertSame('comparison', $parsed['type']);
        $this->assertSame('post_title', $parsed['key']);
        $this->assertSame('contains', $parsed['op']);
        $this->assertSame('Sale', $parsed['value']);
    }

    public function test_compare_values_numeric(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::compare_values('75', '>', '50'));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::compare_values('25', '>', '50'));
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::compare_values('50', '>=', '50'));
    }

    public function test_compare_values_string_equality(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::compare_values('instock', '=', 'instock'));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::compare_values('outofstock', '=', 'instock'));
    }

    public function test_compare_values_contains(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::compare_values('Big Sale Event', 'contains', 'sale'));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::compare_values('Regular Post', 'contains', 'sale'));
    }

    public function test_compare_values_array_uses_count_for_numeric_ops(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::compare_values(['a', 'b', 'c'], '>', '2'));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::compare_values(['a'], '>', '2'));
    }

    public function test_compare_values_array_joins_for_equality(): void {
        $this->assertSame(
            true,
            WP_Dynamic_Tags_Placeholders::compare_values(['Red', 'Green'], '=', 'Red, Green')
        );
    }

    public function test_is_truthy(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_truthy('yes'));
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_truthy(['x']));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_truthy(''));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_truthy('0'));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_truthy(0));
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_truthy([]));
    }
}
