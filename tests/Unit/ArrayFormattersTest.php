<?php
/**
 * Unit tests for the array-aware formatters (join, count, first, last)
 * added in WP_Dynamic_Tags_Formatters for V2 array/repeater support.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';

class ArrayFormattersTest extends TestCase {

    public function test_is_array_aware(): void {
        $this->assertTrue(WP_Dynamic_Tags_Formatters::is_array_aware('join'));
        $this->assertTrue(WP_Dynamic_Tags_Formatters::is_array_aware('count'));
        $this->assertTrue(WP_Dynamic_Tags_Formatters::is_array_aware('first'));
        $this->assertTrue(WP_Dynamic_Tags_Formatters::is_array_aware('last'));
        $this->assertFalse(WP_Dynamic_Tags_Formatters::is_array_aware('upper'));
    }

    public function test_join_default_separator(): void {
        $result = WP_Dynamic_Tags_Formatters::format(['Red', 'Green', 'Blue'], 'join');
        $this->assertSame('Red, Green, Blue', $result);
    }

    public function test_join_custom_separator(): void {
        $result = WP_Dynamic_Tags_Formatters::format(['Red', 'Green'], 'join: | ');
        $this->assertSame('Red | Green', $result);
    }

    public function test_join_scalarizes_nested_rows(): void {
        $rows = [
            ['name' => 'Alice', 'role' => 'Lead'],
            ['name' => 'Bob', 'role' => 'Dev'],
        ];
        $result = WP_Dynamic_Tags_Formatters::format($rows, 'join:; ');
        $this->assertSame('Alice Lead; Bob Dev', $result);
    }

    public function test_count_array(): void {
        $this->assertSame('3', WP_Dynamic_Tags_Formatters::format(['a', 'b', 'c'], 'count'));
        $this->assertSame('0', WP_Dynamic_Tags_Formatters::format([], 'count'));
    }

    public function test_count_scalar(): void {
        $this->assertSame('1', WP_Dynamic_Tags_Formatters::format('hello', 'count'));
        $this->assertSame('0', WP_Dynamic_Tags_Formatters::format('', 'count'));
    }

    public function test_first_and_last(): void {
        $this->assertSame('a', WP_Dynamic_Tags_Formatters::format(['a', 'b', 'c'], 'first'));
        $this->assertSame('c', WP_Dynamic_Tags_Formatters::format(['a', 'b', 'c'], 'last'));
    }

    public function test_first_and_last_on_scalar_are_passthrough(): void {
        $this->assertSame('solo', WP_Dynamic_Tags_Formatters::format('solo', 'first'));
        $this->assertSame('solo', WP_Dynamic_Tags_Formatters::format('solo', 'last'));
    }

    public function test_first_on_empty_array(): void {
        $this->assertSame('', WP_Dynamic_Tags_Formatters::format([], 'first'));
        $this->assertSame('', WP_Dynamic_Tags_Formatters::format([], 'last'));
    }
}
