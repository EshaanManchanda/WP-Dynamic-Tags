<?php
/**
 * The list of tags (a WP_List_Table): search, status and group filters, sorting, paging, bulk and row actions.
 *
 * Loaded on demand by WP_Dynamic_Tags_Admin once the WordPress list table base class exists. Every action link carries a
 * nonce; the actions themselves are carried out (and their capability checked) by WP_Dynamic_Tags_Admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_List_Table extends WP_List_Table
{
    /** @var array<int,string> term id => group name, loaded once */
    private $groups = null;

    public function __construct()
    {
        parent::__construct(array('singular' => 'tag', 'plural' => 'tags', 'ajax' => false));
    }

    public function get_columns()
    {
        return array(
            'cb'            => '<input type="checkbox" />',
            'tag_name'      => __('Name', 'wp-dynamic-tags'),
            'shortcode'     => __('Shortcode', 'wp-dynamic-tags'),
            'value'         => __('Value', 'wp-dynamic-tags'),
            'category'      => __('Group', 'wp-dynamic-tags'),
            'status'        => __('Status', 'wp-dynamic-tags'),
            'priority'      => __('Priority', 'wp-dynamic-tags'),
            'usage_count'   => __('Used', 'wp-dynamic-tags'),
            'modified_date' => __('Modified', 'wp-dynamic-tags'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'tag_name'      => array('tag_name', false),
            'priority'      => array('priority', false),
            'usage_count'   => array('usage_count', false),
            'modified_date' => array('modified_date', true),
        );
    }

    protected function get_bulk_actions()
    {
        return array(
            'activate'   => __('Activate', 'wp-dynamic-tags'),
            'deactivate' => __('Deactivate', 'wp-dynamic-tags'),
            'duplicate'  => __('Duplicate', 'wp-dynamic-tags'),
            'delete'     => __('Delete', 'wp-dynamic-tags'),
        );
    }

    /** The filters of the current request, already sanitized. @return array{status:string,category:?int,search:string} */
    public static function filters_from_request(): array
    {
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
        return array(
            'status'   => in_array($status, array('active', 'inactive'), true) ? $status : '',
            'category' => isset($_GET['category']) && $_GET['category'] !== '' ? absint($_GET['category']) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
            'search'   => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
        );
    }

    protected function get_views()
    {
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $filters = self::filters_from_request();
        $base = admin_url('admin.php?page=' . WP_Dynamic_Tags_Admin::MENU);
        $views = array();
        foreach (array('' => __('All', 'wp-dynamic-tags'), 'active' => __('Active', 'wp-dynamic-tags'), 'inactive' => __('Inactive', 'wp-dynamic-tags')) as $status => $label) {
            $count = (int) $db->get_tags_count(array('status' => $status));
            $url = $status === '' ? $base : add_query_arg('status', $status, $base);
            $views[$status === '' ? 'all' : $status] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $filters['status'] === $status ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                $count
            );
        }
        return $views;
    }

    public function prepare_items()
    {
        $db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $filters = self::filters_from_request();
        $per_page = max(1, (int) apply_filters('dt_list_per_page', 20));
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing

        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'modified_date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
        $order = isset($_GET['order']) && strtolower(sanitize_key(wp_unslash($_GET['order']))) === 'asc' ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: chooses what is shown, changes nothing
        if (!isset($this->get_sortable_columns()[$orderby])) {
            $orderby = 'modified_date';
        }

        $args = array('status' => $filters['status'], 'search' => $filters['search']);
        if ($filters['category'] !== null) {
            $args['category_id'] = $filters['category'];
        }

        $total = (int) $db->get_tags_count($args);
        $args += array('orderby' => $orderby, 'order' => $order, 'limit' => $per_page, 'offset' => ($page - 1) * $per_page);
        $this->items = $db->get_tags($args);

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'tag_name');
        $this->set_pagination_args(array('total_items' => $total, 'per_page' => $per_page, 'total_pages' => (int) ceil($total / $per_page)));
    }

    public function no_items()
    {
        esc_html_e('No tags found.', 'wp-dynamic-tags');
    }

    protected function extra_tablenav($which)
    {
        if ($which !== 'top') {
            return;
        }
        $selected = self::filters_from_request()['category'];
        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="dt-filter-group">' . esc_html__('Filter by group', 'wp-dynamic-tags') . '</label>';
        echo '<select name="category" id="dt-filter-group"><option value="">' . esc_html__('All groups', 'wp-dynamic-tags') . '</option>';
        foreach ($this->groups() as $id => $name) {
            printf('<option value="%d"%s>%s</option>', (int) $id, selected($selected, (int) $id, false), esc_html($name));
        }
        echo '</select>';
        submit_button(__('Filter', 'wp-dynamic-tags'), '', 'filter_action', false);
        echo '</div>';
    }

    /** @return array<int,string> */
    private function groups(): array
    {
        if ($this->groups === null) {
            $this->groups = array();
            $terms = get_terms(array('taxonomy' => 'tag_groups', 'hide_empty' => false));
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $this->groups[(int) $term->term_id] = $term->name;
                }
            }
        }
        return $this->groups;
    }

    // ------------------------------------------------------------------ columns

    /** The checkbox of a row, with a label that names the tag (for screen readers; a bulk action must say what it is about). */
    protected function column_cb($item)
    {
        $item = (object) $item;
        return sprintf(
            '<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input type="checkbox" name="tag[]" value="%1$d" id="cb-select-%1$d" />',
            (int) $item->id,
            /* translators: %s: tag name */
            esc_html(sprintf(__('Select %s', 'wp-dynamic-tags'), (string) ($item->tag_name ?? '')))
        );
    }

    protected function column_tag_name($item)
    {
        $id = (int) $item->id;
        $edit = WP_Dynamic_Tags_Admin::edit_url($id);
        $title = sprintf('<strong><a class="row-title" href="%s">%s</a></strong>', esc_url($edit), esc_html($item->tag_name));

        $toggle = $item->status === 'active' ? 'deactivate' : 'activate';
        $actions = array(
            'edit'      => sprintf('<a href="%s">%s</a>', esc_url($edit), esc_html__('Edit', 'wp-dynamic-tags')),
            'duplicate' => sprintf('<a href="%s">%s</a>', esc_url(WP_Dynamic_Tags_Admin::action_url('duplicate', $id)), esc_html__('Duplicate', 'wp-dynamic-tags')),
            $toggle     => sprintf('<a href="%s">%s</a>', esc_url(WP_Dynamic_Tags_Admin::action_url($toggle, $id)), $toggle === 'activate' ? esc_html__('Activate', 'wp-dynamic-tags') : esc_html__('Deactivate', 'wp-dynamic-tags')),
            'delete'    => sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
                esc_url(WP_Dynamic_Tags_Admin::action_url('delete', $id)),
                esc_attr(wp_json_encode(__('Delete this tag? Pages that use its shortcode will show nothing.', 'wp-dynamic-tags'))),
                esc_html__('Delete', 'wp-dynamic-tags')
            ),
        );
        return $title . $this->row_actions($actions);
    }

    protected function column_shortcode($item)
    {
        $code = '[' . $item->shortcode . ']';
        return sprintf(
            '<code>%s</code> <button type="button" class="button-link dt-copy" data-copy="%s" aria-label="%s">%s</button>',
            esc_html($code),
            esc_attr($code),
            /* translators: %s: shortcode */
            esc_attr(sprintf(__('Copy %s', 'wp-dynamic-tags'), $code)),
            esc_html__('Copy', 'wp-dynamic-tags')
        );
    }

    /** What the tag renders to, as plain text and cut short; blank when it cannot be rendered. */
    protected function column_value($item)
    {
        $badge = WP_Dynamic_Tags_Admin::paid_badge((string) $item->content); // a Paid feature on a Free site renders nothing: say so next to the empty value
        $text = WP_Dynamic_Tags_Admin::value_preview($item);
        if ($text === '') {
            return $badge;
        }
        $cut = mb_strlen($text) > 120;
        return esc_html(mb_substr($text, 0, 120)) . ($cut ? '&hellip;' : '') . $badge;
    }

    protected function column_category($item)
    {
        $groups = $this->groups();
        $id = (int) $item->category_id;
        return $id && isset($groups[$id]) ? esc_html($groups[$id]) : '&mdash;';
    }

    protected function column_status($item)
    {
        return $item->status === 'active'
            ? '<span class="dt-badge dt-badge-on">' . esc_html__('Active', 'wp-dynamic-tags') . '</span>'
            : '<span class="dt-badge dt-badge-off">' . esc_html__('Inactive', 'wp-dynamic-tags') . '</span>';
    }

    protected function column_priority($item)
    {
        return esc_html((string) (int) $item->priority);
    }

    /** Counting is off by default: the column shows a dash until it is switched on in the settings. */
    protected function column_usage_count($item)
    {
        if (!get_option('wp_dynamic_tags_track_usage', false)) {
            return '<span aria-label="' . esc_attr__('Usage counting is off', 'wp-dynamic-tags') . '">&mdash;</span>';
        }
        return esc_html(number_format_i18n((int) $item->usage_count));
    }

    protected function column_modified_date($item)
    {
        $time = strtotime((string) $item->modified_date);
        return $time ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time)) : '&mdash;';
    }

    protected function column_default($item, $column_name)
    {
        return '';
    }
}
