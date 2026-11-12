<?php
/**
 * The pure parts of the Free and Paid plans, tested without WordPress (so tools/mutation/mutants.php can break them quickly):
 *
 *  - Tag_Service::normalize_meta(): the origin mark that keeps a tag out of the Free limit can be set only for a tag another plugin
 *    creates, never chosen by a caller, an import file or an update, whatever shape the meta data arrives in
 *  - DT_License_Manager::paid_features_in(), is_gated_prefix(), mask_key()
 *  - Placeholders::is_builtin_condition()
 *
 * The WordPress behaviour built on them (the limit, the gates, the admin) is in tests/wp (LicenseGatingTest, TagLimitTest, ...).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-database-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-dynamic-formatters.php';
require_once dirname(__DIR__, 2) . '/includes/class-dynamic-placeholders.php';
require_once dirname(__DIR__, 2) . '/includes/class-tag-service.php';
require_once dirname(__DIR__, 2) . '/includes/class-license-manager.php';

class PlanHelpersTest extends TestCase
{
    private const MARK = array('dt_origin' => 'api');

    protected function setUp(): void
    {
        new WP_Dynamic_Tags_Placeholders(); // paid_features_in() asks the registry, which exists once the class has been constructed
    }

    // ------------------------------------------------------------------ the origin mark

    public function test_a_plugin_tag_gets_the_mark_and_keeps_its_other_meta(): void
    {
        $this->assertSame(array('source' => 'certificates', 'dt_origin' => 'api'), WP_Dynamic_Tags_Tag_Service::normalize_meta(array('source' => 'certificates'), true));
        $this->assertSame(self::MARK, WP_Dynamic_Tags_Tag_Service::normalize_meta(array(), true));
        $this->assertSame(self::MARK, WP_Dynamic_Tags_Tag_Service::normalize_meta('', true));
        $this->assertSame(self::MARK, WP_Dynamic_Tags_Tag_Service::normalize_meta(null, true));
    }

    public function test_a_claimed_mark_is_removed_for_a_person_and_set_again_only_for_a_plugin(): void
    {
        $claim = array('k' => 'v', 'dt_origin' => 'api');
        $this->assertSame(array('k' => 'v'), WP_Dynamic_Tags_Tag_Service::normalize_meta($claim, false));
        $this->assertSame(array('k' => 'v', 'dt_origin' => 'api'), WP_Dynamic_Tags_Tag_Service::normalize_meta(array('dt_origin' => 'anything', 'k' => 'v'), true), 'the value is the plugin\'s, not the caller\'s, and it is last');
    }

    public function test_the_mark_is_found_inside_json_and_serialized_meta(): void
    {
        $this->assertSame(array('k' => 'v'), WP_Dynamic_Tags_Tag_Service::normalize_meta('{"dt_origin":"api","k":"v"}', false));
        $this->assertSame(array('k' => 'v'), WP_Dynamic_Tags_Tag_Service::normalize_meta(serialize(array('dt_origin' => 'api', 'k' => 'v')), false));
        $this->assertSame(array('k' => 'v', 'dt_origin' => 'api'), WP_Dynamic_Tags_Tag_Service::normalize_meta('{"k":"v"}', true));
    }

    public function test_empty_meta_for_a_person_stays_empty(): void
    {
        $this->assertSame('', WP_Dynamic_Tags_Tag_Service::normalize_meta('', false));
        $this->assertSame('', WP_Dynamic_Tags_Tag_Service::normalize_meta(null, false));
        $this->assertSame(array(), WP_Dynamic_Tags_Tag_Service::normalize_meta(array(), false));
    }

    public function test_text_that_mentions_the_mark_but_is_not_an_array_is_dropped(): void
    {
        $this->assertSame('', WP_Dynamic_Tags_Tag_Service::normalize_meta('x"dt_origin";s:3:"api";', false));
        $this->assertSame('', WP_Dynamic_Tags_Tag_Service::normalize_meta('x"dt_origin";s:3:"api";', true), 'a plugin tag cannot carry it either: the mark is only ever written as an array');
        $this->assertSame('just a note', WP_Dynamic_Tags_Tag_Service::normalize_meta('just a note', false), 'other text is left alone');
        $this->assertSame(5, WP_Dynamic_Tags_Tag_Service::normalize_meta(5, false));
    }

    public function test_a_serialized_object_never_comes_back_as_one(): void
    {
        $this->assertSame(array(), WP_Dynamic_Tags_Tag_Service::normalize_meta(serialize(new stdClass()), false));
        $this->assertSame(self::MARK, WP_Dynamic_Tags_Tag_Service::normalize_meta(serialize(new stdClass()), true));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function hostile_meta(): iterable
    {
        yield 'api claim in json' => array('{"dt_origin":"api"}');
        yield 'api claim in serialized' => array('a:1:{s:9:"dt_origin";s:3:"api";}');
        yield 'nested' => array(array('a' => array('dt_origin' => 'api')));
        yield 'in a value' => array(array('a' => '"dt_origin";s:3:"api"'));
        yield 'upper case' => array(array('DT_ORIGIN' => 'API'));
        yield 'deep list' => array(array('l' => array(array(array('dt_origin' => 'api')))));
        yield 'json nested' => array('{"a":{"b":{"dt_origin":"api"}}}');
        yield 'as a key of a list' => array(array('dt_origin' => array('api')));
        yield 'numeric key' => array(array(0 => 'dt_origin', 1 => 'api'));
        yield 'bool' => array(true);
        yield 'float' => array(1.5);
        yield 'unicode' => array("\u{202e}dt_origin");
        yield 'long text' => array(str_repeat('dt_origin', 200));
    }

    /** @dataProvider hostile_meta */
    public function test_a_person_can_never_end_up_with_the_mark(array|string|int|float|bool|null $raw): void
    {
        $out = WP_Dynamic_Tags_Tag_Service::normalize_meta($raw, false);

        $this->assertIsNotObject($out);
        $this->assertFalse(is_array($out) && array_key_exists('dt_origin', $out), 'no mark in an array');
        $this->assertFalse(is_string($out) && strpos($out, 'dt_origin') !== false, 'no mention of it in text');
        // The Free limit finds a plugin's tag by searching the STORED text for the mark, so what matters is the stored form: it must
        // not contain the words at all (not as a nested key, not inside a value, in no case).
        $stored = is_array($out) ? serialize($out) : (string) $out;
        $this->assertSame(false, stripos($stored, 'dt_origin'), 'the stored form of a person\'s meta data never contains the mark: ' . $stored);
    }

    // ------------------------------------------------------------------ a person cannot fake the mark by hiding it elsewhere

    public function test_the_mark_is_removed_wherever_it_is_hidden_and_the_rest_is_kept(): void
    {
        $n = static fn($raw) => WP_Dynamic_Tags_Tag_Service::normalize_meta($raw, false);

        $this->assertSame(array('x' => array('y' => 1)), $n(array('x' => array('y' => 1, 'dt_origin' => 'api'))), 'a nested key');
        $this->assertSame(array('k' => 'v'), $n(array('k' => 'v', 'DT_Origin' => 'API')), 'in any case: the database compares text without regard to case');
        $this->assertSame(array('list' => array(array('a' => 1))), $n(array('list' => array(array('a' => 1, 'dt_origin' => 'api')))), 'inside a list of rows');
        $this->assertSame(array('x' => array('y' => 1)), $n('{"x":{"y":1,"dt_origin":"api"}}'), 'in nested JSON');
    }

    public function test_the_mark_written_out_inside_a_value_is_defused(): void
    {
        $faked = '"dt_origin";s:3:"api"';   // exactly what the limit searches for, as the text of an ordinary value
        $out = WP_Dynamic_Tags_Tag_Service::normalize_meta(array('note' => $faked, 'other' => 'unchanged'), false);

        $this->assertSame('unchanged', $out['other']);
        $this->assertStringNotContainsString('dt_origin', $out['note']);
        $this->assertSame(false, stripos(serialize($out), '"dt_origin";s:3:"api"'));
        $this->assertSame('see dt-origin docs', WP_Dynamic_Tags_Tag_Service::normalize_meta(array('note' => 'see DT_origin docs'), false)['note'], 'only the word is changed, the sentence is kept');
    }

    public function test_a_plugin_tag_keeps_what_it_sent_apart_from_the_mark_it_must_carry(): void
    {
        $this->assertSame(array('x' => array('y' => 1), 'dt_origin' => 'api'), WP_Dynamic_Tags_Tag_Service::normalize_meta(array('x' => array('y' => 1)), true));
        $this->assertSame(array('note' => 'dt_origin in my own words', 'dt_origin' => 'api'), WP_Dynamic_Tags_Tag_Service::normalize_meta(array('note' => 'dt_origin in my own words'), true), 'a plugin\'s own data is its own: only a person\'s is defused');
    }

    // ------------------------------------------------------------------ the Paid features a tag uses

    public function test_the_gated_prefixes_are_exactly_the_documented_ones(): void
    {
        foreach (array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func') as $prefix) {
            $this->assertTrue(DT_License_Manager::is_gated_prefix($prefix), $prefix);
        }
        foreach (array('meta', 'acf', 'post_title', 'WC', '', 'wc ', 'funcs') as $prefix) {
            $this->assertFalse(DT_License_Manager::is_gated_prefix($prefix), "'$prefix'");
        }
    }

    public function test_every_paid_feature_in_a_tag_is_named_once(): void
    {
        $found = DT_License_Manager::paid_features_in('{wc:price|currency} {wc:sku} {user_meta:a} {term_meta:b} {query:post_type=page} {api:https://x.test/a.json::b} {func:f} {acf:rows.name} [dt_loop source="x"]a[/dt_loop] [DT_TEMPLATE tag="t"] {if:meta:k=v}x{/if} [dt_if condition="meta:k=v"]y[/dt_if]');

        $this->assertEqualsCanonicalizing(array('{wc:}', '{user_meta:}', '{term_meta:}', '{query:}', '{api:}', '{func:}', '{acf:rows.field}', '[dt_loop]', '[dt_template]', 'field conditions'), $found);
        $this->assertSame(count($found), count(array_unique($found)));
    }

    public function test_a_field_condition_is_found_in_either_syntax_and_in_either_quote(): void
    {
        $this->assertSame(array('field conditions'), DT_License_Manager::paid_features_in('{if:meta:k=v}x{/if}'));
        $this->assertSame(array('field conditions'), DT_License_Manager::paid_features_in('[dt_if condition="meta:k=v"]y[/dt_if]'));
        $this->assertSame(array('field conditions'), DT_License_Manager::paid_features_in("[dt_if condition='meta:k=v']y[/dt_if]"));
        $this->assertSame(array('field conditions'), DT_License_Manager::paid_features_in('[dt_if class="a" condition="meta:k&lt;5"]y[/dt_if]'));
    }

    public function test_free_content_names_nothing(): void
    {
        $free = 'Hi {user_name} {post_title|upper} {meta:k} {acf:title} {current_year} {if:user_logged_in}a{/if} {if:!is_page}b{/if} {if:user_role:editor}c{/if} '
            . '{if:time_between(08:00,18:00)}d{/if} [dt_if condition="is_single"]e[/dt_if] [dt tag="x"] [dt_group group="g"] {wcx:y} {funcs:y}';

        $this->assertSame(array(), DT_License_Manager::paid_features_in($free));
        $this->assertSame(array(), DT_License_Manager::paid_features_in(''));
        $this->assertSame(array(), DT_License_Manager::paid_features_in(null));
    }

    public function test_a_condition_a_site_adds_is_not_a_paid_feature(): void
    {
        $this->assertSame(array(), DT_License_Manager::paid_features_in('{if:my_site_condition}x{/if}'));
    }

    public function test_without_the_registry_a_condition_is_not_guessed_at_and_nothing_breaks(): void
    {
        $instance = new ReflectionProperty('WP_Dynamic_Tags_Placeholders', '_instance');
        $kept = $instance->getValue();
        $instance->setValue(null, null);
        try {
            $this->assertSame(array('{wc:}'), DT_License_Manager::paid_features_in('{wc:price} {if:meta:k=v}x{/if}'), 'the token prefixes need no registry; a condition cannot be classified without it');
        } finally {
            $instance->setValue(null, $kept);
        }
    }

    public function test_the_built_in_conditions_are_recognised_with_or_without_a_negation(): void
    {
        foreach (array('user_logged_in', 'user_not_logged_in', 'is_admin', 'is_mobile', 'is_front_page', 'is_home', 'is_single', 'is_page', '!is_page', ' !user_logged_in ', 'user_role:editor', '!user_role:x', 'time_between(22:00,06:00)', 'time_between( 8:00 , 9:30 )') as $c) {
            $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_builtin_condition($c), $c);
        }
        foreach (array('meta:k=v', 'wc:price>1', 'post_title', 'acf:featured', 'my_custom', 'is_pages', 'user_logged_in2', 'time_between(25)', 'role:user_role:x', 'xtime_between(8:00,9:00)', 'time_between(8:00,9:00)x', 'time_between(8:00,9:00,10:00)') as $c) {
            $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_builtin_condition($c), $c);
        }
    }

    // ------------------------------------------------------------------ the gate, without WordPress

    public function test_a_registered_function_is_not_called_on_free_and_is_on_paid(): void
    {
        $called = 0;
        $placeholders = new WP_Dynamic_Tags_Placeholders();
        $placeholders->register_allowed_function('plan_probe', static function () use (&$called) {
            $called++;
            return 'ran';
        });

        $GLOBALS['dt_unit_options']['dt_plan'] = 'free';
        try {
            $this->assertSame('', $placeholders->resolve_token_raw('func', 'plan_probe'), 'a Paid source resolves to nothing on Free');
            $this->assertSame(0, $called, 'and its code is not even run');
            $this->assertSame('x', $placeholders->process_placeholders('{func:plan_probe|upper??x}'), 'the author\'s own fallback is what shows, and no formatter touches it');
            $this->assertSame('', $placeholders->process_placeholders('{func:plan_probe|currency}'), 'and a formatter cannot turn the missing value into text');
            $this->assertFalse($placeholders->resolve_token_raw('not_a_token', 'x'), 'an unknown token is still left alone, as before');
        } finally {
            $GLOBALS['dt_unit_options']['dt_plan'] = 'paid';
        }

        $this->assertSame('ran', $placeholders->resolve_token_raw('func', 'plan_probe'));
        $this->assertSame(1, $called);
    }

    // ------------------------------------------------------------------ the masked key

    public function test_a_key_is_masked_to_its_first_five_and_last_four_characters(): void
    {
        $this->assertSame('PAID-' . str_repeat("\u{2022}", 9) . '1234', DT_License_Manager::mask_key('PAID-ABCDEFGH-1234'));
        $this->assertSame('DT-AB' . str_repeat("\u{2022}", 13) . 'WXYZ', DT_License_Manager::mask_key('DT-ABCD-EFGH-IJKL-WXYZ'), 'a key of 22 characters: 5 shown, 13 hidden, 4 shown');
    }

    public function test_a_short_key_is_hidden_entirely_and_nothing_stays_nothing(): void
    {
        $this->assertSame('', DT_License_Manager::mask_key(''));
        $this->assertSame("\u{2022}\u{2022}\u{2022}", DT_License_Manager::mask_key('abc'));
        $this->assertSame(str_repeat("\u{2022}", 12), DT_License_Manager::mask_key('abcdefghijkl'), 'twelve characters is still "short"');
        $this->assertSame('abcde' . str_repeat("\u{2022}", 4) . 'jklm', DT_License_Manager::mask_key('abcdefghijklm'), 'thirteen is the shortest that shows its ends');
        $this->assertSame(strlen('PAID-ABCDEFGH-1234'), mb_strlen(DT_License_Manager::mask_key('PAID-ABCDEFGH-1234')), 'the mask keeps the key\'s length');
    }
}
