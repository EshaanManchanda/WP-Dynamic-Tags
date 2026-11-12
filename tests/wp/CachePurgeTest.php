<?php
/**
 * An edit to a tag shows on the whole site at once (user requirement). The plugin keeps nothing between requests, so what can
 * hold an old value is a page cache in front of it: when a tag or a group changes, the page caches of the site are purged,
 * once per request, and only when something really changed. Rendering after an edit in the same request is checked too.
 *
 * The cache plugins are stand-ins: the functions they expose are defined here and record that they were called.
 */
if (!function_exists('rocket_clean_domain')) {
    function rocket_clean_domain()
    {
        $GLOBALS['dt_purge_calls'][] = 'wp-rocket';
        if (!empty($GLOBALS['dt_purge_throw'])) {
            throw new RuntimeException('a cache plugin that fails');
        }
    }
}
if (!function_exists('w3tc_flush_all')) {
    function w3tc_flush_all()
    {
        $GLOBALS['dt_purge_calls'][] = 'w3-total-cache';
    }
}
if (!function_exists('wp_cache_clear_cache')) {
    function wp_cache_clear_cache()
    {
        $GLOBALS['dt_purge_calls'][] = 'wp-super-cache';
    }
}
if (!function_exists('sg_cachepress_purge_cache')) {
    function sg_cachepress_purge_cache()
    {
        $GLOBALS['dt_purge_calls'][] = 'sg-optimizer';
    }
}
if (!function_exists('wpfc_clear_all_cache')) {
    function wpfc_clear_all_cache($minified = false)
    {
        $GLOBALS['dt_purge_calls'][] = 'wp-fastest-cache';
    }
}

class DT_Cache_Purge_Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $GLOBALS['dt_purge_calls'] = array();
        $GLOBALS['dt_purge_throw'] = false;
        WP_Dynamic_Tags_Cache_Purge::flush(); // whatever an earlier test left pending
        $GLOBALS['dt_purge_calls'] = array();
    }

    public function tear_down(): void
    {
        $GLOBALS['dt_purge_throw'] = false;
        remove_all_filters('dt_purge_page_caches');
        remove_all_actions('dt_tags_changed');
        remove_all_actions('litespeed_purge_all');
        remove_all_actions('cache_enabler_clear_complete_cache');
        parent::tear_down();
    }

    private function tags(): WP_Dynamic_Tags_Table_Manager
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance();
    }

    private function create(string $code, string $content = 'x'): int
    {
        $id = $this->tags()->create_tag(array('tag_name' => $code, 'shortcode' => $code, 'content' => $content));
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        return $id;
    }

    // ------------------------------------------------------------------ what asks for a purge

    public function test_creating_updating_and_deleting_a_tag_each_ask_for_a_purge(): void
    {
        $this->assertFalse(WP_Dynamic_Tags_Cache_Purge::is_pending());
        $id = $this->create('purge_a');
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a new tag');

        WP_Dynamic_Tags_Cache_Purge::flush();
        $this->tags()->update_tag($id, array('content' => 'changed'));
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'new content');

        WP_Dynamic_Tags_Cache_Purge::flush();
        $this->tags()->update_tag($id, array('status' => 'inactive'));
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a tag switched off disappears from pages');

        WP_Dynamic_Tags_Cache_Purge::flush();
        $this->tags()->delete_tag($id);
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a deleted tag');
    }

    public function test_a_save_that_changes_nothing_does_not_purge_the_site(): void
    {
        $id = $this->create('purge_same', 'same content');
        WP_Dynamic_Tags_Cache_Purge::flush();

        $this->tags()->update_tag($id, array('content' => 'same content', 'status' => 'active', 'priority' => 0));
        $this->assertFalse(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a sibling plugin that re-syncs unchanged data must not empty the page cache each time');

        $this->tags()->track_usage($id);
        $this->assertFalse(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a usage count is not a change to what a page shows');
    }

    public function test_a_change_to_a_group_asks_for_a_purge_too(): void
    {
        $term = wp_insert_term('Purge group', 'tag_groups');
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a new group');

        WP_Dynamic_Tags_Cache_Purge::flush();
        wp_update_term($term['term_id'], 'tag_groups', array('name' => 'Purge group renamed'));
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a renamed group: [dt_group group="..."] shows other tags');

        WP_Dynamic_Tags_Cache_Purge::flush();
        wp_delete_term($term['term_id'], 'tag_groups');
        $this->assertTrue(WP_Dynamic_Tags_Cache_Purge::is_pending(), 'a deleted group');
    }

    // ------------------------------------------------------------------ the purge itself

    public function test_many_changes_in_one_request_purge_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->create('purge_many_' . $i);
        }
        $before = did_action('dt_tags_changed');
        $GLOBALS['dt_purge_calls'] = array();

        WP_Dynamic_Tags_Cache_Purge::flush();
        WP_Dynamic_Tags_Cache_Purge::flush(); // the second has nothing left to do

        $this->assertSame($before + 1, did_action('dt_tags_changed'));
        $this->assertSame(1, count(array_keys($GLOBALS['dt_purge_calls'], 'wp-rocket', true)));
    }

    public function test_the_supported_cache_plugins_are_purged(): void
    {
        $litespeed = 0;
        $enabler = 0;
        add_action('litespeed_purge_all', static function () use (&$litespeed) {
            $litespeed++;
        });
        add_action('cache_enabler_clear_complete_cache', static function () use (&$enabler) {
            $enabler++;
        });
        $this->create('purge_all');
        $GLOBALS['dt_purge_calls'] = array();

        WP_Dynamic_Tags_Cache_Purge::flush();

        foreach (array('wp-rocket', 'w3-total-cache', 'wp-super-cache', 'sg-optimizer', 'wp-fastest-cache') as $plugin) {
            $this->assertContains($plugin, $GLOBALS['dt_purge_calls'], $plugin . ' is purged');
        }
        $this->assertSame(1, $litespeed, 'LiteSpeed Cache listens to litespeed_purge_all');
        $this->assertSame(1, $enabler, 'Cache Enabler listens to cache_enabler_clear_complete_cache');
    }

    public function test_a_cache_plugin_that_fails_does_not_stop_the_others(): void
    {
        $this->create('purge_fail');
        $GLOBALS['dt_purge_calls'] = array();
        $GLOBALS['dt_purge_throw'] = true; // WP Rocket throws; it is the first one called

        WP_Dynamic_Tags_Cache_Purge::flush();

        $this->assertContains('wp-rocket', $GLOBALS['dt_purge_calls']);
        $this->assertContains('w3-total-cache', $GLOBALS['dt_purge_calls'], 'the next purger still runs');
        $this->assertContains('sg-optimizer', $GLOBALS['dt_purge_calls']);
    }

    public function test_a_listener_of_the_public_action_that_fails_does_not_break_the_request(): void
    {
        add_action('dt_tags_changed', static function () {
            throw new RuntimeException('another plugin failing');
        });
        $this->create('purge_listener');
        $GLOBALS['dt_purge_calls'] = array();

        WP_Dynamic_Tags_Cache_Purge::flush();

        $this->assertContains('wp-rocket', $GLOBALS['dt_purge_calls'], 'the built-in purge still happens');
    }

    public function test_the_built_in_purge_can_be_switched_off_but_the_action_still_fires(): void
    {
        add_filter('dt_purge_page_caches', '__return_false');
        $this->create('purge_off');
        $before = did_action('dt_tags_changed');
        $GLOBALS['dt_purge_calls'] = array();

        WP_Dynamic_Tags_Cache_Purge::flush();

        $this->assertSame(array(), $GLOBALS['dt_purge_calls']);
        $this->assertSame($before + 1, did_action('dt_tags_changed'), 'a site with its own cache layer listens to this action');
    }

    public function test_the_purge_runs_at_the_end_of_the_request(): void
    {
        $this->assertNotFalse(has_action('shutdown', array('WP_Dynamic_Tags_Cache_Purge', 'flush')), 'hooked on shutdown, so one purge covers every change of the request');
    }

    // ------------------------------------------------------------------ what a page shows after an edit

    public function test_an_edit_shows_in_the_very_next_render_of_the_same_request(): void
    {
        $id = $this->create('rt_probe', 'first value');
        $this->assertSame('first value', do_shortcode('[rt_probe]'));

        $this->tags()->update_tag($id, array('content' => 'second value'));
        $this->assertSame('second value', do_shortcode('[rt_probe]'), 'no stale copy of the old content');

        $this->tags()->update_tag($id, array('status' => 'inactive'));
        $this->assertSame('[rt_probe]', do_shortcode('[rt_probe]'), 'a tag switched off stops rendering at once');

        $this->tags()->update_tag($id, array('status' => 'active', 'content' => 'back again'));
        $this->assertSame('back again', do_shortcode('[rt_probe]'));

        $this->tags()->delete_tag($id);
        $this->assertSame('[rt_probe]', do_shortcode('[rt_probe]'), 'a deleted tag stops rendering at once');
    }

    public function test_renaming_the_shortcode_moves_it_at_once(): void
    {
        $id = $this->create('rt_old_name', 'moved');
        $this->tags()->update_tag($id, array('shortcode' => 'rt_new_name'));

        $this->assertSame('[rt_old_name]', do_shortcode('[rt_old_name]'), 'the old name no longer renders');
        $this->assertSame('moved', do_shortcode('[rt_new_name]'));
    }
}
