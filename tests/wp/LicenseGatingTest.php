<?php
/**
 * The Free and Paid plans: what a Free site renders and what it does not.
 *
 * Paid features (LICENSE-PLAN.md): {wc:}, {user_meta:}, {term_meta:}, {query:}, {api:}, {func:}, the ACF repeater/group/
 * gallery/relationship branches, [dt_loop], [dt_template] and field-based conditions ({if:meta:x=y}, [dt_if condition="wc:..."]).
 * Free keeps everything else, including [dt_if] with a built-in condition and custom conditions added through the
 * dt_evaluate_condition filter. A Paid feature on a Free site behaves like a feature with no data: the token renders empty (an
 * author-written ??fallback, empty="" or template text still shows), nothing is written, nothing is advertised to visitors.
 *
 * The suite runs as the Paid plan (tests/wp/bootstrap.php); every test here removes that and sets its own plan.
 */
class DT_License_Gating_Test extends WP_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Placeholders */
    private $placeholders;

    /** @var int */
    private $post_id;

    /** @var array<string, callable> the parameterized placeholders as they were before this test registered stand-ins */
    private $registry = array();

    /** @var array<string, callable> the plain {key} placeholders as they were before this test registered one */
    private $flat_registry = array();

    public function set_up(): void
    {
        parent::set_up();
        remove_all_filters('pre_option_dt_plan');
        $this->plan('free');

        $this->placeholders = WP_Dynamic_Tags_Placeholders::get_instance();
        $this->registry = (new ReflectionProperty($this->placeholders, 'parameterized_placeholders'))->getValue($this->placeholders);
        $this->flat_registry = (new ReflectionProperty($this->placeholders, 'placeholders'))->getValue($this->placeholders);
        $this->placeholders->register_allowed_function('gate_probe', static fn($arg = null) => 'PROBE' . ($arg === null ? '' : '(' . $arg . ')'));
        $this->placeholders->register_allowed_function('gate_list', static fn() => array('one', 'two'));

        $author = self::factory()->user->create();
        update_user_meta($author, 'gate_user_meta', 'USER-META');
        $this->post_id = self::factory()->post->create(array('post_author' => $author, 'post_title' => 'Gate post'));
        update_post_meta($this->post_id, 'gate_flag', 'on');
        $GLOBALS['post'] = get_post($this->post_id);
        setup_postdata($GLOBALS['post']);
    }

    public function tear_down(): void
    {
        wp_reset_postdata();
        remove_all_filters('pre_http_request');
        remove_all_filters('dt_evaluate_condition');
        $this->forget_prefix_stand_ins();
        parent::tear_down();
    }

    private function plan(string $plan): void
    {
        update_option('dt_plan', $plan);
    }

    private function render(string $content): string
    {
        return $this->placeholders->process_placeholders($content);
    }

    /** @param string[] $prefixes */
    private function stand_in(array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            $this->placeholders->register_parameterized_placeholder($prefix, static fn($arg) => 'STAND-IN');
        }
    }

    /** The placeholder class is a singleton for the whole run: put its registries back the way this test found them. */
    private function forget_prefix_stand_ins(): void
    {
        (new ReflectionProperty($this->placeholders, 'parameterized_placeholders'))->setValue($this->placeholders, $this->registry);
        (new ReflectionProperty($this->placeholders, 'placeholders'))->setValue($this->placeholders, $this->flat_registry);
    }

    private function answer_api(string $body): void
    {
        add_filter('pre_http_request', static function () use ($body) {
            return array(
                'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(array('content-type' => 'application/json')),
                'body'     => $body,
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies'  => array(),
                'filename' => null,
            );
        });
    }

    // ------------------------------------------------------------------ tokens

    public function test_every_paid_prefix_renders_empty_on_free_and_its_value_on_paid(): void
    {
        $this->stand_in(DT_License_Manager::GATED_PREFIXES);

        foreach (DT_License_Manager::GATED_PREFIXES as $prefix) {
            $this->plan('free');
            $this->assertSame('', $this->render('{' . $prefix . ':x}'), "{{$prefix}:x} must render nothing on Free");
            $this->assertSame('', $this->render('{' . $prefix . ':x|currency}'), "a formatter must not turn the missing value of {{$prefix}:x} into \$0.00");
            $this->plan('paid');
            $this->assertSame('STAND-IN', $this->render('{' . $prefix . ':x}'), "{{$prefix}:x} must render on Paid");
        }
        $this->assertSame(array('wc', 'user_meta', 'term_meta', 'query', 'api', 'func'), DT_License_Manager::GATED_PREFIXES, 'the Settings table and LICENSE-PLAN.md list exactly these');
    }

    public function test_the_real_sources_follow_the_plan(): void
    {
        $this->answer_api('{"rate":"42"}');
        $tokens = array(
            'user_meta' => '{user_meta:gate_user_meta}',
            'func'      => '{func:gate_probe:x}',
            'api'       => '{api:https://api.example.test/rates.json::rate}',
        );
        $expected = array('user_meta' => 'USER-META', 'func' => 'PROBE(x)', 'api' => '42');

        foreach ($tokens as $name => $token) {
            $this->plan('free');
            $this->assertSame('', $this->render($token), "$name on Free");
            $this->plan('paid');
            $this->assertSame($expected[$name], $this->render($token), "$name on Paid");
        }

        // {query:} resolves to rows, which only a loop or a formatter turns into text: ask for the rows themselves
        $this->plan('free');
        $this->assertSame('', $this->placeholders->resolve_token_raw('query', 'post_type=post&posts_per_page=1'));
        $this->plan('paid');
        $rows = $this->placeholders->resolve_token_raw('query', 'post_type=post&posts_per_page=1');
        $this->assertSame('Gate post', $rows[0]['title']);
    }

    public function test_the_api_is_not_even_contacted_on_free(): void
    {
        $calls = 0;
        add_filter('pre_http_request', static function ($pre) use (&$calls) {
            $calls++;
            return $pre;
        });

        $this->render('{api:https://api.example.test/never.json::a}');
        $this->assertSame(0, $calls, 'a Free site must not make the request');
    }

    public function test_the_free_tokens_keep_working_on_free(): void
    {
        $this->assertSame('Gate post', $this->render('{post_title}'));
        $this->assertSame('GATE POST', $this->render('{post_title|upper}'));
        $this->assertSame('on', $this->render('{meta:gate_flag}'));
        $this->assertSame('fallback', $this->render('{meta:gate_missing??fallback}'));
        $this->assertSame(wp_date('Y'), $this->render('{current_year}'));
    }

    public function test_an_author_written_fallback_still_shows_for_a_paid_token_on_free(): void
    {
        $this->assertSame('Not available', $this->render('{func:gate_probe??Not available}'));
        $this->plan('paid');
        $this->assertSame('PROBE', $this->render('{func:gate_probe??Not available}'));
    }

    // ------------------------------------------------------------------ shortcodes

    public function test_dt_loop_needs_paid(): void
    {
        $loop = '[dt_loop source="func:gate_list" empty="no rows"]<i>{item}</i>[/dt_loop]';

        $this->assertSame('no rows', do_shortcode($loop), 'on Free it behaves like a loop with no data');
        $this->plan('paid');
        $this->assertSame('<i>one</i><i>two</i>', do_shortcode($loop));
    }

    public function test_dt_loop_needs_paid_whatever_the_source_is(): void
    {
        // a source that is not a Paid token (a site's or another plugin's own array placeholder): the loop itself is the Paid feature
        $this->placeholders->register_placeholder('gate_array', static fn() => array('p', 'q'));
        $loop = '[dt_loop source="gate_array" empty="no rows"]<i>{item}</i>[/dt_loop]';

        $this->assertSame('no rows', do_shortcode($loop));
        $this->plan('paid');
        $this->assertSame('<i>p</i><i>q</i>', do_shortcode($loop));
    }

    public function test_dt_template_needs_paid(): void
    {
        WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array('tag_name' => 'gate_card', 'shortcode' => 'gate_card', 'content' => '<b>{post_title}</b>'));
        $template = '[dt_template tag="gate_card" query="p=' . $this->post_id . '" empty="no cards"]';

        $this->assertSame('no cards', do_shortcode($template));
        $this->plan('paid');
        $this->assertSame('<b>Gate post</b>', do_shortcode($template));
    }

    public function test_dt_group_dt_random_dt_count_dt_list_and_dt_stay_free(): void
    {
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $manager->create_tag(array('tag_name' => 'gate_plain', 'shortcode' => 'gate_plain', 'content' => 'plain value'));

        $this->assertSame('plain value', do_shortcode('[dt tag="gate_plain"]'));
        $this->assertSame('plain value', do_shortcode('[gate_plain]'));
        $this->assertNotSame('', do_shortcode('[dt_list]'));
    }

    // ------------------------------------------------------------------ conditions

    public function test_field_based_conditions_need_paid(): void
    {
        $this->stand_in(array('wc'));
        $conditions = array(
            '{if:meta:gate_flag=on}yes{else}no{/if}',
            '{if:meta:gate_flag}yes{else}no{/if}',
            '{if:func:gate_probe!=zzz}yes{else}no{/if}',
            '{if:wc:price>1}yes{else}no{/if}',
        );
        foreach ($conditions as $content) {
            $this->plan('free');
            $this->assertSame('no', $this->render($content), "$content on Free");
        }
        $this->plan('paid');
        $this->assertSame('yes', $this->render('{if:meta:gate_flag=on}yes{else}no{/if}'));
        $this->assertSame('yes', $this->render('{if:func:gate_probe!=zzz}yes{else}no{/if}'), 'a comparison against a Paid source is decided by the plan, not by the empty string it would resolve to');
    }

    public function test_the_built_in_conditions_and_custom_ones_stay_free(): void
    {
        add_filter('dt_evaluate_condition', static fn($result, $condition) => $condition === 'gate_custom' ? true : $result, 10, 2);

        $this->assertSame('out', $this->render('{if:user_logged_in}in{else}out{/if}'));
        $this->assertSame('in', $this->render('{if:!user_logged_in}in{else}out{/if}'));
        $this->assertSame('yes', $this->render('{if:time_between(00:00,23:59)}yes{else}no{/if}'));
        $this->assertSame('yes', $this->render('{if:gate_custom}yes{else}no{/if}'), 'a condition a site adds through the filter is its own code, not a Paid feature');
    }

    public function test_dt_if_works_with_built_in_conditions_on_free_but_not_with_field_conditions(): void
    {
        $this->assertSame('out', do_shortcode('[dt_if condition="user_logged_in"]in[dt_else]out[/dt_if]'));
        $this->assertSame('no', do_shortcode('[dt_if condition="meta:gate_flag=on"]yes[dt_else]no[/dt_if]'));
        $this->plan('paid');
        $this->assertSame('yes', do_shortcode('[dt_if condition="meta:gate_flag=on"]yes[dt_else]no[/dt_if]'));
    }

    // ------------------------------------------------------------------ ACF

    public function test_acf_scalar_fields_are_free_and_the_structured_ones_are_not(): void
    {
        $scalar = array(null, 'text');
        $structured = array(array(null, 'repeater'), array(null, 'flexible_content'), array(null, 'group'), array(null, 'gallery'), array(null, 'relationship'), array(null, 'post_object'), array('name', 'repeater'), array('name', 'group'), array('name', null));

        $this->assertTrue(DT_License_Manager::acf_allowed($scalar[0], $scalar[1]));
        $this->assertTrue(DT_License_Manager::acf_allowed(null, null), 'an unknown field type is a scalar, as before');
        foreach ($structured as $case) {
            $this->plan('free');
            $this->assertFalse(DT_License_Manager::acf_allowed($case[0], $case[1]), json_encode($case) . ' on Free');
            $this->plan('paid');
            $this->assertTrue(DT_License_Manager::acf_allowed($case[0], $case[1]), json_encode($case) . ' on Paid');
        }
    }

    // ------------------------------------------------------------------ what a tag uses (the admin badges and notices)

    public function test_the_paid_features_a_tag_uses_are_listed(): void
    {
        $content = '{wc:price|currency} {user_meta:a} {term_meta:b} {query:post_type=page} {api:https://x.test/a.json::b} {func:f} {acf:rows.name} '
            . '[dt_loop source="query:x"]a[/dt_loop] [dt_template tag="t"] {if:meta:k=v}x{/if} [dt_if condition="wc:price>1"]y[/dt_if]';

        $this->assertEqualsCanonicalizing(
            array('{wc:}', '{user_meta:}', '{term_meta:}', '{query:}', '{api:}', '{func:}', '{acf:rows.field}', '[dt_loop]', '[dt_template]', 'field conditions'),
            DT_License_Manager::paid_features_in($content)
        );
    }

    public function test_free_content_lists_nothing(): void
    {
        $content = 'Hi {user_name} {post_title|upper} {meta:k} {acf:title} {if:user_logged_in}a{/if} {if:!is_page}b{/if} {if:user_role:editor}c{/if} '
            . '{if:time_between(08:00,18:00)}d{/if} [dt_if condition="is_single"]e[/dt_if] [dt tag="x"] [dt_group group="g"]';

        $this->assertSame(array(), DT_License_Manager::paid_features_in($content));
        $this->assertSame(array(), DT_License_Manager::paid_features_in(''));
    }

    public function test_the_built_in_conditions_are_recognised(): void
    {
        foreach (array('user_logged_in', 'user_not_logged_in', 'is_admin', 'is_mobile', 'is_front_page', 'is_home', 'is_single', 'is_page', '!is_page', 'user_role:editor', 'time_between(22:00,06:00)', 'time_between( 8:00 , 9:30 )') as $condition) {
            $this->assertTrue(WP_Dynamic_Tags_Placeholders::is_builtin_condition($condition), $condition);
            $this->assertFalse($this->placeholders->is_field_condition($condition), "$condition is not a field condition");
        }
        foreach (array('meta:k=v', 'wc:price>1', 'post_title', 'acf:featured', 'my_custom') as $condition) {
            $this->assertFalse(WP_Dynamic_Tags_Placeholders::is_builtin_condition($condition), $condition);
        }
        $this->assertTrue($this->placeholders->is_field_condition('meta:k=v'));
        $this->assertTrue($this->placeholders->is_field_condition('post_title'), 'a token tested for a value reads a field too');
        $this->assertFalse($this->placeholders->is_field_condition('my_custom'), 'a condition no token answers to is left to the filter');
    }

    // ------------------------------------------------------------------ no writes

    public function test_rendering_on_free_writes_no_option(): void
    {
        $writes = 0;
        $count = static function () use (&$writes): void {
            $writes++;
        };
        add_action('added_option', $count);
        add_action('updated_option', $count);

        $this->render('{func:gate_probe} {post_title} {if:meta:gate_flag=on}x{/if}');
        do_shortcode('[dt_loop source="func:gate_list"]{item}[/dt_loop]');

        $this->assertSame(0, $writes, 'the plan is read, never written, while a page renders');
    }
}
