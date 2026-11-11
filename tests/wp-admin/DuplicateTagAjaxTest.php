<?php
/**
 * L6: assets/js/admin.js posts the "Duplicate" button to admin-ajax.php (action dt_duplicate_tag, nonce dt_duplicate), but
 * only a GET admin_action_ handler existed, so the button did nothing. Authorization (capability, nonce) is covered by
 * DT_Action_Security_Test through tests/wp-admin/support/actions.php; this class covers what the handler does.
 */
class DT_Duplicate_Tag_Ajax_Test extends WP_Ajax_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
    }

    /** @return array{die: ?string, json: mixed} */
    private function post_duplicate(array $extra): array
    {
        $_POST = array_merge(array('nonce' => wp_create_nonce('dt_duplicate')), $extra);
        $this->_last_response = '';
        $die = null;
        try {
            $this->_handleAjax('dt_duplicate_tag');
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            $die = $e->getMessage();
        }
        return array('die' => $die, 'json' => json_decode((string) $this->_last_response, true));
    }

    private function tag_post_titles(): array
    {
        return wp_list_pluck(get_posts(array('post_type' => 'dynamic_tag', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC')), 'post_title');
    }

    public function test_duplicates_a_tag_with_its_content_priority_and_groups(): void
    {
        $term = wp_insert_term('Dup Group', 'tag_groups');
        $source = wp_insert_post(array('post_type' => 'dynamic_tag', 'post_status' => 'publish', 'post_title' => 'dup_source', 'post_content' => 'Hello copy'));
        update_post_meta($source, '_dt_priority', '7');
        wp_set_object_terms($source, array((int) $term['term_id']), 'tag_groups');

        $r = $this->post_duplicate(array('tag_id' => $source));

        $this->assertTrue($r['json']['success'] ?? false, 'duplicate must succeed; got ' . wp_json_encode($r));
        $new_id = (int) ($r['json']['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $new_id);
        $this->assertNotSame($source, $new_id);

        $copy = get_post($new_id);
        $this->assertSame('dynamic_tag', $copy->post_type);
        $this->assertSame('dup_source (Copy)', $copy->post_title);
        $this->assertSame('Hello copy', $copy->post_content);
        $this->assertSame('7', get_post_meta($new_id, '_dt_priority', true));
        $this->assertSame(array((int) $term['term_id']), array_map('intval', wp_get_object_terms($new_id, 'tag_groups', array('fields' => 'ids'))));
    }

    public function test_refuses_a_post_that_is_not_a_tag(): void
    {
        $page = self::factory()->post->create(array('post_title' => 'Just a post'));
        $before = $this->tag_post_titles();

        $r = $this->post_duplicate(array('tag_id' => $page));

        $this->assertFalse($r['json']['success'] ?? null, 'only dynamic_tag posts can be duplicated; got ' . wp_json_encode($r));
        $this->assertSame($before, $this->tag_post_titles());
    }

    public function test_a_request_without_a_tag_id_is_an_error_not_a_crash(): void
    {
        $r = $this->post_duplicate(array());
        $this->assertFalse($r['json']['success'] ?? null);
    }
}
