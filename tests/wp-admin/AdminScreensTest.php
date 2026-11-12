<?php
/**
 * The admin screens (admin/class-admin.php, admin/class-tags-list-table.php): one top-level menu replaces the tag post
 * type's own screens. Covers what is decided in code: where the old URLs go, who may do what and with which nonce, what the
 * form saves, what the list shows, that every screen escapes what it prints, and the preview endpoint.
 */
class DT_Redirect_Exception extends Exception
{
    /** @var string */
    public $location;

    public function __construct(string $location)
    {
        parent::__construct($location);
        $this->location = $location;
    }
}

class DT_Die_Exception extends Exception
{
}

// The Ajax base class (not WP_UnitTestCase) keeps the core update checks off admin_init for the classes that follow. Its
// wp_die() handler pops output buffers it did not start, so this class installs its own, at a later priority.
class DT_Admin_Screens_Test extends WP_Ajax_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Admin */
    private $admin;

    /** @var string */
    private $previous_log;

    public function set_up(): void
    {
        parent::set_up();
        $this->previous_log = (string) ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'dtadm')); // the debug log also writes to error_log
        $this->admin = new WP_Dynamic_Tags_Admin();
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        set_current_screen('dashboard');
        // wp_redirect() and wp_die() are followed by exit: turn both into exceptions the test can catch.
        add_filter('wp_redirect', static function ($location) {
            throw new DT_Redirect_Exception((string) $location);
        });
        $die = static function () {
            return static function ($message) {
                throw new DT_Die_Exception(is_scalar($message) ? (string) $message : 'died');
            };
        };
        add_filter('wp_die_handler', $die, 99);
        add_filter('wp_die_ajax_handler', $die, 99);
    }

    public function tear_down(): void
    {
        remove_all_filters('wp_redirect');
        remove_all_filters('dt_list_per_page');
        remove_all_filters('dt_debug_mode');
        $_GET = $_POST = $_REQUEST = array();
        $GLOBALS['pagenow'] = 'index.php';
        set_current_screen('front');
        ini_set('error_log', $this->previous_log);
        parent::tear_down();
    }

    private function tag(string $code, array $extra = array()): int
    {
        $id = WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array_merge(array('tag_name' => $code, 'shortcode' => $code, 'content' => 'content of ' . $code), $extra));
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        return $id;
    }

    private function row(int $id): ?object
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag($id) ?: null;
    }

    /** Runs $work and returns the redirect location it ended with, or the die message, or null. */
    private function outcome(callable $work): ?string
    {
        try {
            $work();
        } catch (DT_Redirect_Exception $e) {
            return $e->location;
        } catch (DT_Die_Exception $e) {
            return 'DIE: ' . $e->getMessage();
        }
        return null;
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

    // ------------------------------------------------------------------ the post type is only a store now

    public function test_the_tag_post_type_has_no_screens_of_its_own(): void
    {
        $type = get_post_type_object('dynamic_tag');
        $this->assertNotNull($type, 'sibling plugins check post_type_exists(): it must stay registered');
        $this->assertFalse($type->show_ui);
        $this->assertFalse($type->show_in_menu);
        $this->assertFalse($type->public);
    }

    // ------------------------------------------------------------------ old URLs

    /** @return array<string,array{0:string,1:array<string,string>,2:?string}> */
    public static function old_urls(): array
    {
        return array(
            'the old list'            => array('edit.php', array('post_type' => 'dynamic_tag'), 'dynamic-tags'),
            'the Table Manager'       => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-table-manager'), 'dynamic-tags'),
            'Quick Create'            => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-quick-create'), 'dynamic-tags'),
            'conflict resolution'     => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-conflict-resolution'), 'dt-tools'),
            'the old debug page'      => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-debug'), 'dt-tools'),
            'groups'                  => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-group-manager'), 'dt-group-manager'),
            'import and export'       => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-import-export'), 'dt-import-export'),
            'settings'                => array('edit.php', array('post_type' => 'dynamic_tag', 'page' => 'dt-settings'), 'dt-settings'),
            'add new'                 => array('post-new.php', array('post_type' => 'dynamic_tag'), 'dt-tag-edit'),
            'another post type'       => array('edit.php', array('post_type' => 'page'), null),
            'another script'          => array('index.php', array('post_type' => 'dynamic_tag'), null),
        );
    }

    /** @dataProvider old_urls */
    public function test_old_admin_urls_lead_to_the_new_screens(string $pagenow, array $query, ?string $page): void
    {
        $this->assertSame($page, WP_Dynamic_Tags_Admin::redirect_target($pagenow, $query));
    }

    public function test_opening_an_old_url_redirects_there(): void
    {
        $GLOBALS['pagenow'] = 'edit.php';
        $_GET = array('post_type' => 'dynamic_tag', 'page' => 'dt-settings');

        $location = $this->outcome(function () {
            $this->admin->redirect_old_urls();
        });

        $this->assertNotNull($location, 'the request must be redirected');
        $this->assertStringContainsString('admin.php?page=dt-settings', (string) $location);
    }

    // ------------------------------------------------------------------ saving

    public function test_the_form_creates_and_updates_a_tag(): void
    {
        $created = $this->admin->save_tag(0, array('tag_name' => 'Form Tag', 'shortcode' => '', 'content' => 'hello {site_name}', 'description' => 'd', 'category_id' => '0', 'priority' => '7', 'status' => 'active'));
        $this->assertIsInt($created);
        $row = $this->row($created);
        $this->assertSame('form_tag', $row->shortcode, 'an empty shortcode is created from the name');
        $this->assertSame('7', (string) $row->priority);

        $this->assertTrue($this->admin->save_tag($created, array('tag_name' => 'Form Tag', 'shortcode' => '', 'content' => 'changed', 'status' => 'nonsense')));
        $row = $this->row($created);
        $this->assertSame('changed', $row->content);
        $this->assertSame('form_tag', $row->shortcode, 'an empty shortcode field keeps the stored one');
        $this->assertSame('active', $row->status, 'an unknown status falls back to active');
    }

    public function test_the_form_reports_why_it_could_not_save(): void
    {
        $result = $this->admin->save_tag(0, array('tag_name' => 'Reserved', 'shortcode' => 'dt_group', 'content' => 'x'));
        $this->assertWPError($result);
        $this->assertSame('dt_reserved_shortcode', $result->get_error_code());
    }

    public function test_saving_needs_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $result = $this->admin->save_tag(0, array('tag_name' => 'Nope', 'content' => 'x'));
        $this->assertWPError($result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertNull(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('nope'));
    }

    public function test_the_form_post_needs_a_valid_nonce(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $_REQUEST = array('dt_save_tag' => '1', 'tag_id' => '0', 'tag_name' => 'No Nonce', 'content' => 'x', '_wpnonce' => 'wrong');

        $message = $this->outcome(function () {
            $this->admin->load_edit();
        });

        $this->assertStringStartsWith('DIE:', (string) $message);
        $this->assertNull(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('no_nonce'));
    }

    public function test_a_valid_form_post_creates_the_tag_and_redirects_to_the_list(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('dt_save_tag' => '1', 'tag_id' => '0', 'tag_name' => 'Posted Tag', 'content' => 'posted', '_wpnonce' => wp_create_nonce('dt_save_tag_0'));
        $_REQUEST = $_POST;

        $location = $this->outcome(function () {
            $this->admin->load_edit();
        });

        $this->assertStringContainsString('dt_notice=created', (string) $location);
        $this->assertNotNull(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('posted_tag'));
    }

    // ------------------------------------------------------------------ actions

    public function test_duplicate_makes_an_inactive_copy_with_its_own_name_and_shortcode(): void
    {
        $group = wp_insert_term('Dup Group', 'tag_groups');
        $id = $this->tag('dup_src', array('priority' => 9, 'category_id' => (int) $group['term_id'], 'description' => 'kept'));

        $this->assertTrue($this->admin->run_action('duplicate', $id));
        $this->assertTrue($this->admin->run_action('duplicate', $id), 'a second copy gets the next free name');

        $first = WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_name('dup_src Copy');
        $second = WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_name('dup_src Copy 2');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('inactive', $first->status);
        $this->assertSame('content of dup_src', $first->content);
        $this->assertSame('9', (string) $first->priority);
        $this->assertSame((string) $group['term_id'], (string) $first->category_id);
        $this->assertNotSame($first->shortcode, $second->shortcode);
        $this->assertNotSame('dup_src', $first->shortcode);
    }

    public function test_activate_deactivate_and_delete(): void
    {
        $id = $this->tag('act_probe');

        $this->assertTrue($this->admin->run_action('deactivate', $id));
        $this->assertSame('inactive', $this->row($id)->status);
        $this->assertTrue($this->admin->run_action('activate', $id));
        $this->assertSame('active', $this->row($id)->status);
        $this->assertTrue($this->admin->run_action('delete', $id));
        $this->assertNull($this->row($id));

        $this->assertSame('tag_not_found', $this->admin->run_action('delete', $id)->get_error_code());
        $this->assertSame('invalid_action', $this->admin->run_action('explode', $this->tag('act_probe2'))->get_error_code());
    }

    public function test_actions_need_the_capability(): void
    {
        $id = $this->tag('cap_probe');
        wp_set_current_user(self::factory()->user->create(array('role' => 'author')));

        $this->assertSame('forbidden', $this->admin->run_action('delete', $id)->get_error_code());
        $this->assertNotNull($this->row($id));
    }

    public function test_a_row_link_needs_its_own_nonce(): void
    {
        $id = $this->tag('nonce_probe');
        $_GET = $_REQUEST = array('page' => 'dynamic-tags', 'dt_action' => 'delete', 'tag' => (string) $id, '_wpnonce' => wp_create_nonce('dt_tag_activate_' . $id)); // a nonce for another action

        $message = $this->outcome(function () {
            $this->admin->load_list();
        });

        $this->assertStringStartsWith('DIE:', (string) $message);
        $this->assertNotNull($this->row($id), 'the tag must still exist');

        $_GET['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('dt_tag_delete_' . $id);
        $location = $this->outcome(function () {
            $this->admin->load_list();
        });
        $this->assertStringContainsString('dt_notice=deleted', (string) $location);
        $this->assertNull($this->row($id));
    }

    public function test_a_bulk_action_needs_the_bulk_nonce(): void
    {
        $a = $this->tag('bulk_a');
        $b = $this->tag('bulk_b');
        $_GET = $_REQUEST = array('page' => 'dynamic-tags', 'action' => 'deactivate', 'tag' => array((string) $a, (string) $b), '_wpnonce' => 'wrong');
        $this->assertStringStartsWith('DIE:', (string) $this->outcome(function () {
            $this->admin->load_list();
        }));
        $this->assertSame('active', $this->row($a)->status);

        $_GET['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('bulk-tags');
        $this->assertStringContainsString('dt_notice=bulk', (string) $this->outcome(function () {
            $this->admin->load_list();
        }));
        $this->assertSame('inactive', $this->row($a)->status);
        $this->assertSame('inactive', $this->row($b)->status);
    }

    // ------------------------------------------------------------------ the list

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->tag('list_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i > 10 ? array('status' => 'inactive') : array());
        }
        $this->tag('other_thing');
        add_filter('dt_list_per_page', static fn() => 5);
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once dirname(__DIR__, 2) . '/admin/class-tags-list-table.php';

        $_GET = array('s' => 'list_', 'orderby' => 'tag_name', 'order' => 'asc');
        $table = new WP_Dynamic_Tags_List_Table();
        $table->prepare_items();
        $this->assertSame(12, (int) $table->get_pagination_arg('total_items'), 'the search matches the shortcode');
        $this->assertSame(3, (int) $table->get_pagination_arg('total_pages'));
        $this->assertSame(array('list_01', 'list_02', 'list_03', 'list_04', 'list_05'), wp_list_pluck($table->items, 'tag_name'));

        $_GET = array('s' => 'list_', 'status' => 'inactive');
        $table = new WP_Dynamic_Tags_List_Table();
        $table->prepare_items();
        $this->assertSame(array('list_11', 'list_12'), array_values(array_intersect(wp_list_pluck($table->items, 'tag_name'), array('list_11', 'list_12'))));
        $this->assertSame(2, (int) $table->get_pagination_arg('total_items'));

        $_GET = array('orderby' => 'nonsense; DROP TABLE x', 'order' => 'sideways', 'paged' => 'abc');
        $table = new WP_Dynamic_Tags_List_Table();
        $table->prepare_items();
        $this->assertNotEmpty($table->items, 'an unknown sort column or order falls back instead of reaching the query');
    }

    // ------------------------------------------------------------------ escaping

    public function test_every_screen_escapes_what_it_prints(): void
    {
        $evil = '"><img src=x onerror=alert(1)>';
        // The facade only accepts letters, digits, spaces, hyphens and underscores in a name, but an import, a migration or an
        // older row can hold anything: insert the row the way the data layer does, without that validation.
        $id = WP_Dynamic_Tags_Database_Manager::get_instance()->insert_tag(array(
            'tag_name' => 'Escape Probe" autofocus onfocus="alert(3)', 'shortcode' => 'escape_probe', 'description' => $evil,
            'content' => '</textarea><script>alert(1)</script>',
        ));
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        $_GET = array('page' => 'dt-tag-edit', 'tag' => (string) $id);
        $_REQUEST = $_GET;

        $edit = $this->page('page_edit');
        $this->assertStringContainsString('Escape Probe', $edit);
        $this->assertStringNotContainsString('</textarea><script>', $edit, 'the content cannot break out of its textarea');
        $this->assertStringNotContainsString('<img src=x onerror', $edit, 'the description is escaped');
        $this->assertStringNotContainsString('" autofocus onfocus=', $edit, 'the name cannot break out of its attribute');

        $_GET = array('page' => 'dynamic-tags', 'dt_notice' => '<script>alert(21)</script>', 'dt_error' => '<script>alert(22)</script>');
        $list = $this->page('page_list');
        $this->assertStringContainsString('Escape Probe', $list);
        $this->assertStringNotContainsString('" autofocus onfocus=', $list, 'the list escapes the name too');
        $this->assertStringNotContainsString('alert(21', $list, 'a notice code from the URL is looked up, its text is never shown');
        $this->assertStringNotContainsString('alert(22', $list, 'an error code from the URL is looked up, its text is never shown');
    }

    public function test_the_screens_need_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        foreach (array('page_list', 'page_edit', 'page_import', 'page_tools') as $method) {
            $message = $this->outcome(function () use ($method) {
                $this->page($method);
            });
            $this->assertStringStartsWith('DIE:', (string) $message, $method . ' must refuse an editor');
        }
    }

    public function test_the_other_screens_render_without_warnings(): void
    {
        $this->tag('render_probe');
        add_filter('dt_debug_mode', '__return_true');
        WP_Dynamic_Tags_Debug::log('render_guard', array('tag_id' => 1, 'shortcode' => 'render_probe', 'error_type' => 'cycle'));

        $warnings = array();
        set_error_handler(static function ($no, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            $list = $this->page('page_list');
            $tools = $this->page('page_tools');
            $import = $this->page('page_import');
            $new = $this->page('page_edit');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(array(), $warnings);
        $this->assertStringContainsString('render_probe', $list);
        $this->assertStringContainsString('render_guard', $tools, 'the debug log is shown when debug mode is on');
        $this->assertStringContainsString('dt-export-button', $import);
        $this->assertStringContainsString('dt_save_tag', $new);
    }

    public function test_clearing_the_debug_log_needs_its_nonce_and_the_capability(): void
    {
        add_filter('dt_debug_mode', '__return_true');
        WP_Dynamic_Tags_Debug::log('render_guard', array('tag_id' => 1));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $_REQUEST = array('dt_clear_debug' => '1', '_wpnonce' => 'wrong');

        $this->assertStringStartsWith('DIE:', (string) $this->outcome(function () {
            $this->admin->load_tools();
        }));
        $this->assertNotEmpty(WP_Dynamic_Tags_Debug::entries(), 'a request without a valid nonce must not clear the log');

        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $_POST = $_REQUEST = array('dt_clear_debug' => '1', '_wpnonce' => wp_create_nonce('dt_clear_debug')); // a valid nonce of the editor
        $this->assertStringStartsWith('DIE:', (string) $this->outcome(function () {
            $this->admin->load_tools();
        }));
        $this->assertNotEmpty(WP_Dynamic_Tags_Debug::entries(), 'an editor must not clear the log');

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $_POST = $_REQUEST = array('dt_clear_debug' => '1', '_wpnonce' => wp_create_nonce('dt_clear_debug'));
        $this->assertStringContainsString('dt_notice=cleared', (string) $this->outcome(function () {
            $this->admin->load_tools();
        }));
        $this->assertSame(array(), WP_Dynamic_Tags_Debug::entries());
    }

    // ------------------------------------------------------------------ preview

    public function test_the_preview_renders_the_content_through_the_real_pipeline(): void
    {
        $_POST = array('nonce' => wp_create_nonce('dt_preview'), 'content' => 'Welcome to {site_name|upper}');
        $_REQUEST = $_POST;

        $level = ob_get_level();
        ob_start();
        try {
            $this->admin->ajax_preview();
        } catch (DT_Die_Exception $e) {
            // expected: wp_send_json_* ends the request
        }
        $response = (string) ob_get_contents();
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $json = json_decode($response, true);

        $this->assertTrue($json['success'] ?? false, $response);
        $this->assertSame('Welcome to ' . strtoupper(get_bloginfo('name')), $json['data']['html']);
    }

    public function test_the_preview_refuses_a_request_without_the_nonce(): void
    {
        $_POST = array('nonce' => 'wrong', 'content' => 'x');
        $_REQUEST = $_POST;

        // check_ajax_referer() dies with -1; a request that got through would die with the JSON of wp_send_json_success().
        $this->assertSame('DIE: -1', $this->outcome(function () {
            ob_start();
            try {
                $this->admin->ajax_preview();
            } finally {
                ob_end_clean();
            }
        }));
    }

    public function test_translated_notice_text_is_escaped(): void
    {
        add_filter('gettext', static fn($translated, $text) => $text === 'Tag saved.' ? '<img src=x onerror=alert(1)>' : $translated, 10, 2);
        $_GET = array('page' => 'dynamic-tags', 'dt_notice' => 'saved');

        $html = $this->page('page_list');

        $this->assertStringContainsString('&lt;img', $html, 'the notice must be shown');
        $this->assertStringNotContainsString('<img src=x', $html, 'a translation can contain markup; it must be escaped, not echoed');
    }
}
