<?php
/**
 * Unit tests for WP_Dynamic_Tags_Placeholders::walk_path(), the dot-notation
 * JSON path walker used by {api:URL::path}.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class WalkPathTest extends TestCase {

    private $data = [
        'rates' => ['usd' => 1.08, 'eur' => 1.0],
        'meta' => ['count' => 2],
    ];

    public function test_no_path_returns_whole_payload(): void {
        $this->assertSame($this->data, WP_Dynamic_Tags_Placeholders::walk_path($this->data, null));
        $this->assertSame($this->data, WP_Dynamic_Tags_Placeholders::walk_path($this->data, ''));
    }

    public function test_single_segment(): void {
        $this->assertSame(['usd' => 1.08, 'eur' => 1.0], WP_Dynamic_Tags_Placeholders::walk_path($this->data, 'rates'));
    }

    public function test_nested_segment(): void {
        $this->assertSame(1.08, WP_Dynamic_Tags_Placeholders::walk_path($this->data, 'rates.usd'));
    }

    public function test_missing_segment_returns_empty_string(): void {
        $this->assertSame('', WP_Dynamic_Tags_Placeholders::walk_path($this->data, 'rates.gbp'));
    }

    public function test_path_into_scalar_returns_empty_string(): void {
        $this->assertSame('', WP_Dynamic_Tags_Placeholders::walk_path($this->data, 'meta.count.nonexistent'));
    }
}
