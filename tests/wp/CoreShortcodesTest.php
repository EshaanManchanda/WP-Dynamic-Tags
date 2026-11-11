<?php
/**
 * The core shortcodes ([dt], [dt_group], [dt_random], [dt_count], [dt_list], [dt_template]) read the custom table, the
 * only tag store. They used to read dynamic_tag posts, so a tag that exists only as a row (everything created through the
 * Table Manager or by a sibling plugin) was invisible to them.
 *
 * They keep what they always did: the tag is rendered like its own shortcode would be, and the output of [dt], [dt_group],
 * [dt_random], [dt_list] and [dt_template] is filtered with wp_kses_post (a direct [tag] shortcode is not: S10).
 */
class DT_Core_Shortcodes_Test extends WP_UnitTestCase
{
    /** @var int */
    private $group;

    public function set_up(): void
    {
        parent::set_up();
        $term = wp_insert_term('Core Group', 'tag_groups', array('slug' => 'core-group'));
        $this->group = (int) $term['term_id'];
    }

    private function seed(string $code, string $content, array $extra = array()): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'dynamic_tags', array_merge(array(
            'tag_name' => $code, 'shortcode' => $code, 'content' => $content, 'description' => 'core test', 'category_id' => 0,
            'status' => 'active', 'usage_count' => 0, 'post_id' => 0, 'meta_data' => '', 'created_date' => $now, 'modified_date' => $now,
        ), $extra));
        $id = (int) $wpdb->insert_id;
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->register_table_shortcodes();
        return $id;
    }

    // ------------------------------------------------------------------ [dt]

    public function test_dt_finds_a_row_by_shortcode_key_or_tag_and_uses_the_default(): void
    {
        $this->seed('core_plain', 'Hello {site_name|upper}');
        $expected = 'Hello ' . strtoupper(get_bloginfo('name'));

        $this->assertSame($expected, do_shortcode('[dt tag="core_plain"]'));
        $this->assertSame($expected, do_shortcode('[dt key="core_plain"]'));
        $this->assertSame('D', do_shortcode('[dt tag="core_missing" default="D"]'));
        $this->assertSame('', do_shortcode('[dt tag="core_missing"]'));
    }

    public function test_dt_resolves_a_loose_name_and_a_legacy_alias(): void
    {
        $this->seed('core_my_tag', 'by name', array('tag_name' => 'Core My Tag'));
        $this->seed('core_renamed', 'by alias', array('meta_data' => maybe_serialize(array('legacy' => array('aliases' => array('core_old_name'))))));

        $this->assertSame('by name', do_shortcode('[dt tag="Core My Tag"]'), 'a tag is found by its name written loosely');
        $this->assertSame('by alias', do_shortcode('[dt tag="core_old_name"]'), 'an alias recorded by the migration still resolves');
    }

    public function test_dt_ignores_inactive_tags(): void
    {
        $this->seed('core_off', 'hidden', array('status' => 'inactive'));
        $this->assertSame('X', do_shortcode('[dt tag="core_off" default="X"]'));
    }

    public function test_dt_filters_its_output_with_kses_but_a_direct_shortcode_does_not(): void
    {
        $this->seed('core_kses', '<b>ok</b><script>alert(1)</script>');

        $this->assertSame('<b>ok</b>alert(1)', do_shortcode('[dt tag="core_kses"]'));
        $this->assertSame('<b>ok</b><script>alert(1)</script>', do_shortcode('[core_kses]'));
    }

    // ------------------------------------------------------------------ [dt_group], [dt_list], [dt_count], [dt_random]

    private function seed_group(): void
    {
        $this->seed('core_g_b', 'Bravo', array('tag_name' => 'Bravo', 'category_id' => $this->group));
        $this->seed('core_g_a', 'Alpha', array('tag_name' => 'Alpha', 'category_id' => $this->group));
        $this->seed('core_g_c', 'Charlie', array('tag_name' => 'Charlie', 'category_id' => $this->group));
        $this->seed('core_g_off', 'Hidden', array('tag_name' => 'Zulu', 'category_id' => $this->group, 'status' => 'inactive'));
        $this->seed('core_g_other', 'Elsewhere', array('tag_name' => 'Other'));
    }

    public function test_dt_group_lists_the_active_tags_of_a_group_by_name_or_slug(): void
    {
        $this->seed_group();

        $this->assertSame('Alpha, Bravo, Charlie', do_shortcode('[dt_group group="Core Group"]'));
        $this->assertSame('Alpha | Bravo', do_shortcode('[dt_group group="core-group" separator=" | " limit="2"]'));
        $this->assertSame('Charlie, Bravo, Alpha', do_shortcode('[dt_group group="core-group" order="DESC"]'));
        $this->assertSame('<ul><li>Alpha</li><li>Bravo</li><li>Charlie</li></ul>', do_shortcode('[dt_group group="core-group" format="list"]'));
        $this->assertSame('(Alpha, Bravo, Charlie)', do_shortcode('[dt_group group="core-group" before="(" after=")"]')); // a ] inside an attribute would end the shortcode
        $this->assertSame('', do_shortcode('[dt_group group="no-such-group"]'));
        $this->assertSame('', do_shortcode('[dt_group]'));
    }

    public function test_dt_count_counts_active_tags(): void
    {
        $this->seed_group();

        $this->assertSame('3', do_shortcode('[dt_count group="core-group"]'));
        $this->assertSame('3 tags', do_shortcode('[dt_count group="Core Group" format="text"]'));
        $this->assertSame('0', do_shortcode('[dt_count group="no-such-group"]'));
        $this->assertGreaterThanOrEqual(4, (int) do_shortcode('[dt_count]'), 'every active tag, in any group');
    }

    public function test_dt_list_fills_its_template_from_the_row(): void
    {
        $this->seed_group();

        $out = do_shortcode('[dt_list group="core-group" template="{key}={value} ({title}) {shortcode}" separator="|" limit="2"]');

        $this->assertSame('core_g_a=Alpha (Alpha) [core_g_a]|core_g_b=Bravo (Bravo) [core_g_b]', $out);
    }

    public function test_dt_random_picks_only_from_the_group(): void
    {
        $this->seed_group();

        $one = do_shortcode('[dt_random group="core-group"]');
        $this->assertContains($one, array('Alpha', 'Bravo', 'Charlie'));

        $two = explode(' ', do_shortcode('[dt_random group="core-group" count="2"]'));
        $this->assertCount(2, $two);
        $this->assertCount(2, array_unique($two));
        $this->assertSame(array(), array_diff($two, array('Alpha', 'Bravo', 'Charlie')));
    }

    // ------------------------------------------------------------------ [dt_template]

    public function test_dt_template_renders_a_row_once_per_queried_post(): void
    {
        $first = self::factory()->post->create(array('post_title' => 'Tpl One', 'post_date' => '2026-01-01 10:00:00'));
        $second = self::factory()->post->create(array('post_title' => 'Tpl Two', 'post_date' => '2026-01-02 10:00:00'));
        $this->seed('core_tpl', '<i>{post_title}</i>');

        $out = do_shortcode('[dt_template tag="core_tpl" query="post__in=' . $first . ',' . $second . '&orderby=ID&order=ASC" separator="|" before="(" after=")"]');

        $this->assertSame('(<i>Tpl One</i>|<i>Tpl Two</i>)', $out);
        $this->assertSame('none', do_shortcode('[dt_template tag="core_missing" empty="none"]'));
    }
}
