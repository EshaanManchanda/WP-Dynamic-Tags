<?php
/**
 * Unit tests for WP_Dynamic_Tags_Placeholders::is_empty_value(), the
 * fallback-emptiness check extended in V2 to treat an empty array (a getter
 * returning no rows) the same as '' or null.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class EmptyValueTest extends TestCase {

    public function test_empty_string_is_empty(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_empty_value(''));
    }

    public function test_null_is_empty(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_empty_value(null));
    }

    public function test_empty_array_is_empty(): void {
        $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_empty_value([]));
    }

    public function test_zero_string_is_not_empty(): void {
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_empty_value('0'));
    }

    public function test_zero_int_is_not_empty(): void {
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_empty_value(0));
    }

    public function test_non_empty_array_is_not_empty(): void {
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_empty_value(['x']));
    }

    public function test_non_empty_string_is_not_empty(): void {
        $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_empty_value('text'));
    }
}
