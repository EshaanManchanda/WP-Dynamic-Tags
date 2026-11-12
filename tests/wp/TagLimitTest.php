<?php
/**
 * The Free plan allows 15 tags that people create themselves (Add New, Duplicate, Import); Paid is unlimited.
 *
 * Code that creates tags (Certificate Generator makes one per student, teacher and school, the chatbot makes its event tags)
 * goes through create_tag($data) and is never blocked: such a tag is marked in its meta_data (dt_origin = api) and does not count.
 * A person cannot claim that mark, and an update cannot change it. Tags that already exist always keep working: the cap stops
 * new ones, it never disables, hides or deletes one.
 *
 * The suite runs as the Paid plan (tests/wp/bootstrap.php); this test sets Free itself.
 */
class DT_Tag_Limit_Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        remove_all_filters('pre_option_dt_plan');
        update_option('dt_plan', 'free');
    }

    public function tear_down(): void
    {
        remove_all_filters('dt_upgrade_url');
        parent::tear_down();
    }

    /** @return int|WP_Error */
    private function person_creates(string $code, array $extra = array())
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array_merge(array('tag_name' => $code, 'shortcode' => $code, 'content' => 'x'), $extra), 'user');
    }

    /** @return int|WP_Error */
    private function plugin_creates(string $code, array $extra = array())
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array_merge(array('tag_name' => $code, 'shortcode' => $code, 'content' => 'x'), $extra));
    }

    /** @return int[] */
    private function people_create(int $how_many, string $prefix = 'lim'): array
    {
        $ids = array();
        for ($i = 1; $i <= $how_many; $i++) {
            $id = $this->person_creates($prefix . $i);
            $this->assertIsInt($id, "tag $i of $how_many");
            $ids[] = $id;
        }
        return $ids;
    }

    private function code($result): string
    {
        return is_wp_error($result) ? $result->get_error_code() : 'ok';
    }

    private function meta(int $id)
    {
        return WP_Dynamic_Tags_Database_Manager::get_instance()->get_tag($id)->meta_data;
    }

    // ------------------------------------------------------------------ the cap

    public function test_free_stops_people_at_fifteen_tags(): void
    {
        $this->people_create(15);

        $refused = $this->person_creates('lim_one_too_many');
        $this->assertSame('dt_tag_limit', $this->code($refused));
        $this->assertStringContainsString('15', $refused->get_error_message());
        $this->assertStringContainsString('keeps working', $refused->get_error_message(), 'it must say that nothing existing is touched');
        $this->assertSame(15, DT_License_Manager::count_capped_tags());
    }

    public function test_the_refusal_names_the_upgrade_page_only_when_the_site_has_one(): void
    {
        $this->people_create(15);
        $without = $this->person_creates('lim_a')->get_error_message();
        $this->assertStringNotContainsString('http', $without, 'no pricing page is known: nothing is invented');

        add_filter('dt_upgrade_url', static fn() => 'https://example.test/pricing');
        $with = $this->person_creates('lim_b')->get_error_message();
        $this->assertStringContainsString('https://example.test/pricing', $with);
    }

    public function test_paid_has_no_cap(): void
    {
        update_option('dt_plan', 'paid');
        $this->people_create(20);
        $this->assertSame(20, DT_License_Manager::count_capped_tags());
    }

    public function test_deleting_a_tag_makes_room_for_another(): void
    {
        $ids = $this->people_create(15);
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_x')));

        $this->assertTrue(WP_Dynamic_Tags_Table_Manager::get_instance()->delete_tag($ids[0]));
        $this->assertIsInt($this->person_creates('lim_x'));
    }

    // ------------------------------------------------------------------ tags other plugins create

    public function test_what_other_plugins_create_is_never_blocked_and_never_counted(): void
    {
        $this->people_create(15);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertIsInt($this->plugin_creates('cg_student_' . $i), 'create_tag($data) as a sibling plugin calls it');
        }
        $this->assertSame(15, DT_License_Manager::count_capped_tags(), 'five more tags exist, none of them counts');
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_still_full')));
    }

    public function test_a_site_full_of_plugin_tags_still_lets_a_person_create_theirs(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertIsInt($this->plugin_creates('cg_row_' . $i));
        }
        $this->people_create(15);
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_over')));
    }

    public function test_a_plugin_tag_is_marked_in_its_meta_data(): void
    {
        $id = $this->plugin_creates('cg_marked', array('meta_data' => array('source' => 'certificates')));

        $this->assertSame(array('source' => 'certificates', 'dt_origin' => 'api'), $this->meta($id));
        $this->assertArrayNotHasKey('dt_origin', (array) $this->meta($this->person_creates('lim_unmarked')));
    }

    public function test_any_origin_other_than_api_counts_as_a_person(): void
    {
        $this->people_create(14);
        $this->assertIsInt(WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array('tag_name' => 'lim_odd', 'shortcode' => 'lim_odd', 'content' => 'x'), 'something-else'));
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_after_odd')));
    }

    // ------------------------------------------------------------------ the mark cannot be claimed

    public function test_a_person_cannot_claim_the_exemption_with_meta_data(): void
    {
        $claims = array(
            'array'      => array('dt_origin' => 'api', 'keep' => '1'),
            'json'       => '{"dt_origin":"api","keep":"1"}',
            'serialized' => 'a:2:{s:9:"dt_origin";s:3:"api";s:4:"keep";s:1:"1";}',
            'plain text' => 'x"dt_origin";s:3:"api";',
            // The limit looks for the mark in the STORED text, so hiding it deeper or writing it out inside a value must not work either.
            'nested'     => array('keep' => '1', 'x' => array('dt_origin' => 'api')),
            'in a value' => array('keep' => '1', 'note' => '"dt_origin";s:3:"api"'),
            'upper case' => array('keep' => '1', 'DT_ORIGIN' => 'API'),
        );
        $n = 0;
        foreach ($claims as $name => $meta) {
            $id = $this->person_creates('lim_claim_' . (++$n), array('meta_data' => $meta));
            $this->assertIsInt($id, $name);
            $stored = $this->meta($id);
            $this->assertNotSame('api', is_array($stored) ? ($stored['dt_origin'] ?? '') : '', "$name: the mark must be gone");
            if (is_array($stored)) {
                $this->assertSame('1', $stored['keep'] ?? null, "$name: the rest of the meta data is kept");
            }
        }
        $this->assertSame(count($claims), DT_License_Manager::count_capped_tags(), 'every one of them counts toward the limit');
    }

    public function test_an_update_cannot_add_or_remove_the_mark(): void
    {
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();
        $person = $this->person_creates('lim_person');
        $plugin = $this->plugin_creates('cg_plugin');

        $this->assertTrue($manager->update_tag($person, array('meta_data' => array('dt_origin' => 'api', 'x' => '1'))));
        $this->assertSame(array('x' => '1'), $this->meta($person), 'a person\'s tag does not become a plugin tag');

        $this->assertTrue($manager->update_tag($plugin, array('meta_data' => array('x' => '2'))));
        $this->assertSame(array('x' => '2', 'dt_origin' => 'api'), $this->meta($plugin), 'a plugin tag keeps its mark when its meta data is replaced');

        $this->assertTrue($manager->update_tag($plugin, array('content' => 'new content')));
        $this->assertSame('api', $this->meta($plugin)['dt_origin'], 'and when something else is updated');
    }

    // ------------------------------------------------------------------ tags that exist

    public function test_tags_over_the_cap_keep_rendering_and_can_be_edited(): void
    {
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $ids = array();
        for ($i = 1; $i <= 20; $i++) {   // 20 tags with no mark: a site that had them before, or came down from Paid
            $ids[] = $db->insert_tag(array('tag_name' => 'old' . $i, 'shortcode' => 'old' . $i, 'content' => 'value ' . $i));
        }
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();

        $this->assertSame(20, DT_License_Manager::count_capped_tags());
        $this->assertSame('value 20', do_shortcode('[dt tag="old20"]'), 'rendering never looks at the cap');
        $this->assertTrue(WP_Dynamic_Tags_Table_Manager::get_instance()->update_tag($ids[19], array('content' => 'edited')), 'editing an existing tag is not creating one');
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_new')));

        foreach (array_slice($ids, 0, 6) as $id) {
            WP_Dynamic_Tags_Table_Manager::get_instance()->delete_tag($id);
        }
        $this->assertIsInt($this->person_creates('lim_new'), 'down to 14, one more fits');
    }

    public function test_a_row_with_no_meta_data_at_all_counts(): void
    {
        global $wpdb;
        $this->people_create(2);
        $wpdb->query('UPDATE ' . WP_Dynamic_Tags_Database_Manager::get_instance()->get_table_name() . ' SET meta_data = NULL'); // phpcs:ignore WordPress.DB -- a test fixture

        $this->assertSame(2, DT_License_Manager::count_capped_tags());
    }

    // ------------------------------------------------------------------ the seam

    public function test_the_filter_is_told_who_creates_the_tag(): void
    {
        $seen = array();
        add_filter('dt_can_create_tag', static function ($allowed, $data, $origin) use (&$seen) {
            $seen[] = $origin;
            return $allowed;
        }, 10, 3);

        $this->person_creates('lim_seen_user');
        $this->plugin_creates('lim_seen_api');

        $this->assertSame(array('user', 'api'), $seen);
    }

    public function test_a_site_can_still_refuse_through_the_filter_for_its_own_reasons(): void
    {
        update_option('dt_plan', 'paid');
        add_filter('dt_can_create_tag', static fn() => new WP_Error('site_rule', 'Not on this site.'), 5);

        $this->assertSame('site_rule', $this->code($this->person_creates('lim_site')));
        $this->assertSame('site_rule', $this->code($this->plugin_creates('lim_site_api')), 'a refusal from the site applies to plugins as well');
    }

    public function test_a_refusal_from_the_site_is_not_replaced_by_the_limits_own(): void
    {
        $this->people_create(15);   // Free and full: the limit would refuse too, with its own code
        add_filter('dt_can_create_tag', static fn() => new WP_Error('site_rule', 'Not on this site.'), 5);

        $this->assertSame('site_rule', $this->code($this->person_creates('lim_site_full')), 'the first refusal is the one a person reads');

        remove_all_filters('dt_can_create_tag');
        add_filter('dt_can_create_tag', array('DT_License_Manager', 'enforce_tag_limit'), 10, 3);
        add_filter('dt_can_create_tag', '__return_false', 5);
        $this->assertSame('dt_tag_limit', $this->code($this->person_creates('lim_false')), 'a plain false has no message of its own: the facade reports the limit');
    }
}
