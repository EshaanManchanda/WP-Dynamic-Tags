<?php
/**
 * Admin screens of WP Dynamic Tags: one top-level menu with the tag list, the add/edit form, groups, import/export,
 * settings and a tools screen with diagnostics.
 *
 * Replaces the screens of the post-based generation (the tag post type's own list and editor, the Table Manager page, the
 * Bulk Manager, Quick Create and the conflict screens). The old URLs redirect here (see redirect_target()).
 *
 * Every screen needs the capability from capability() (manage_options unless filter dt_manage_capability says otherwise),
 * every state-changing request carries a nonce, and all output is escaped.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Admin
{
    public const MENU        = 'dynamic-tags';
    public const PAGE_EDIT   = 'dt-tag-edit';
    public const PAGE_IMPORT = 'dt-import-export';
    public const PAGE_TOOLS  = 'dt-tools';

    /** @var WP_Dynamic_Tags_List_Table|null */
    private $list = null;

    /** @var WP_Error|null Why the form could not be saved, shown on the form that was submitted. */
    private $form_error = null;

    /** @var array<string,mixed>|null What was submitted, shown again so nothing typed is lost. */
    private $form_values = null;

    /**
     * The notice shown after a redirect: a fixed list keyed by a short code, never text taken from the URL.
     *
     * @return array{0:string,1:string}|null type and message
     */
    private static function notice_text(string $key): ?array
    {
        switch ($key) {
            case 'created':
                return array('success', __('Tag created.', 'wp-dynamic-tags'));
            case 'saved':
                return array('success', __('Tag saved.', 'wp-dynamic-tags'));
            case 'deleted':
                return array('success', __('Tag deleted.', 'wp-dynamic-tags'));
            case 'activated':
                return array('success', __('Tag activated.', 'wp-dynamic-tags'));
            case 'deactivated':
                return array('success', __('Tag deactivated.', 'wp-dynamic-tags'));
            case 'duplicated':
                return array('success', __('Tag duplicated. The copy is inactive until you activate it.', 'wp-dynamic-tags'));
            case 'bulk':
                return array('success', __('The action was applied to the selected tags.', 'wp-dynamic-tags'));
            case 'cleared':
                return array('success', __('Debug log cleared.', 'wp-dynamic-tags'));
        }
        return null;
    }

    private static function error_text(string $code): string
    {
        switch ($code) {
            case 'dt_reserved_shortcode':
                return __('That shortcode name is reserved for the plugin\'s own shortcodes.', 'wp-dynamic-tags');
            case 'dt_shortcode_conflict':
                return __('That shortcode name is already used by WordPress or another plugin.', 'wp-dynamic-tags');
            case 'dt_circular_reference':
                return __('The tag would render itself, directly or through other tags.', 'wp-dynamic-tags');
            case 'dt_tag_limit':
                return __('No more tags can be created.', 'wp-dynamic-tags');
            case 'tag_not_found':
                return __('That tag no longer exists.', 'wp-dynamic-tags');
            case 'validation_failed':
                return __('The action could not be completed because some values are not valid.', 'wp-dynamic-tags');
        }
        return __('The action could not be carried out.', 'wp-dynamic-tags');
    }

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'), 9);
        add_action('admin_init', array($this, 'redirect_old_urls'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_dt_preview_tag', array($this, 'ajax_preview'));
        add_action('wp_ajax_dt_table_statistics', array($this, 'ajax_statistics'));
        add_action('wp_ajax_dt_create_table', array($this, 'ajax_create_table'));
        add_action('wp_ajax_dt_check_table_health', array($this, 'ajax_check_table_health'));
    }

    // ------------------------------------------------------------------ small helpers

    public static function capability(): string
    {
        return (string) apply_filters('dt_manage_capability', 'manage_options');
    }

    public static function edit_url(int $id = 0): string
    {
        $url = admin_url('admin.php?page=' . self::PAGE_EDIT);
        return $id > 0 ? add_query_arg('tag', $id, $url) : $url;
    }

    /** A link that carries out one action on one tag; the nonce is bound to the action and the tag. */
    public static function action_url(string $action, int $id): string
    {
        $url = add_query_arg(array('page' => self::MENU, 'dt_action' => $action, 'tag' => $id), admin_url('admin.php'));
        return wp_nonce_url($url, 'dt_tag_' . $action . '_' . $id);
    }

    private function guard(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'), '', array('response' => 403));
        }
    }

    /**
     * The screens that go beyond tags (import and export, tools and the table checks, which hold the migration) stay with
     * administrators whatever dt_manage_capability says: they are site-level and some of their actions are destructive, and
     * the endpoints behind them (import, export, migration) always need manage_options. A delegate sees only what works.
     */
    private function guard_site(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'), '', array('response' => 403));
        }
    }

    private function manager(): WP_Dynamic_Tags_Table_Manager
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance();
    }

    /** @param array<string,string|int> $args */
    private function finish(array $args, string $page = self::MENU): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . $page)));
        exit;
    }

    // ------------------------------------------------------------------ menu and old URLs

    public function register_menu(): void
    {
        $cap = self::capability();

        $list = add_menu_page(__('Dynamic Tags', 'wp-dynamic-tags'), __('Dynamic Tags', 'wp-dynamic-tags'), $cap, self::MENU, array($this, 'page_list'), 'dashicons-tag', 30);
        add_submenu_page(self::MENU, __('All Tags', 'wp-dynamic-tags'), __('All Tags', 'wp-dynamic-tags'), $cap, self::MENU, array($this, 'page_list'));
        $edit = add_submenu_page(self::MENU, __('Add New Tag', 'wp-dynamic-tags'), __('Add New', 'wp-dynamic-tags'), $cap, self::PAGE_EDIT, array($this, 'page_edit'));
        add_submenu_page(self::MENU, __('Import / Export', 'wp-dynamic-tags'), __('Import / Export', 'wp-dynamic-tags'), 'manage_options', self::PAGE_IMPORT, array($this, 'page_import'));
        $tools = add_submenu_page(self::MENU, __('Tools and Diagnostics', 'wp-dynamic-tags'), __('Tools', 'wp-dynamic-tags'), 'manage_options', self::PAGE_TOOLS, array($this, 'page_tools'));

        // The requests that change something are handled before any output, so they can redirect.
        add_action('load-' . $list, array($this, 'load_list'));
        add_action('load-' . $edit, array($this, 'load_edit'));
        add_action('load-' . $tools, array($this, 'load_tools'));
    }

    /**
     * Where an old admin URL of the post-based generation goes, or null when the request is not one of them.
     *
     * @param string               $pagenow The admin script being loaded.
     * @param array<string,string> $query   The query string, already unslashed.
     */
    public static function redirect_target(string $pagenow, array $query): ?string
    {
        if (($query['post_type'] ?? '') !== 'dynamic_tag') {
            return null;
        }
        $page = $query['page'] ?? '';
        $pages = array(
            'dt-table-manager'       => self::MENU,
            'dt-quick-create'        => self::MENU,
            'dt-conflict-resolution' => self::PAGE_TOOLS,
            'dt-debug'               => self::PAGE_TOOLS,
            'dt-group-manager'       => 'dt-group-manager',
            'dt-import-export'       => self::PAGE_IMPORT,
            'dt-settings'            => 'dt-settings',
        );
        if ($pagenow === 'edit.php') {
            return $page === '' ? self::MENU : ($pages[$page] ?? self::MENU);
        }
        if ($pagenow === 'post-new.php') {
            return self::PAGE_EDIT;
        }
        return null;
    }

    public function redirect_old_urls(): void
    {
        global $pagenow;

        $query = array();
        foreach (array('post_type', 'page') as $key) {
            if (isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
                $query[$key] = sanitize_key(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            }
        }

        // post.php?post=ID: a tag post goes to the form of the row that carries it, or to the list
        if ($pagenow === 'post.php' && isset($_GET['post']) && get_post_type(absint($_GET['post'])) === 'dynamic_tag') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            global $wpdb;
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $row_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WP_Dynamic_Tags_Database_Manager::get_instance()->get_table_name() . ' WHERE post_id = %d', absint($_GET['post']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            wp_safe_redirect($row_id ? self::edit_url($row_id) : admin_url('admin.php?page=' . self::MENU));
            exit;
        }

        $target = self::redirect_target((string) $pagenow, $query);
        if ($target !== null) {
            wp_safe_redirect(admin_url('admin.php?page=' . $target));
            exit;
        }
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::MENU) === false && strpos($hook, 'dt-') === false) {
            return;
        }
        wp_enqueue_style('dt-tags-admin', plugins_url('assets/css/tags-admin.css', dirname(__DIR__) . '/wp-dynamic-tags.php'), array(), WP_DYNAMIC_TAGS_VERSION);
        wp_enqueue_script('dt-tags-admin', plugins_url('assets/js/tags-admin.js', dirname(__DIR__) . '/wp-dynamic-tags.php'), array('jquery'), WP_DYNAMIC_TAGS_VERSION, true);
        wp_localize_script('dt-tags-admin', 'dtAdmin', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('dt_table_manager'),
            'previewNonce' => wp_create_nonce('dt_preview'),
            'strings'      => array(
                'copied'          => __('Copied', 'wp-dynamic-tags'),
                'copyFailed'      => __('Copy failed', 'wp-dynamic-tags'),
                'previewing'      => __('Rendering...', 'wp-dynamic-tags'),
                'previewFailed'   => __('The preview could not be rendered.', 'wp-dynamic-tags'),
                'exporting'       => __('Exporting...', 'wp-dynamic-tags'),
                'exportFailed'    => __('The export failed.', 'wp-dynamic-tags'),
                'validating'      => __('Validating...', 'wp-dynamic-tags'),
                'importing'       => __('Importing...', 'wp-dynamic-tags'),
                'importFailed'    => __('The import failed.', 'wp-dynamic-tags'),
                'chooseFile'      => __('Choose a file first.', 'wp-dynamic-tags'),
                'confirmImport'   => __('Import this file? Tags with the same shortcode are changed only if you ticked "update existing".', 'wp-dynamic-tags'),
                'working'         => __('Working...', 'wp-dynamic-tags'),
                'requestFailed'   => __('The request failed.', 'wp-dynamic-tags'),
                'totalRecords'    => __('Records in the file', 'wp-dynamic-tags'),
                'created'         => __('Created', 'wp-dynamic-tags'),
                'updated'         => __('Updated', 'wp-dynamic-tags'),
                'skipped'         => __('Skipped', 'wp-dynamic-tags'),
                'errors'          => __('Errors', 'wp-dynamic-tags'),
                'valid'           => __('The file is valid.', 'wp-dynamic-tags'),
                'invalid'         => __('The file has problems:', 'wp-dynamic-tags'),
                'row'             => __('Row', 'wp-dynamic-tags'),
            ),
        ));
    }

    // ------------------------------------------------------------------ actions on tags

    /**
     * One action on one tag. Used by the row links and the bulk actions.
     *
     * @return true|WP_Error
     */
    public function run_action(string $action, int $id)
    {
        if (!current_user_can(self::capability())) {
            return new WP_Error('forbidden', __('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'));
        }
        $manager = $this->manager();
        $row = $id > 0 ? $manager->get_tag($id) : null;
        if (!$row) {
            return new WP_Error('tag_not_found', __('That tag no longer exists.', 'wp-dynamic-tags'));
        }

        switch ($action) {
            case 'activate':
            case 'deactivate':
                return $manager->update_tag($id, array('status' => $action === 'activate' ? 'active' : 'inactive'));
            case 'delete':
                return $manager->delete_tag($id);
            case 'duplicate':
                $created = $manager->create_tag(array(
                    'tag_name'    => $this->unused_name((string) $row->tag_name),
                    'content'     => (string) $row->content,
                    'description' => (string) $row->description,
                    'category_id' => (int) $row->category_id,
                    'priority'    => (int) $row->priority,
                    'status'      => 'inactive',
                ));
                return is_wp_error($created) ? $created : true;
        }
        return new WP_Error('invalid_action', __('Unknown action.', 'wp-dynamic-tags'));
    }

    /** "Name Copy", "Name Copy 2", ...: the first name that is free (names may not contain brackets). */
    private function unused_name(string $name): string
    {
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $candidate = $name . ' Copy';
        for ($n = 2; $db->tag_name_exists($candidate) && $n < 1000; $n++) {
            $candidate = $name . ' Copy ' . $n;
        }
        return $candidate;
    }

    /**
     * Creates or updates a tag from submitted form values (unslashed).
     *
     * @param array<string,mixed> $input
     * @return int|true|WP_Error The new id, true for an update, or why it failed.
     */
    public function save_tag(int $id, array $input)
    {
        if (!current_user_can(self::capability())) {
            return new WP_Error('forbidden', __('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'));
        }
        $status = isset($input['status']) ? (string) $input['status'] : 'active';
        $data = array(
            'tag_name'    => sanitize_text_field((string) ($input['tag_name'] ?? '')),
            'shortcode'   => sanitize_text_field((string) ($input['shortcode'] ?? '')),
            'content'     => (string) ($input['content'] ?? ''), // markup is the author's; the Tag Service filters it by capability
            'description' => sanitize_textarea_field((string) ($input['description'] ?? '')),
            'category_id' => absint($input['category_id'] ?? 0),
            'priority'    => (int) ($input['priority'] ?? 0),
            'status'      => in_array($status, array('active', 'inactive'), true) ? $status : 'active',
        );

        $manager = $this->manager();
        if ($id > 0) {
            if ($data['shortcode'] === '') {
                unset($data['shortcode']); // an empty field keeps the stored one
            }
            return $manager->update_tag($id, $data);
        }
        if ($data['shortcode'] === '') {
            unset($data['shortcode']); // generated from the name
        }
        return $manager->create_tag($data);
    }

    // ------------------------------------------------------------------ the list

    private function list_table(): WP_Dynamic_Tags_List_Table
    {
        if ($this->list === null) {
            if (!class_exists('WP_List_Table')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
            }
            require_once __DIR__ . '/class-tags-list-table.php';
            $this->list = new WP_Dynamic_Tags_List_Table();
        }
        return $this->list;
    }

    public function load_list(): void
    {
        $this->guard();

        // one action from a row link
        if (isset($_GET['dt_action'], $_GET['tag'])) {
            $action = sanitize_key(wp_unslash($_GET['dt_action']));
            $id = absint($_GET['tag']);
            check_admin_referer('dt_tag_' . $action . '_' . $id);
            $result = $this->run_action($action, $id);
            $this->finish(is_wp_error($result) ? array('dt_error' => $result->get_error_code()) : array('dt_notice' => $action === 'delete' ? 'deleted' : ($action === 'duplicate' ? 'duplicated' : $action . 'd')));
        }

        // a bulk action
        $bulk = $this->list_table()->current_action();
        if ($bulk) {
            check_admin_referer('bulk-tags');
            $ids = isset($_REQUEST['tag']) ? array_map('absint', (array) wp_unslash($_REQUEST['tag'])) : array();
            $failed = 0;
            foreach (array_filter($ids) as $id) {
                if (is_wp_error($this->run_action(sanitize_key($bulk), $id))) {
                    $failed++;
                }
            }
            $this->finish($failed ? array('dt_error' => 'validation_failed') : array('dt_notice' => 'bulk'));
        }

        // the search and filter form is a GET form: tidy the address bar
        if (!empty($_GET['_wp_http_referer'])) {
            wp_safe_redirect(remove_query_arg(array('_wp_http_referer', '_wpnonce'), esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '')))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tag content is code, not text: it is shown only through the renderer here and filtered by the Tag Service when it is saved
            exit;
        }
    }

    private function notices(): void
    {
        if (isset($_GET['dt_notice'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            $notice = self::notice_text(sanitize_key(wp_unslash($_GET['dt_notice']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            if ($notice) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
            }
        }
        if (isset($_GET['dt_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html(self::error_text(sanitize_key(wp_unslash($_GET['dt_error']))))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
        }
    }

    public function page_list(): void
    {
        $this->guard();
        $list = $this->list_table();
        $list->prepare_items();

        echo '<div class="wrap dt-wrap"><h1 class="wp-heading-inline">' . esc_html__('Dynamic Tags', 'wp-dynamic-tags') . '</h1> ';
        echo '<a href="' . esc_url(self::edit_url()) . '" class="page-title-action">' . esc_html__('Add New', 'wp-dynamic-tags') . '</a><hr class="wp-header-end">';
        $this->notices();
        $list->views();
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::MENU) . '">';
        $list->search_box(__('Search Tags', 'wp-dynamic-tags'), 'dt-search');
        $list->display();
        echo '</form></div>';
    }

    // ------------------------------------------------------------------ the form

    private function edit_id(): int
    {
        return isset($_REQUEST['tag']) ? absint($_REQUEST['tag']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
    }

    public function load_edit(): void
    {
        $this->guard();
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['dt_save_tag'])) {
            return;
        }

        $id = isset($_POST['tag_id']) ? absint($_POST['tag_id']) : 0;
        check_admin_referer('dt_save_tag_' . $id);

        $input = wp_unslash($_POST);
        $result = $this->save_tag($id, $input);
        if (is_wp_error($result)) {
            $this->form_error = $result;
            $this->form_values = $input;
            return;
        }
        $this->finish(array('dt_notice' => $id > 0 ? 'saved' : 'created'));
    }

    public function page_edit(): void
    {
        $this->guard();
        $id = $this->edit_id();
        $row = $id > 0 ? $this->manager()->get_tag($id) : null;

        echo '<div class="wrap dt-wrap"><h1>' . esc_html($id > 0 ? __('Edit Tag', 'wp-dynamic-tags') : __('Add New Tag', 'wp-dynamic-tags')) . '</h1>';
        if ($id > 0 && !$row) {
            echo '<div class="notice notice-error"><p>' . esc_html__('That tag no longer exists.', 'wp-dynamic-tags') . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::MENU)) . '">' . esc_html__('Back to all tags', 'wp-dynamic-tags') . '</a></p></div>';
            return;
        }
        if ($this->form_error) {
            echo '<div class="notice notice-error"><p>' . esc_html($this->form_error->get_error_message()) . '</p></div>';
        }

        $v = $this->form_values ?? ($row ? (array) $row : array());
        $v = array_merge(array('tag_name' => '', 'shortcode' => '', 'content' => '', 'description' => '', 'category_id' => 0, 'priority' => 0, 'status' => 'active'), $v);

        echo '<form method="post" action="' . esc_url($id > 0 ? self::edit_url($id) : self::edit_url()) . '" class="dt-form">';
        wp_nonce_field('dt_save_tag_' . $id);
        echo '<input type="hidden" name="dt_save_tag" value="1"><input type="hidden" name="tag_id" value="' . esc_attr((string) $id) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="dt-name">' . esc_html__('Name', 'wp-dynamic-tags') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="dt-name" name="tag_name" value="' . esc_attr((string) $v['tag_name']) . '" required maxlength="255">';
        echo '<p class="description">' . esc_html__('Letters, numbers, spaces, hyphens and underscores.', 'wp-dynamic-tags') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="dt-shortcode">' . esc_html__('Shortcode', 'wp-dynamic-tags') . '</label></th><td>';
        echo '<input type="text" class="regular-text code" id="dt-shortcode" name="shortcode" value="' . esc_attr((string) $v['shortcode']) . '" pattern="[A-Za-z0-9_\-]+" maxlength="255">';
        echo '<p class="description">' . esc_html__('Leave empty to create it from the name. Changing it breaks pages that use the old one.', 'wp-dynamic-tags') . '</p>';
        if ($id > 0) {
            echo '<p><code id="dt-shortcode-preview">[' . esc_html((string) $v['shortcode']) . ']</code> <button type="button" class="button-link dt-copy" data-copy="' . esc_attr('[' . $v['shortcode'] . ']') . '">' . esc_html__('Copy', 'wp-dynamic-tags') . '</button></p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="dt-category">' . esc_html__('Group', 'wp-dynamic-tags') . '</label></th><td><select id="dt-category" name="category_id"><option value="0">' . esc_html__('No group', 'wp-dynamic-tags') . '</option>';
        $terms = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
        foreach (is_wp_error($terms) ? array() : $terms as $term) {
            printf('<option value="%d"%s>%s</option>', (int) $term->term_id, selected((int) $v['category_id'], (int) $term->term_id, false), esc_html($term->name));
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="dt-status">' . esc_html__('Status', 'wp-dynamic-tags') . '</label></th><td><select id="dt-status" name="status">';
        printf('<option value="active"%s>%s</option>', selected($v['status'], 'active', false), esc_html__('Active', 'wp-dynamic-tags'));
        printf('<option value="inactive"%s>%s</option>', selected($v['status'], 'inactive', false), esc_html__('Inactive', 'wp-dynamic-tags'));
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="dt-priority">' . esc_html__('Priority', 'wp-dynamic-tags') . '</label></th><td><input type="number" id="dt-priority" name="priority" min="0" max="100" value="' . esc_attr((string) (int) $v['priority']) . '" class="small-text"></td></tr>';

        echo '<tr><th scope="row"><label for="dt-description">' . esc_html__('Description', 'wp-dynamic-tags') . '</label></th><td><textarea id="dt-description" name="description" rows="2" class="large-text">' . esc_textarea((string) $v['description']) . '</textarea></td></tr>';

        echo '<tr><th scope="row"><label for="dt-content">' . esc_html__('Content', 'wp-dynamic-tags') . '</label></th><td>';
        // WordPress's own editor, with its Visual and Code tabs (the tag post editor had them; WordPress remembers the tab the user
        // last used). wpautop stays on, as it was for tag posts: the Visual tab shows paragraphs but saves a single paragraph
        // without its <p> wrapper, so a tag such as a phone number can still be used inside a sentence.
        wp_editor((string) $v['content'], 'dt-content', array(
            'textarea_name'    => 'content',
            'textarea_rows'    => 12,
            'media_buttons'    => false,
            'drag_drop_upload' => false,
            'quicktags'        => true,
            'editor_class'     => 'code',
            'tinymce'          => array('height' => 260),
        ));
        echo '<p class="description">' . esc_html__('Use the Code tab for conditions that contain < or >: the Visual tab rewrites them.', 'wp-dynamic-tags') . '</p>';
        $placeholders = class_exists('WP_Dynamic_Tags_Placeholders') && WP_Dynamic_Tags_Placeholders::get_instance() ? WP_Dynamic_Tags_Placeholders::get_instance()->get_placeholder_names() : array();
        if ($placeholders) {
            echo '<details class="dt-placeholders"><summary>' . esc_html__('Placeholders you can use (click to insert)', 'wp-dynamic-tags') . '</summary><p>';
            foreach ($placeholders as $name) {
                echo '<button type="button" class="button-link dt-insert" data-insert="' . esc_attr($name) . '"><code>' . esc_html($name) . '</code></button> ';
            }
            echo '</p></details>';
        }
        echo '<p><button type="button" class="button" id="dt-preview-button">' . esc_html__('Preview', 'wp-dynamic-tags') . '</button> <span class="description">' . esc_html__('Rendered here with the current page context of this screen; scripts are not run.', 'wp-dynamic-tags') . '</span></p>';
        echo '<div id="dt-preview" hidden></div>';
        echo '</td></tr></tbody></table>';

        submit_button($id > 0 ? __('Save Tag', 'wp-dynamic-tags') : __('Create Tag', 'wp-dynamic-tags'));
        echo '</form></div>';
    }

    /**
     * The text a tag renders to, for the Value column of the list: the real render pipeline, as plain text, up to 500
     * characters. Blank when there is nothing to show or the render fails. The list is not a page, so no remote fetch is made
     * ({api:} answers that are already cached are used), nothing is counted, a failure of one tag stays with that tag, and
     * anything a shortcode prints is discarded.
     */
    public static function value_preview(object $tag): string
    {
        $content = (string) ($tag->content ?? '');

        if (strpbrk($content, '{[') === false) {
            // No placeholder, condition or shortcode in it: there is nothing to render, the text is the value. This keeps the
            // list fast on a site with many plain tags (rendering a tag costs a few milliseconds).
            $html = $content;
        } else {
            $no_fetch = static function () {
                return 0;
            };
            add_filter('dt_api_max_fetches', $no_fetch, PHP_INT_MAX);
            $level = ob_get_level();
            ob_start();
            try {
                $html = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->render_row($tag, array('cache' => 'false'));
            } catch (Throwable $e) {
                $html = '';
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                remove_filter('dt_api_max_fetches', $no_fetch, PHP_INT_MAX);
            }
        }

        $text = html_entity_decode(wp_strip_all_tags(is_string($html) ? $html : ''), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, 500);
    }

    /** Renders the submitted content the way a page would, for the preview on the form. */
    public function ajax_preview(): void
    {
        check_ajax_referer('dt_preview', 'nonce');
        if (!current_user_can(self::capability())) {
            wp_send_json_error(__('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'), 403);
        }

        $content = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tag content is code, not text: it is shown only through the renderer here and filtered by the Tag Service when it is saved
        $row = (object) array(
            'id' => -999999999, 'tag_name' => 'preview', 'shortcode' => '__preview__', 'content' => WP_Dynamic_Tags_Tag_Service::prepare_content($content),
            'description' => '', 'category_id' => 0, 'priority' => 0, 'usage_count' => 0, 'status' => 'active', 'meta_data' => '', 'post_id' => 0,
        );
        $html = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance()->render_row($row, array('cache' => 'false'));
        wp_send_json_success(array('html' => $html));
    }

    // ------------------------------------------------------------------ import / export

    public function page_import(): void
    {
        $this->guard_site();
        echo '<div class="wrap dt-wrap"><h1>' . esc_html__('Import / Export', 'wp-dynamic-tags') . '</h1>';

        echo '<h2>' . esc_html__('Export', 'wp-dynamic-tags') . '</h2><form id="dt-export-form"><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="export-format">' . esc_html__('Format', 'wp-dynamic-tags') . '</label></th><td><select id="export-format"><option value="json">JSON</option><option value="csv">CSV</option><option value="xml">XML</option><option value="sql">SQL</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="export-status">' . esc_html__('Status', 'wp-dynamic-tags') . '</label></th><td><select id="export-status"><option value="">' . esc_html__('All', 'wp-dynamic-tags') . '</option><option value="active">' . esc_html__('Active only', 'wp-dynamic-tags') . '</option><option value="inactive">' . esc_html__('Inactive only', 'wp-dynamic-tags') . '</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="export-category">' . esc_html__('Group', 'wp-dynamic-tags') . '</label></th><td><select id="export-category"><option value="">' . esc_html__('All groups', 'wp-dynamic-tags') . '</option>';
        $terms = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
        foreach (is_wp_error($terms) ? array() : $terms as $term) {
            printf('<option value="%d">%s</option>', (int) $term->term_id, esc_html($term->name));
        }
        echo '</select></td></tr></tbody></table><p><button type="button" id="dt-export-button" class="button button-primary">' . esc_html__('Export', 'wp-dynamic-tags') . '</button></p></form>';
        echo '<p class="description">' . esc_html__('Exports contain the full content of the tags, including any personal data they hold. Keep the file private.', 'wp-dynamic-tags') . '</p>';

        echo '<h2>' . esc_html__('Import', 'wp-dynamic-tags') . '</h2><form id="dt-import-form"><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="import-file">' . esc_html__('File', 'wp-dynamic-tags') . '</label></th><td><input type="file" id="import-file" accept=".json,.csv,.xml">';
        echo '<p class="description">' . esc_html__('JSON, CSV or XML, up to 5 MB.', 'wp-dynamic-tags') . ' <a href="' . esc_url(admin_url('admin.php?action=dt_download_sample_csv')) . '">' . esc_html__('Sample CSV', 'wp-dynamic-tags') . '</a></p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Options', 'wp-dynamic-tags') . '</th><td><label><input type="checkbox" id="update-existing" value="1"> ' . esc_html__('Update existing tags (same shortcode)', 'wp-dynamic-tags') . '</label><br><label><input type="checkbox" id="skip-duplicates" value="1" checked> ' . esc_html__('Skip duplicates', 'wp-dynamic-tags') . '</label></td></tr>';
        echo '</tbody></table><p><button type="button" id="dt-validate-import-button" class="button">' . esc_html__('Check the file', 'wp-dynamic-tags') . '</button> <button type="button" id="dt-import-button" class="button button-primary">' . esc_html__('Import', 'wp-dynamic-tags') . '</button></p></form>';
        echo '<div id="dt-import-results" role="status" aria-live="polite" hidden></div></div>';
    }

    // ------------------------------------------------------------------ tools and diagnostics

    public function load_tools(): void
    {
        $this->guard_site();
        if (isset($_POST['dt_clear_debug'])) {
            check_admin_referer('dt_clear_debug');
            WP_Dynamic_Tags_Debug::clear();
            $this->finish(array('dt_notice' => 'cleared'), self::PAGE_TOOLS);
        }
    }

    /** @param array<int,array{0:string,1:string}> $rows */
    private function table(array $rows): void
    {
        echo '<table class="widefat striped dt-facts" role="presentation"><tbody>';
        foreach ($rows as [$label, $value]) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every caller passes a value that is already escaped
        }
        echo '</tbody></table>';
    }

    public function page_tools(): void
    {
        $this->guard_site();
        global $wpdb, $shortcode_tags;
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $processor = WP_Dynamic_Tags_Table_Shortcode_Processor::get_instance();
        $engine = WP_Dynamic_Tags_Migration_Engine::get_instance();
        $table = $db->get_table_name();

        $total = (int) $db->get_tags_count(array('status' => ''));
        $active = (int) $db->get_tags_count(array('status' => 'active'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
        $orphans = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} t LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id WHERE t.post_id > 0 AND p.ID IS NULL");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $legacy_posts = $engine->count_legacy_posts();

        echo '<div class="wrap dt-wrap"><h1>' . esc_html__('Tools and Diagnostics', 'wp-dynamic-tags') . '</h1>';
        $this->notices();

        echo '<h2>' . esc_html__('Status', 'wp-dynamic-tags') . '</h2>';
        $this->table(array(
            array(__('Tags', 'wp-dynamic-tags'), esc_html(sprintf(/* translators: 1: total, 2: active */ __('%1$d (%2$d active)', 'wp-dynamic-tags'), $total, $active))),
            array(__('Shortcodes registered in this request', 'wp-dynamic-tags'), esc_html((string) count($processor->get_registered_shortcodes()))),
            array(__('Usage counting', 'wp-dynamic-tags'), esc_html(get_option('wp_dynamic_tags_track_usage', false) ? __('On', 'wp-dynamic-tags') : __('Off (Settings)', 'wp-dynamic-tags'))),
            array(__('Database table', 'wp-dynamic-tags'), esc_html($table) . ' &mdash; ' . esc_html($db->table_exists(true) ? __('exists', 'wp-dynamic-tags') : __('MISSING', 'wp-dynamic-tags'))),
            array(__('Versions', 'wp-dynamic-tags'), esc_html('plugin ' . WP_DYNAMIC_TAGS_VERSION . ', schema ' . $db->get_table_version() . ', WordPress ' . get_bloginfo('version') . ', PHP ' . PHP_VERSION)),
        ));

        // Names that belong to someone else, and tags whose shortcode is no longer ours
        echo '<h2>' . esc_html__('Shortcode names', 'wp-dynamic-tags') . '</h2>';
        $problems = array();
        foreach (WP_Dynamic_Tags_Plugin::get_shortcode_conflicts() as $name => $owner) {
            $problems[] = array('[' . $name . ']', esc_html(sprintf(/* translators: %s: plugin folder */ __('Not registered: the name belongs to %s.', 'wp-dynamic-tags'), $owner)));
        }
        foreach ($processor->all_active_tags() as $tag) {
            if (isset($shortcode_tags[$tag->shortcode]) && !WP_Dynamic_Tags_Plugin::shortcode_is_ours($tag->shortcode)) {
                $problems[] = array('[' . $tag->shortcode . ']', esc_html(sprintf(/* translators: %s: plugin folder */ __('Replaced by %s after this plugin registered it: that plugin\'s shortcode is shown on pages.', 'wp-dynamic-tags'), WP_Dynamic_Tags_Plugin::shortcode_owner($tag->shortcode))));
            }
        }
        if ($problems) {
            $this->table($problems);
        } else {
            echo '<p>' . esc_html__('No conflicts found.', 'wp-dynamic-tags') . '</p>';
        }

        echo '<h2>' . esc_html__('Rendering and debug log', 'wp-dynamic-tags') . '</h2>';
        $this->table(array(
            array(__('Times the render guard cut off a tag in this request', 'wp-dynamic-tags'), esc_html((string) WP_Dynamic_Tags_Table_Shortcode_Processor::guard_trips())),
            array(__('Debug mode', 'wp-dynamic-tags'), esc_html(WP_Dynamic_Tags_Debug::enabled() ? __('On', 'wp-dynamic-tags') : __('Off: define DT_DEBUG_MODE as true in wp-config.php, or use the dt_debug_mode filter', 'wp-dynamic-tags'))),
        ));
        $entries = array_reverse(WP_Dynamic_Tags_Debug::entries());
        if ($entries) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time (UTC)', 'wp-dynamic-tags') . '</th><th>' . esc_html__('Event', 'wp-dynamic-tags') . '</th><th>' . esc_html__('Tag', 'wp-dynamic-tags') . '</th><th>' . esc_html__('Type', 'wp-dynamic-tags') . '</th><th>' . esc_html__('Context', 'wp-dynamic-tags') . '</th></tr></thead><tbody>';
            foreach ($entries as $e) {
                echo '<tr><td>' . esc_html((string) ($e['time'] ?? '')) . '</td><td>' . esc_html((string) ($e['event'] ?? '')) . '</td><td>' . esc_html(trim((string) ($e['shortcode'] ?? '') . ' #' . (string) ($e['tag_id'] ?? ''))) . '</td><td>' . esc_html((string) ($e['error_type'] ?? '')) . '</td><td>' . esc_html((string) ($e['context'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table><form method="post" style="margin-top:8px">';
            wp_nonce_field('dt_clear_debug');
            submit_button(__('Clear the log', 'wp-dynamic-tags'), 'secondary', 'dt_clear_debug', false);
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Older tag posts', 'wp-dynamic-tags') . '</h2>';
        $capture = get_option(WP_Dynamic_Tags_Migration_Engine::OPTION_CAPTURE, array());
        $capture = is_array($capture) ? $capture : array();
        $this->table(array(
            array(__('Tag posts of earlier versions', 'wp-dynamic-tags'), esc_html((string) $legacy_posts)),
            array(__('Writes to them carried into the table', 'wp-dynamic-tags'), esc_html(isset($capture['count']) ? sprintf(/* translators: 1: count, 2: time */ __('%1$d, the last on %2$s', 'wp-dynamic-tags'), (int) $capture['count'], (string) ($capture['last_at'] ?? '')) : __('none', 'wp-dynamic-tags'))),
            array(__('Tags that point at a post that no longer exists', 'wp-dynamic-tags'), esc_html((string) $orphans)),
            array(__('Inactive tags', 'wp-dynamic-tags'), esc_html((string) ($total - $active))),
        ));

        echo '<h2>' . esc_html__('Moving older tag posts into the table', 'wp-dynamic-tags') . '</h2>';
        (new WP_Dynamic_Tags_Migration_Admin())->render_panel();

        echo '<h2>' . esc_html__('Database', 'wp-dynamic-tags') . '</h2>';
        echo '<p><button type="button" class="button" id="dt-check-health">' . esc_html__('Check the table', 'wp-dynamic-tags') . '</button> <button type="button" class="button" id="dt-create-table">' . esc_html__('Create the table if it is missing', 'wp-dynamic-tags') . '</button></p><pre id="dt-health-result" role="status" hidden></pre></div>';
    }

    // ------------------------------------------------------------------ AJAX that remain

    private function ajax_guard(): void
    {
        check_ajax_referer('dt_table_manager', 'nonce');
        if (!current_user_can('manage_options')) { // the table tools are site-level, like the import and export endpoints
            wp_send_json_error(__('You do not have permission to manage dynamic tags.', 'wp-dynamic-tags'), 403);
        }
    }

    public function ajax_statistics(): void
    {
        $this->ajax_guard();
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        wp_send_json_success(array(
            'total'    => (int) $db->get_tags_count(array('status' => '')),
            'active'   => (int) $db->get_tags_count(array('status' => 'active')),
            'inactive' => (int) $db->get_tags_count(array('status' => 'inactive')),
        ));
    }

    public function ajax_create_table(): void
    {
        $this->ajax_guard();
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();

        if ($db->table_exists(true)) {
            wp_send_json_success(array('message' => __('The table already exists.', 'wp-dynamic-tags'), 'already_exists' => true));
        }
        $result = $db->create_table();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        delete_option('wp_dynamic_tags_table_creation_error');
        wp_send_json_success(array('message' => __('The table was created.', 'wp-dynamic-tags'), 'created' => true));
    }

    public function ajax_check_table_health(): void
    {
        $this->ajax_guard();
        global $wpdb;
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $table = $db->get_table_name();
        $report = array('table_exists' => $db->table_exists(true));

        if ($report['table_exists']) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $columns = $wpdb->get_results("DESCRIBE {$table}");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing = is_array($columns) ? array_column($columns, 'Field') : array();
            $report['missing_columns'] = array_values(array_diff(array('id', 'tag_name', 'shortcode', 'content', 'status', 'created_date'), $existing));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $report['record_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $report['read_access'] = $wpdb->last_error === '';
            $report['status'] = $report['missing_columns'] === array() && $report['read_access'] ? 'healthy' : 'warning';
            $report['message'] = $report['status'] === 'healthy' ? __('The table is healthy.', 'wp-dynamic-tags') : __('The table exists but has problems.', 'wp-dynamic-tags');
        } else {
            $report['status'] = 'error';
            $report['message'] = __('The table does not exist.', 'wp-dynamic-tags');
            $error = get_option('wp_dynamic_tags_table_creation_error');
            if ($error) {
                $report['creation_error'] = (string) $error;
            }
        }
        wp_send_json_success($report);
    }
}
