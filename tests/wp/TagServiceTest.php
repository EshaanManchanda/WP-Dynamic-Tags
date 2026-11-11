<?php
/**
 * The Tag Service: the rules a tag must satisfy before it is stored, on top of the facade's field validation.
 *
 *  - the shortcode may not be a name of the core [dt_*] shortcodes (or start with dt_table_)         dt_reserved_shortcode
 *  - nor a name another plugin or WordPress owns (a tag never replaces a foreign shortcode)         dt_shortcode_conflict
 *  - a tag may not render itself, directly or through other tags                                      dt_circular_reference
 *  - content written by a user without the unfiltered_html capability goes through wp_kses_post
 *  - filter dt_can_create_tag is the single seam for a cap on the number of tags                      dt_tag_limit
 */
class DT_Tag_Service_Test extends WP_UnitTestCase
{
    /** @return int|WP_Error */
    private function create(string $code, string $content = 'x', array $extra = array())
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array_merge(array('tag_name' => $code, 'shortcode' => $code, 'content' => $content), $extra));
    }

    private function code($result): string
    {
        return is_wp_error($result) ? $result->get_error_code() : 'ok';
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_can_create_tag');
        remove_all_filters('dt_allow_shortcode_override');
        remove_shortcode('svc_foreign');
        wp_set_current_user(0);
        parent::tear_down();
    }

    // ------------------------------------------------------------------ names

    public function test_the_names_of_the_core_shortcodes_are_reserved(): void
    {
        foreach (array('dt', 'dt_group', 'dt_random', 'dt_count', 'dt_list', 'dt_loop', 'dt_if', 'dt_else', 'dt_template', 'dt_table_group', 'dt_table_anything') as $name) {
            $this->assertSame('dt_reserved_shortcode', $this->code($this->create($name)), "[$name] must be refused");
        }
        $this->assertSame('ok', $this->code($this->create('svc_fine')));
    }

    public function test_a_name_that_another_plugin_owns_is_refused_unless_the_site_allows_it(): void
    {
        add_shortcode('svc_foreign', static fn() => 'FOREIGN');
        $this->assertSame('dt_shortcode_conflict', $this->code($this->create('svc_foreign')));
        $this->assertSame('dt_shortcode_conflict', $this->code($this->create('gallery')), 'WordPress core shortcodes belong to nobody else but WordPress');

        add_filter('dt_allow_shortcode_override', '__return_true');
        $this->assertSame('ok', $this->code($this->create('svc_foreign')));
    }

    public function test_renaming_a_shortcode_to_a_foreign_name_is_refused_but_saving_other_fields_is_not(): void
    {
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $id = $this->create('svc_rename');
        add_shortcode('svc_foreign', static fn() => 'FOREIGN');

        $this->assertSame('dt_shortcode_conflict', $this->code($manager->update_tag($id, array('shortcode' => 'svc_foreign'))));
        $this->assertTrue($manager->update_tag($id, array('description' => 'saving something else is fine')));
    }

    // ------------------------------------------------------------------ cycles

    public function test_a_tag_cannot_render_itself(): void
    {
        $this->assertSame('dt_circular_reference', $this->code($this->create('svc_self', 'again [svc_self]')));
        $this->assertSame('dt_circular_reference', $this->code($this->create('svc_self2', '[dt tag="svc_self2"]')));
    }

    public function test_two_tags_cannot_render_each_other(): void
    {
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $a = $this->create('svc_a', 'a uses [svc_b]');
        $this->assertIsInt($a, 'a tag may refer to one that does not exist yet');

        $this->assertSame('dt_circular_reference', $this->code($this->create('svc_b', 'b uses [svc_a]')));
        $b = $this->create('svc_b', 'b is plain');
        $this->assertIsInt($b);
        $this->assertSame('dt_circular_reference', $this->code($manager->update_tag($b, array('content' => 'now [dt tag="svc_a"]'))), 'through the [dt] shortcode as well');
        $this->assertTrue($manager->update_tag($b, array('content' => 'b still plain')));
    }

    public function test_a_chain_that_does_not_loop_is_fine(): void
    {
        $this->assertIsInt($this->create('svc_c1', 'end'));
        $this->assertIsInt($this->create('svc_c2', '[svc_c1]'));
        $this->assertIsInt($this->create('svc_c3', '[svc_c2] and [svc_c1]'));
    }

    // ------------------------------------------------------------------ cap seam

    public function test_the_creation_filter_can_stop_a_new_tag_but_never_an_update(): void
    {
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $id = $this->create('svc_cap_existing');

        add_filter('dt_can_create_tag', static fn() => false);
        $this->assertSame('dt_tag_limit', $this->code($this->create('svc_cap_new')));
        $this->assertTrue($manager->update_tag($id, array('priority' => 5)), 'existing tags stay editable at the limit');

        remove_all_filters('dt_can_create_tag');
        add_filter('dt_can_create_tag', static fn() => new WP_Error('my_limit', 'Free plan: 15 tags.'));
        $error = $this->create('svc_cap_new');
        $this->assertSame('my_limit', $this->code($error), 'a WP_Error from the filter is passed on as it is');
    }

    // ------------------------------------------------------------------ kses by capability

    public function test_content_from_a_user_without_unfiltered_html_is_filtered(): void
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'author'))); // authors lack unfiltered_html
        $id = $this->create('svc_kses_author', '<b>ok</b><script>alert(1)</script>');
        $this->assertSame('<b>ok</b>alert(1)', WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag($id)->content);
    }

    public function test_content_from_an_administrator_or_from_code_without_a_user_is_kept(): void
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $admin = $this->create('svc_kses_admin', '<b>ok</b><script>alert(1)</script>');
        $this->assertSame('<b>ok</b><script>alert(1)</script>', WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag($admin)->content);

        wp_set_current_user(0); // cron, WP-CLI, a sibling plugin's setup code
        $code = $this->create('svc_kses_code', '<i>x</i><script>1</script>');
        $this->assertSame('<i>x</i><script>1</script>', WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag($code)->content);
    }
}
