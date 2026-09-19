<?php
/**
 * Example unit test for WP Dynamic Tags.
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

class DynamicTagTest extends TestCase {

    public function test_tag_name_is_sanitized(): void {
        $raw_name       = 'my tag name!!';
        $sanitized_name = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $raw_name ) );

        $this->assertMatchesRegularExpression( '/^[a-z0-9_-]*$/', $sanitized_name );
    }

    public function test_shortcode_output_is_escaped(): void {
        $dynamic_value = '<b>Hello & World</b>';
        $escaped       = htmlspecialchars( $dynamic_value, ENT_QUOTES, 'UTF-8' );

        $this->assertStringNotContainsString( '<b>', $escaped );
        $this->assertStringContainsString( '&lt;b&gt;', $escaped );
    }

    public function test_missing_tag_returns_empty_string(): void {
        $tag_value = null;
        $output    = $tag_value ?? '';

        $this->assertSame( '', $output );
    }
}
