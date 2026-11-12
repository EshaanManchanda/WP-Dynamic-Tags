<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-conditional-parser.php';

/**
 * L1: {if}/{else}/{/if} block matching. The "regex_*" cases are the exact inputs the regular-expression version got
 * wrong; "kept_*" cases document behavior of well-formed content that must not change.
 */
class ConditionalParserTest extends TestCase
{
    /** Evaluates conditions from a fixed table; unknown conditions are false. */
    private function run_parser(string $content, array $truth = array(), ?array &$asked = null): string
    {
        $asked = array();
        return WP_Dynamic_Tags_Conditional_Parser::process($content, static function (string $c) use ($truth, &$asked): bool {
            $asked[] = $c;
            return $truth[$c] ?? false;
        });
    }

    public function test_content_without_blocks_is_returned_untouched(): void
    {
        $this->assertSame('plain {site_name} text', $this->run_parser('plain {site_name} text'));
        $this->assertSame('', $this->run_parser(''));
    }

    public function test_kept_simple_block_true_and_false(): void
    {
        $this->assertSame('AINB', $this->run_parser('A{if:in}IN{/if}B', array('in' => true)));
        $this->assertSame('AB', $this->run_parser('A{if:in}IN{/if}B', array('in' => false)));
    }

    public function test_kept_else_block(): void
    {
        $this->assertSame('IN', $this->run_parser('{if:in}IN{else}OUT{/if}', array('in' => true)));
        $this->assertSame('OUT', $this->run_parser('{if:in}IN{else}OUT{/if}', array('in' => false)));
    }

    public function test_kept_two_blocks_of_the_same_kind_in_a_row(): void
    {
        $this->assertSame('1-3', $this->run_parser('{if:a}1{/if}-{if:b}2{/if}{if:c}3{/if}', array('a' => true, 'c' => true)));
        $this->assertSame('1-no', $this->run_parser('{if:a}1{else}x{/if}-{if:b}2{else}no{/if}', array('a' => true)));
    }

    public function test_kept_multiline_content_and_untrimmed_condition(): void
    {
        $asked = null;
        $out = $this->run_parser("{if: spaced }\nline1\nline2{/if}", array(' spaced ' => true), $asked);
        $this->assertSame("\nline1\nline2", $out);
        $this->assertSame(array(' spaced '), $asked, 'the condition is passed to the callback exactly as written');
    }

    public function test_regex_a_simple_block_followed_by_an_else_block_is_not_merged(): void
    {
        $in = 'A{if:x}shown{/if} middle {if:y}yes{else}no{/if} end';
        $this->assertSame('Ashown middle no end', $this->run_parser($in, array('x' => true, 'y' => false)));
        $this->assertSame('A middle yes end', $this->run_parser($in, array('x' => false, 'y' => true)));
    }

    public function test_regex_the_old_output_leaked_raw_tags(): void
    {
        $out = $this->run_parser('A{if:x}shown{/if} middle {if:y}yes{else}no{/if} end', array('x' => true, 'y' => true));
        $this->assertStringNotContainsString('{/if}', $out);
        $this->assertStringNotContainsString('{if:', $out);
        $this->assertStringNotContainsString('{else}', $out);
    }

    public function test_nested_blocks(): void
    {
        $in = '{if:a}1{if:b}2{else}3{/if}4{/if}';
        $this->assertSame('124', $this->run_parser($in, array('a' => true, 'b' => true)));
        $this->assertSame('134', $this->run_parser($in, array('a' => true, 'b' => false)));
        $this->assertSame('', $this->run_parser($in, array('a' => false, 'b' => true)));
    }

    public function test_nested_block_in_the_else_branch(): void
    {
        $in = '{if:a}A{else}{if:b}B{else}C{/if}{/if}';
        $this->assertSame('A', $this->run_parser($in, array('a' => true)));
        $this->assertSame('B', $this->run_parser($in, array('b' => true)));
        $this->assertSame('C', $this->run_parser($in));
    }

    public function test_only_the_chosen_branch_is_evaluated(): void
    {
        $asked = null;
        $this->run_parser('{if:a}{if:inner_true}1{/if}{else}{if:inner_false}2{/if}{/if}', array('a' => true), $asked);
        $this->assertSame(array('a', 'inner_true'), $asked);
    }

    public function test_a_second_else_is_plain_text_of_the_false_branch(): void
    {
        $this->assertSame('B{else}C', $this->run_parser('{if:a}A{else}B{else}C{/if}'));
        $this->assertSame('A', $this->run_parser('{if:a}A{else}B{else}C{/if}', array('a' => true)));
    }

    public function test_an_unclosed_block_stays_literal(): void
    {
        $this->assertSame('x {if:a} never closed', $this->run_parser('x {if:a} never closed', array('a' => true)));
    }

    public function test_a_closed_block_inside_an_unclosed_one_is_still_evaluated(): void
    {
        $this->assertSame('{if:outer} inner|', $this->run_parser('{if:outer} {if:i}inner{/if}|', array('i' => true, 'outer' => true)));
    }

    public function test_stray_closing_tag_and_stray_else_stay_literal(): void
    {
        $this->assertSame('a{/if}b', $this->run_parser('a{/if}b'));
        $this->assertSame('a{else}b', $this->run_parser('a{else}b'));
        $this->assertSame('{/if}1', $this->run_parser('{/if}{if:a}1{/if}', array('a' => true)));
    }

    public function test_a_condition_cannot_contain_a_closing_brace(): void
    {
        $this->assertSame('{if:a', $this->run_parser('{if:a'));
    }

    public function test_hostile_nesting_depth_is_bounded_and_does_not_crash(): void
    {
        $n = WP_Dynamic_Tags_Conditional_Parser::MAX_DEPTH + 40;
        $in = str_repeat('{if:a}', $n) . 'core' . str_repeat('{/if}', $n);
        $out = $this->run_parser($in, array('a' => true));
        $this->assertStringContainsString('core', $out);
        $this->assertIsString($out);
    }

    public function test_malformed_soup_never_fails(): void
    {
        foreach (array('{if:}{else}{/if}', '{{if:a}}', '{if:a}{if:b}{/if}', '{else}{else}{/if}{/if}', "{if:\n}x{/if}", '{if:a}{else}', '{/if}{if:a}') as $soup) {
            $this->assertIsString($this->run_parser($soup, array('a' => true, 'b' => true)));
        }
    }
}
