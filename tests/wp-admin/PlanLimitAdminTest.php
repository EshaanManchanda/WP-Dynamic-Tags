<?php
/**
 * The Free limit on the plugin's own screens (Add New, Duplicate, Import), and what the screens say about the plan.
 *
 *  - Add New, Duplicate and Import are people creating tags: they count and they are refused at 15 on Free
 *  - an imported file cannot carry the "created by another plugin" mark, and an import that updates a tag cannot change its mark
 *  - a sibling plugin's create_tag($data) still works next to a full list
 *  - All Tags shows "N of 15" on Free (and nothing on Paid), marks a tag that uses a Paid feature, and says so on its form and
 *    under its preview; the message of a refused Duplicate is the real one, not a generic line
 *
 * The suite runs as the Paid plan (tests/wp/bootstrap.php); this test sets Free itself.
 */
class DT_Plan_Limit_Admin_Test extends WP_Ajax_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Admin */
    private $admin;

    /** @var WP_Dynamic_Tags_Table_Manager */
    private $tags;

    /** @var WP_Dynamic_Tags_Table_Import_Export */
    private $io;

    public function set_up(): void
    {
        parent::set_up();
        remove_all_filters('pre_option_dt_plan');
        update_option('dt_plan', 'free');
        // _handleAjax() runs admin_init, where WordPress would ask wordpress.org for updates: no test talks to the network.
        foreach (array('_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes') as $update_check) {
            remove_action('admin_init', $update_check);
        }
        $this->admin = new WP_Dynamic_Tags_Admin();
        $this->tags = WP_Dynamic_Tags_Table_Manager::get_instance();
        $this->io = WP_Dynamic_Tags_Table_Import_Export::get_instance();
        $administrator = self::factory()->user->create(array('role' => 'administrator'));
        if (is_multisite()) {
            grant_super_admin($administrator);
        }
        wp_set_current_user($administrator);
        set_current_screen('dashboard');
    }

    public function tear_down(): void
    {
        $_GET = $_POST = $_REQUEST = array();
        set_current_screen('front');
        parent::tear_down();
    }

    // ------------------------------------------------------------------ helpers

    /** @return int[] */
    private function people_add(int $how_many, string $prefix = 'mine'): array
    {
        $ids = array();
        for ($i = 1; $i <= $how_many; $i++) {
            $id = $this->admin->save_tag(0, array('tag_name' => $prefix . ' ' . $i, 'content' => 'content ' . $i));
            $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
            $ids[] = $id;
        }
        return $ids;
    }

    private function code($result): string
    {
        return is_wp_error($result) ? $result->get_error_code() : 'ok';
    }

    private function page(string $method): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $this->admin->$method();
            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function meta(string $shortcode)
    {
        return WP_Dynamic_Tags_Database_Manager::get_instance()->get_tag_by_shortcode($shortcode)->meta_data;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function import(array $rows, array $options = array())
    {
        return $this->io->import_data(wp_json_encode(array('tags' => $rows)), 'json', $options);
    }

    // ------------------------------------------------------------------ Add New, Duplicate, Import count

    public function test_add_new_counts_and_is_refused_at_fifteen_with_the_real_message(): void
    {
        $this->people_add(15);

        $refused = $this->admin->save_tag(0, array('tag_name' => 'one too many', 'content' => 'x'));
        $this->assertSame('dt_tag_limit', $this->code($refused));
        $this->assertStringContainsString('Free plan allows 15', $refused->get_error_message());
        $this->assertSame(15, DT_License_Manager::count_capped_tags());
    }

    public function test_editing_a_tag_is_never_refused_by_the_limit(): void
    {
        $ids = $this->people_add(15);

        $this->assertTrue($this->admin->save_tag($ids[0], array('tag_name' => 'mine 1', 'content' => 'edited at the limit')));
        $this->assertSame('edited at the limit', $this->tags->get_tag($ids[0])->content);
    }

    public function test_duplicate_is_refused_at_the_limit_and_works_on_paid(): void
    {
        $ids = $this->people_add(15);

        $this->assertSame('dt_tag_limit', $this->code($this->admin->run_action('duplicate', $ids[0])));

        update_option('dt_plan', 'paid');
        $this->assertTrue($this->admin->run_action('duplicate', $ids[0]));
        $this->assertSame(16, DT_License_Manager::count_capped_tags());
    }

    public function test_a_refused_duplicate_shows_the_real_message_on_the_list(): void
    {
        $_GET['dt_error'] = 'dt_tag_limit';

        $html = $this->page('page_list');

        $this->assertStringContainsString('Free plan allows 15', $html);
        $this->assertStringContainsString('keeps working', $html);
        $this->assertStringNotContainsString('No more tags can be created', $html);
    }

    public function test_a_sibling_plugin_still_creates_tags_next_to_a_full_list(): void
    {
        $this->people_add(15);

        $id = $this->tags->create_tag(array('tag_name' => 'cg student 1', 'shortcode' => 'cg_student_1', 'content' => 'Ada'));

        $this->assertIsInt($id);
        $this->assertSame(15, DT_License_Manager::count_capped_tags());
        $this->assertSame('dt_tag_limit', $this->code($this->admin->save_tag(0, array('tag_name' => 'still full', 'content' => 'x'))));
    }

    public function test_an_import_counts_toward_the_limit_and_reports_each_refused_row(): void
    {
        $this->people_add(14);

        $result = $this->import(array(
            array('tag_name' => 'Imp one', 'shortcode' => 'imp_one', 'content' => 'a'),
            array('tag_name' => 'Imp two', 'shortcode' => 'imp_two', 'content' => 'b'),
            array('tag_name' => 'Imp three', 'shortcode' => 'imp_three', 'content' => 'c'),
        ));

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(1, $result['stats']['created']);
        $this->assertSame(2, $result['stats']['errors']);
        $this->assertStringContainsString('Free plan allows 15', $result['stats']['error_details'][0]['error']);
        $this->assertSame(array(2, 3), wp_list_pluck($result['stats']['error_details'], 'row'));
    }

    public function test_an_imported_file_cannot_claim_the_exemption(): void
    {
        $rows = array();
        for ($i = 1; $i <= 16; $i++) {
            $rows[] = array('tag_name' => 'Claim ' . $i, 'shortcode' => 'claim_' . $i, 'content' => 'x', 'meta_data' => array('dt_origin' => 'api', 'keep' => 'yes'));
        }

        $result = $this->import($rows);

        $this->assertSame(15, $result['stats']['created'], 'the mark in the file did not make the tags free to add');
        $this->assertSame(1, $result['stats']['errors']);
        $this->assertSame(array('keep' => 'yes'), $this->meta('claim_1'), 'the rest of the meta data is kept');
    }

    public function test_an_import_update_cannot_change_the_mark(): void
    {
        $this->tags->create_tag(array('tag_name' => 'Plugin tag', 'shortcode' => 'plug_tag', 'content' => 'old'));
        $this->tags->create_tag(array('tag_name' => 'Person tag', 'shortcode' => 'pers_tag', 'content' => 'old'), 'user');

        $result = $this->import(array(
            array('tag_name' => 'Plugin tag', 'shortcode' => 'plug_tag', 'content' => 'new', 'meta_data' => array('x' => '1')),
            array('tag_name' => 'Person tag', 'shortcode' => 'pers_tag', 'content' => 'new', 'meta_data' => array('x' => '2', 'dt_origin' => 'api')),
        ), array('update_existing' => true));

        $this->assertSame(2, $result['stats']['updated'], wp_json_encode($result['stats']['error_details']));
        $this->assertSame(array('x' => '1', 'dt_origin' => 'api'), $this->meta('plug_tag'), 'a plugin tag stays a plugin tag');
        $this->assertSame(array('x' => '2'), $this->meta('pers_tag'), 'a person\'s tag does not become a plugin tag');
    }

    // ------------------------------------------------------------------ what the screens say

    public function test_the_list_says_how_many_of_the_fifteen_are_used_on_free(): void
    {
        $this->people_add(3);
        $this->tags->create_tag(array('tag_name' => 'cg row', 'shortcode' => 'cg_row', 'content' => 'x'));

        $html = $this->page('page_list');

        $this->assertStringContainsString('3 of 15', $html);
        $this->assertStringContainsString('other plugins', $html, 'it says that tags other plugins create are not counted');
        $this->assertStringContainsString('page=dt-settings', $html, 'an administrator is linked to the License box');
        $this->assertStringContainsString('Activate a Paid key', $html);
        $this->assertStringNotContainsString('Ask an administrator', $html, 'and is not told to ask themselves');
    }

    public function test_the_list_warns_when_the_limit_is_reached(): void
    {
        $this->people_add(15);

        $html = $this->page('page_list');

        $this->assertStringContainsString('15 of 15', $html);
        $this->assertStringContainsString('notice-warning', $html);
    }

    public function test_a_delegate_is_not_sent_to_a_page_they_cannot_open(): void
    {
        // All Tags can be handed to a lower capability (filter dt_manage_capability); Settings, where the key goes, cannot.
        add_filter('dt_manage_capability', static fn() => 'edit_pages');
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $id = $this->tags->create_tag(array('tag_name' => 'Delegate loop', 'shortcode' => 'delegate_loop', 'content' => '[dt_loop source="x"]a[/dt_loop]'));
        $_GET['tag'] = (string) $id;
        $_REQUEST['tag'] = (string) $id;

        $list = $this->page('page_list');
        $form = $this->page('page_edit');
        remove_all_filters('dt_manage_capability');

        $this->assertStringContainsString('Free plan:', $list, 'the delegate still sees the plan');
        $this->assertStringNotContainsString('page=dt-settings', $list, 'but is not linked to a screen that would refuse them');
        $this->assertStringContainsString('render nothing on the Free plan', $form);
        $this->assertStringNotContainsString('page=dt-settings', $form);
        $this->assertStringContainsString('Ask an administrator', $list, 'and is told who can activate a key');
    }

    public function test_paid_sees_no_plan_note(): void
    {
        $this->people_add(3);
        update_option('dt_plan', 'paid');

        $html = $this->page('page_list');

        $this->assertStringNotContainsString('of 15', $html);
        $this->assertStringNotContainsString('Paid feature', $html);
    }

    public function test_the_new_tag_form_shows_the_counter(): void
    {
        $this->people_add(2);

        $this->assertStringContainsString('2 of 15', $this->page('page_edit'));
    }

    public function test_a_tag_that_uses_a_paid_feature_is_marked_on_free_only(): void
    {
        $this->tags->create_tag(array('tag_name' => 'Price', 'shortcode' => 'price_tag', 'content' => '{wc:price} {if:meta:flag=on}x{/if}'));
        $this->tags->create_tag(array('tag_name' => 'Plain', 'shortcode' => 'plain_tag', 'content' => 'just text {site_name}'));

        $free = $this->page('page_list');
        $this->assertSame(1, substr_count($free, 'Paid feature'), 'one badge: the tag that uses {wc:} and a field condition');

        update_option('dt_plan', 'paid');
        $this->assertStringNotContainsString('Paid feature', $this->page('page_list'));
    }

    public function test_the_form_of_such_a_tag_lists_what_does_not_render_on_free(): void
    {
        $id = $this->tags->create_tag(array('tag_name' => 'Loopy', 'shortcode' => 'loopy', 'content' => '[dt_loop source="query:post_type=post"]{item:title}[/dt_loop] {func:x}'));
        $_GET['tag'] = (string) $id;
        $_REQUEST['tag'] = (string) $id;

        $html = $this->page('page_edit');

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('[dt_loop]', $html);
        $this->assertStringContainsString('{func:}', $html);
        $this->assertStringContainsString('render nothing on the Free plan', $html);
    }

    public function test_the_preview_says_why_a_paid_feature_is_empty(): void
    {
        $_POST = array('nonce' => wp_create_nonce('dt_preview'), 'content' => 'a {func:x} b');
        $this->_last_response = '';
        try {
            $this->_handleAjax('dt_preview_tag');
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            // expected: wp_send_json_* ends the request
        }
        $json = json_decode((string) $this->_last_response, true);

        $this->assertTrue($json['success'] ?? false, (string) $this->_last_response);
        $this->assertSame('a  b', $json['data']['html'], 'the page would show nothing for the Paid token');
        $this->assertStringContainsString('{func:}', $json['data']['note']);
        $this->assertStringContainsString('Free plan', $json['data']['note']);

        $this->_last_response = '';
        $_POST = array('nonce' => wp_create_nonce('dt_preview'), 'content' => 'plain');
        try {
            $this->_handleAjax('dt_preview_tag');
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
        }
        $this->assertSame('', json_decode((string) $this->_last_response, true)['data']['note'], 'no note when nothing is affected');
    }
}
