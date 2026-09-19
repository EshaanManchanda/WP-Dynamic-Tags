<?php
/**
 * Unit tests for WP_Dynamic_Tags_Placeholders::parse_token_body().
 *
 * Run: composer test
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-dynamic-formatters.php';
require_once __DIR__ . '/../../includes/class-dynamic-placeholders.php';

class TokenParserTest extends TestCase {

    public function test_flat_key(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('post_title');

        $this->assertSame('post_title', $parsed['key']);
        $this->assertNull($parsed['arg']);
        $this->assertSame([], $parsed['formatters']);
        $this->assertNull($parsed['fallback']);
    }

    public function test_key_with_argument(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('meta:price');

        $this->assertSame('meta', $parsed['key']);
        $this->assertSame('price', $parsed['arg']);
    }

    public function test_key_with_formatter(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('post_title|upper');

        $this->assertSame('post_title', $parsed['key']);
        $this->assertNull($parsed['arg']);
        $this->assertSame(['upper'], $parsed['formatters']);
    }

    public function test_key_arg_formatters_and_fallback(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('meta:price|round:2|currency??Not set');

        $this->assertSame('meta', $parsed['key']);
        $this->assertSame('price', $parsed['arg']);
        $this->assertSame(['round:2', 'currency'], $parsed['formatters']);
        $this->assertSame('Not set', $parsed['fallback']);
    }

    public function test_fallback_only(): void {
        $parsed = WP_Dynamic_Tags_Placeholders::parse_token_body('user_email??no email on file');

        $this->assertSame('user_email', $parsed['key']);
        $this->assertSame('no email on file', $parsed['fallback']);
    }
}
