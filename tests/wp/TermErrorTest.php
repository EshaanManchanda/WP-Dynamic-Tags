<?php
/**
 * L7: wp_get_post_terms() returns a WP_Error when the taxonomy does not exist (the group taxonomy is registered by this
 * plugin on init, so any code that runs before that, or after it was unregistered, sees one). Code that used the result as
 * an array was a fatal on PHP 8 (array_merge(), count() and $groups[0] on an object). WP_Dynamic_Tags_Plugin::post_terms()
 * is a drop-in wrapper that always returns an array.
 */
class DT_Term_Error_Test extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        if (!taxonomy_exists('tag_groups')) {
            $prop = new ReflectionProperty(WP_Dynamic_Tags_Plugin::class, 'tag_groups');
            $prop->getValue(WP_Dynamic_Tags_Plugin::get_instance())->register_taxonomy(); // later tests need the taxonomy
        }
        parent::tear_down();
    }

    private function tag_post(string $title): int
    {
        return (int) wp_insert_post(array('post_type' => 'dynamic_tag', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $title . ' content'));
    }

    public function test_the_wrapper_returns_the_terms_of_a_post_and_an_empty_array_for_a_missing_taxonomy(): void
    {
        $post = $this->tag_post('term_probe');
        $term = wp_insert_term('Term Probe Group', 'tag_groups');
        wp_set_object_terms($post, array((int) $term['term_id']), 'tag_groups');

        $names = WP_Dynamic_Tags_Plugin::post_terms($post, 'tag_groups', array('fields' => 'names'));
        $this->assertSame(array('Term Probe Group'), $names);

        unregister_taxonomy('tag_groups');
        $this->assertTrue(is_wp_error(wp_get_post_terms($post, 'tag_groups')), 'precondition: WordPress reports a missing taxonomy as a WP_Error');
        $this->assertSame(array(), WP_Dynamic_Tags_Plugin::post_terms($post, 'tag_groups'));
    }


    public function test_saving_a_tag_post_with_a_missing_taxonomy_does_not_throw(): void
    {
        unregister_taxonomy('tag_groups');
        $id = $this->tag_post('saved_without_taxonomy'); // runs every save_post hook, among them the post to table bridge ($groups[0])
        $this->assertGreaterThan(0, $id);
    }
}
