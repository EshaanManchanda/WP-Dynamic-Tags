<?php
/**
 * Parser for the {if:condition}...{else}...{/if} blocks of tag content.
 *
 * Pure: no WordPress calls, so it is unit tested without WordPress and fuzzed. The condition itself is decided by
 * the caller through a callback; this class only finds the blocks and chooses the branch.
 *
 * It replaces two regular expressions that paired an opening tag with the nearest `{else}` anywhere after it (L1):
 *
 *   A{if:x}shown{/if} middle {if:y}yes{else}no{/if} end
 *
 * was parsed as ONE block (condition x, true branch "shown{/if} middle {if:y}yes", false branch "no") and leaked raw
 * `{/if}` and `{if:y}` into the page. Blocks are now matched with a stack, so sequential and nested blocks work.
 *
 * Rules (they keep the behavior of well-formed content from the regex version):
 *  - the condition is everything up to the next `}` (so it cannot contain `}`), passed to the callback untrimmed;
 *  - the first `{else}` of a block starts its false branch; further `{else}` inside the block are plain text;
 *  - an `{if:...}` that is never closed, a stray `{/if}` and an `{else}` outside any block stay as literal text,
 *    while complete blocks inside an unclosed one are still evaluated;
 *  - only the chosen branch is evaluated, so conditions inside the other branch are never asked for;
 *  - nesting deeper than MAX_DEPTH is treated as literal text (protection against hostile content).
 */
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Conditional_Parser
{
    public const MAX_DEPTH = 32;

    /**
     * @param string                $content  Tag content, possibly containing {if:...} blocks.
     * @param callable(string):bool $evaluate Decides a condition, receives the text between "{if:" and "}".
     */
    public static function process(string $content, callable $evaluate): string
    {
        if (strpos($content, '{if:') === false) {
            return $content;
        }
        if (!preg_match_all('/\{if:([^}]+)\}|\{else\}|\{\/if\}/', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $root = array();        // parts of the outermost level: literal strings and closed blocks
        $stack = array();       // open blocks, innermost last
        $cursor = 0;

        foreach ($matches as $set) {
            $text = $set[0][0];
            $pos = $set[0][1];

            // Literal text before this tag goes to whatever branch is currently collecting.
            if ($pos > $cursor) {
                self::append($root, $stack, substr($content, $cursor, $pos - $cursor));
            }
            $cursor = $pos + strlen($text);

            if ($text === '{else}') {
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['false'] === null) {
                    $stack[$top]['false'] = array();
                } else {
                    self::append($root, $stack, $text);
                }
            } elseif ($text === '{/if}') {
                if ($stack) {
                    $block = array_pop($stack);
                    self::append($root, $stack, $block);
                } else {
                    self::append($root, $stack, $text);
                }
            } elseif (count($stack) >= self::MAX_DEPTH) {
                self::append($root, $stack, $text);
            } else {
                $stack[] = array('cond' => $set[1][0], 'open' => $text, 'true' => array(), 'false' => null);
            }
        }

        if ($cursor < strlen($content)) {
            self::append($root, $stack, substr($content, $cursor));
        }

        // Blocks that were never closed become literal text again: their opening tag, branches and {else} are emitted
        // as plain parts so the closed blocks inside them are still evaluated.
        while ($stack) {
            $block = array_pop($stack);
            $flat = array_merge(array($block['open']), $block['true']);
            if ($block['false'] !== null) {
                $flat = array_merge($flat, array('{else}'), $block['false']);
            }
            foreach ($flat as $part) {
                self::append($root, $stack, $part);
            }
        }

        return self::render($root, $evaluate);
    }

    /**
     * Appends a literal string or a closed block to the branch that is currently collecting.
     *
     * @param array<int, string|array<string, mixed>> $root
     * @param array<int, array<string, mixed>>        $stack
     * @param string|array<string, mixed>             $part
     */
    private static function append(array &$root, array &$stack, $part): void
    {
        $top = count($stack) - 1;
        if ($top < 0) {
            $root[] = $part;
        } elseif ($stack[$top]['false'] === null) {
            $stack[$top]['true'][] = $part;
        } else {
            $stack[$top]['false'][] = $part;
        }
    }

    /**
     * @param array<int, string|array<string, mixed>> $parts
     */
    private static function render(array $parts, callable $evaluate): string
    {
        $out = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }
            $branch = $evaluate($part['cond']) ? $part['true'] : ($part['false'] ?? array());
            $out .= self::render($branch, $evaluate);
        }
        return $out;
    }
}
