<?php
/**
 * Tag Groups Manager for WP Dynamic Tags
 *
 * Handles hierarchical tag organization with custom taxonomy
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Groups {

    private $post_type = 'dynamic_tag';
    private $taxonomy = 'tag_groups';

    public function __construct() {
        // Register taxonomy immediately to ensure it's available for AJAX operations
        $this->register_taxonomy();

        add_action('admin_menu', array($this, 'add_admin_menu'), 15);
        add_action('wp_ajax_dt_create_group', array($this, 'ajax_create_group'));
        add_action('wp_ajax_dt_edit_group', array($this, 'ajax_edit_group'));
        add_action('wp_ajax_dt_delete_group', array($this, 'ajax_delete_group'));
        add_action('wp_ajax_dt_get_group_details', array($this, 'ajax_get_group_details'));
        add_action('wp_ajax_dt_get_group_edit_data', array($this, 'ajax_get_group_edit_data'));

        // A tag is a row of the table and its group is the row's category_id: when a group goes, whoever removed it (this page,
        // WP-CLI, another plugin), its tags are released instead of keeping the id of a group that no longer exists.
        add_action('delete_' . $this->taxonomy, array($this, 'release_tags'), 10, 1);

        // Migration hook
        add_action('admin_init', array($this, 'maybe_migrate_categories'));
    }

    /**
     * Register custom taxonomy for tag groups
     */
    public function register_taxonomy() {
        $labels = array(
            'name'                       => __('Tag Groups', 'wp-dynamic-tags'),
            'singular_name'              => __('Tag Group', 'wp-dynamic-tags'),
            'menu_name'                  => __('Groups', 'wp-dynamic-tags'),
            'all_items'                  => __('All Groups', 'wp-dynamic-tags'),
            'parent_item'                => __('Parent Group', 'wp-dynamic-tags'),
            'parent_item_colon'          => __('Parent Group:', 'wp-dynamic-tags'),
            'new_item_name'              => __('New Group Name', 'wp-dynamic-tags'),
            'add_new_item'               => __('Add New Group', 'wp-dynamic-tags'),
            'edit_item'                  => __('Edit Group', 'wp-dynamic-tags'),
            'update_item'                => __('Update Group', 'wp-dynamic-tags'),
            'view_item'                  => __('View Group', 'wp-dynamic-tags'),
            'separate_items_with_commas' => __('Separate groups with commas', 'wp-dynamic-tags'),
            'add_or_remove_items'        => __('Add or remove groups', 'wp-dynamic-tags'),
            'choose_from_most_used'      => __('Choose from the most used', 'wp-dynamic-tags'),
            'popular_items'              => __('Popular Groups', 'wp-dynamic-tags'),
            'search_items'               => __('Search Groups', 'wp-dynamic-tags'),
            'not_found'                  => __('Not Found', 'wp-dynamic-tags'),
            'no_terms'                   => __('No groups', 'wp-dynamic-tags'),
            'items_list'                 => __('Groups list', 'wp-dynamic-tags'),
            'items_list_navigation'      => __('Groups list navigation', 'wp-dynamic-tags'),
        );

        $args = array(
            'labels'                     => $labels,
            'hierarchical'               => true,
            'public'                     => false,
            'show_ui'                    => false, // Hidden: Use unified Groups interface instead
            'show_admin_column'          => false, // We'll handle this manually
            'show_in_nav_menus'          => false,
            'show_tagcloud'              => false,
            'query_var'                  => false,
            'rewrite'                    => false,
            'show_in_rest'               => false,
            'capabilities'               => array(
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'manage_options',
            ),
            'meta_box_cb'                => false, // We'll handle meta box manually
        );

        register_taxonomy($this->taxonomy, array($this->post_type), $args);
    }

    /**
     * Add Groups admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            WP_Dynamic_Tags_Admin::MENU,
            __('Groups', 'wp-dynamic-tags'),
            __('Groups', 'wp-dynamic-tags'),
            'manage_options',
            'dt-group-manager',
            array($this, 'group_manager_page')
        );
    }

    /**
     * Groups admin page (unified interface)
     */
    public function group_manager_page() {
        $this->group_counts = null;

        // Get all groups organized hierarchically
        $groups = $this->get_hierarchical_groups();
        $colors = $this->get_group_colors();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Dynamic Tags - Groups', 'wp-dynamic-tags'); ?></h1>

            <?php $this->show_notices(); ?>

            <div class="dt-group-manager-container" style="display: flex; gap: 20px;">
                <!-- Groups Tree -->
                <div class="dt-groups-tree" style="flex: 1; min-width: 300px;">
                    <div class="card">
                        <h2><?php esc_html_e('Tag Groups', 'wp-dynamic-tags'); ?></h2>

                        <div class="dt-tree-actions" style="margin-bottom: 15px;">
                            <button type="button" id="dt-add-root-group" class="button button-primary">
                                <span class="dashicons dashicons-plus"></span>
                                <?php esc_html_e('Add Root Group', 'wp-dynamic-tags'); ?>
                            </button>
                            <button type="button" id="dt-expand-all" class="button">
                                <?php esc_html_e('Expand All', 'wp-dynamic-tags'); ?>
                            </button>
                            <button type="button" id="dt-collapse-all" class="button">
                                <?php esc_html_e('Collapse All', 'wp-dynamic-tags'); ?>
                            </button>
                        </div>

                        <div id="dt-groups-tree" class="dt-tree-container">
                            <?php $this->render_groups_tree($groups); ?>
                        </div>
                    </div>
                </div>

                <!-- Group Details & Tag List -->
                <div class="dt-group-details" style="flex: 2; min-width: 400px;">
                    <div class="card">
                        <h2 id="dt-details-title"><?php esc_html_e('Group Details', 'wp-dynamic-tags'); ?></h2>

                        <div id="dt-group-info" style="display: none;">
                            <div id="dt-group-form">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="group-name"><?php esc_html_e('Group Name', 'wp-dynamic-tags'); ?></label>
                                        </th>
                                        <td>
                                            <input type="text" id="group-name" class="regular-text" placeholder="<?php esc_attr_e('Enter group name...', 'wp-dynamic-tags'); ?>">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="group-description"><?php esc_html_e('Description', 'wp-dynamic-tags'); ?></label>
                                        </th>
                                        <td>
                                            <textarea id="group-description" class="large-text" rows="3" placeholder="<?php esc_attr_e('Optional description...', 'wp-dynamic-tags'); ?>"></textarea>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="group-color"><?php esc_html_e('Color', 'wp-dynamic-tags'); ?></label>
                                        </th>
                                        <td>
                                            <input type="color" id="group-color" value="#2271b1">
                                            <p class="description"><?php esc_html_e('Choose a color for visual identification', 'wp-dynamic-tags'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="group-parent"><?php esc_html_e('Parent Group', 'wp-dynamic-tags'); ?></label>
                                        </th>
                                        <td>
                                            <select id="group-parent">
                                                <option value="0"><?php esc_html_e('None (Root Group)', 'wp-dynamic-tags'); ?></option>
                                                <?php $this->render_group_options($groups); ?>
                                            </select>
                                        </td>
                                    </tr>
                                </table>

                                <p class="submit">
                                    <button type="button" id="dt-save-group" class="button button-primary">
                                        <?php esc_html_e('Save Group', 'wp-dynamic-tags'); ?>
                                    </button>
                                    <button type="button" id="dt-cancel-group" class="button">
                                        <?php esc_html_e('Cancel', 'wp-dynamic-tags'); ?>
                                    </button>
                                    <button type="button" id="dt-delete-group" class="button button-secondary" style="float: right; display: none;">
                                        <?php esc_html_e('Delete Group', 'wp-dynamic-tags'); ?>
                                    </button>
                                </p>
                            </div>
                        </div>

                        <div id="dt-group-tags" style="display: none;">
                            <h3><?php esc_html_e('Tags in this Group', 'wp-dynamic-tags'); ?></h3>
                            <div id="dt-tags-list"></div>
                        </div>

                        <div id="dt-welcome-message">
                            <p><?php esc_html_e('Select a group from the tree to view details and manage tags, or create a new group to get started.', 'wp-dynamic-tags'); ?></p>

                            <h3><?php esc_html_e('Quick Actions', 'wp-dynamic-tags'); ?></h3>
                            <ul>
                                <li><strong><?php esc_html_e('Create Groups:', 'wp-dynamic-tags'); ?></strong> <?php esc_html_e('Organize your tags into logical categories', 'wp-dynamic-tags'); ?></li>
                                <li><strong><?php esc_html_e('Hierarchical Structure:', 'wp-dynamic-tags'); ?></strong> <?php esc_html_e('Create parent-child relationships', 'wp-dynamic-tags'); ?></li>
                                <li><strong><?php esc_html_e('Color Coding:', 'wp-dynamic-tags'); ?></strong> <?php esc_html_e('Assign colors for visual organization', 'wp-dynamic-tags'); ?></li>
                                <li><strong><?php esc_html_e('Bulk Assignment:', 'wp-dynamic-tags'); ?></strong> <?php esc_html_e('Assign multiple tags to groups at once', 'wp-dynamic-tags'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Card -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php esc_html_e('Group Statistics', 'wp-dynamic-tags'); ?></h2>
                <?php $this->display_group_statistics(); ?>
            </div>
        </div>

        <!-- Hidden form for operations -->
        <form id="dt-group-operation-form" style="display: none;">
            <?php wp_nonce_field('dt_group_operation', 'dt_group_nonce'); ?>
            <input type="hidden" id="dt-operation" name="operation">
            <input type="hidden" id="dt-group-id" name="group_id">
            <input type="hidden" id="dt-parent-id" name="parent_id">
        </form>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var currentGroupId = 0;
            var isEditing = false;

            // Tree interaction
            $(document).on('click', '.dt-group-item', function(e) {
                e.preventDefault();
                $('.dt-group-item').removeClass('selected');
                $(this).addClass('selected');

                var groupId = $(this).data('group-id');
                loadGroupDetails(groupId);
            });

            // Add root group
            $('#dt-add-root-group').on('click', function() {
                showGroupForm(0, 0);
            });

            // Add child group
            $(document).on('click', '.dt-add-child', function(e) {
                e.stopPropagation();
                var parentId = $(this).closest('.dt-group-item').data('group-id');
                showGroupForm(0, parentId);
            });

            // Edit group
            $(document).on('click', '.dt-edit-group', function(e) {
                e.stopPropagation();
                var groupId = $(this).closest('.dt-group-item').data('group-id');
                showGroupForm(groupId, 0);
            });

            // Save group
            $('#dt-save-group').on('click', function() {
                saveGroup();
            });

            // Cancel editing
            $('#dt-cancel-group').on('click', function() {
                hideGroupForm();
            });

            // Delete group
            $('#dt-delete-group').on('click', function() {
                if (!currentGroupId || currentGroupId <= 0) {
                    alert('<?php echo esc_js(__('No group selected for deletion. Please select a group first.', 'wp-dynamic-tags')); ?>');
                    return;
                }

                if (confirm('<?php echo esc_js(__('Are you sure you want to delete this group? Tags will not be deleted, only removed from this group.', 'wp-dynamic-tags')); ?>')) {
                    deleteGroup(currentGroupId);
                }
            });

            // Expand/Collapse all
            $('#dt-expand-all').on('click', function() {
                $('.dt-group-children').show();
                $('.dt-toggle').removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
            });

            $('#dt-collapse-all').on('click', function() {
                $('.dt-group-children').hide();
                $('.dt-toggle').removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
            });

            // Toggle group children
            $(document).on('click', '.dt-toggle', function(e) {
                e.stopPropagation();
                var $children = $(this).closest('.dt-group-wrapper').find('> .dt-group-children');
                $children.toggle();
                $(this).toggleClass('dashicons-arrow-right dashicons-arrow-down');
            });

            function showGroupForm(groupId, parentId) {
                currentGroupId = groupId;
                isEditing = groupId > 0;

                $('#dt-welcome-message').hide();
                $('#dt-group-tags').hide();
                $('#dt-group-info').show();

                $('#dt-details-title').text(isEditing ? '<?php echo esc_js(__('Edit Group', 'wp-dynamic-tags')); ?>' : '<?php echo esc_js(__('New Group', 'wp-dynamic-tags')); ?>');
                $('#dt-delete-group').toggle(isEditing);

                if (isEditing) {
                    loadGroupForEdit(groupId);
                } else {
                    $('#group-name').val('');
                    $('#group-description').val('');
                    $('#group-color').val('#2271b1');
                    $('#group-parent').val(parentId);
                }
            }

            function hideGroupForm() {
                $('#dt-group-info').hide();
                $('#dt-group-tags').hide();
                $('#dt-welcome-message').show();
                $('#dt-details-title').text('<?php echo esc_js(__('Group Details', 'wp-dynamic-tags')); ?>');
                $('.dt-group-item').removeClass('selected');
                currentGroupId = 0;
                isEditing = false;
            }

            function loadGroupDetails(groupId) {
                currentGroupId = groupId;

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_get_group_details',
                        group_id: groupId,
                        nonce: '<?php echo esc_js(wp_create_nonce('dt_get_group_details')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            displayGroupDetails(response.data);
                        } else {
                            alert('<?php echo esc_js(__('Error loading group details:', 'wp-dynamic-tags')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error occurred.', 'wp-dynamic-tags')); ?>'));
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred while loading group details. Please try again.', 'wp-dynamic-tags')); ?>');
                    }
                });
            }

            function displayGroupDetails(data) {
                $('#dt-welcome-message').hide();
                $('#dt-group-info').hide();
                $('#dt-group-tags').show();

                $('#dt-details-title').text(data.name);
                $('#dt-tags-list').html(data.tags_html);
            }

            function loadGroupForEdit(groupId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_get_group_edit_data',
                        group_id: groupId,
                        nonce: '<?php echo esc_js(wp_create_nonce('dt_get_group_edit_data')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#group-name').val(data.name);
                            $('#group-description').val(data.description);
                            $('#group-color').val(data.color);

                            // Update parent dropdown with filtered options (excluding current group and descendants)
                            if (data.parent_options) {
                                $('#group-parent').html(data.parent_options);
                            }
                            $('#group-parent').val(data.parent);
                        } else {
                            alert('<?php echo esc_js(__('Error loading group data:', 'wp-dynamic-tags')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error occurred.', 'wp-dynamic-tags')); ?>'));
                            hideGroupForm();
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred while loading group data. Please try again.', 'wp-dynamic-tags')); ?>');
                        hideGroupForm();
                    }
                });
            }

            function saveGroup() {
                var data = {
                    action: currentGroupId > 0 ? 'dt_edit_group' : 'dt_create_group',
                    group_id: currentGroupId,
                    name: $('#group-name').val(),
                    description: $('#group-description').val(),
                    color: $('#group-color').val(),
                    parent: $('#group-parent').val(),
                    nonce: '<?php echo esc_js(wp_create_nonce('dt_group_operation')); ?>'
                };

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: data,
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'wp-dynamic-tags')); ?> ' + response.data);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred while saving group. Please try again.', 'wp-dynamic-tags')); ?>');
                    }
                });
            }

            function deleteGroup(groupId) {
                if (!groupId || groupId <= 0) {
                    alert('<?php echo esc_js(__('Invalid group selected for deletion.', 'wp-dynamic-tags')); ?>');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_delete_group',
                        group_id: groupId,
                        nonce: '<?php echo esc_js(wp_create_nonce('dt_group_operation')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message || '<?php echo esc_js(__('Group deleted successfully.', 'wp-dynamic-tags')); ?>');
                            location.reload();
                        } else {
                            alert('<?php echo esc_js(__('Error deleting group:', 'wp-dynamic-tags')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error occurred.', 'wp-dynamic-tags')); ?>'));
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred while deleting group. Please try again.', 'wp-dynamic-tags')); ?>');
                    }
                });
            }
        });
        </script>

        <style>
        .dt-tree-container {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            max-height: 500px;
            overflow-y: auto;
        }

        .dt-group-wrapper {
            margin-bottom: 5px;
        }

        .dt-group-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            border: 1px solid transparent;
        }

        .dt-group-item:hover {
            background-color: #f0f0f0;
        }

        .dt-group-item.selected {
            background-color: #e7f3ff;
            border-color: #0073aa;
        }

        .dt-group-color {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            margin-right: 8px;
            border: 1px solid #ddd;
        }

        .dt-group-name {
            flex: 1;
            font-weight: 500;
        }

        .dt-group-count {
            background: #ddd;
            color: #666;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 8px;
        }

        .dt-group-actions {
            opacity: 0;
            transition: opacity 0.2s;
        }

        .dt-group-item:hover .dt-group-actions {
            opacity: 1;
        }

        .dt-group-children {
            margin-left: 20px;
            border-left: 1px dashed #ddd;
            padding-left: 10px;
        }

        .dt-toggle {
            margin-right: 5px;
            cursor: pointer;
            color: #666;
        }

        .dt-toggle:hover {
            color: #0073aa;
        }
        </style>
        <?php
    }

    /**
     * Get hierarchical groups
     */
    private function get_hierarchical_groups() {
        $terms = get_terms(array(
            'taxonomy' => $this->taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        return $this->build_hierarchy($terms);
    }

    /**
     * Build hierarchical structure from flat terms array
     */
    private function build_hierarchy($terms, $parent_id = 0) {
        $children = array();

        foreach ($terms as $term) {
            // Ensure $term is an object and has the required properties
            if (!is_object($term) || !isset($term->term_id) || !isset($term->parent)) {
                continue;
            }

            if ($term->parent == $parent_id) {
                $term->children = $this->build_hierarchy($terms, $term->term_id);
                $children[] = $term;
            }
        }

        return $children;
    }

    /**
     * Render groups tree HTML
     */
    private function render_groups_tree($groups, $level = 0) {
        if (empty($groups)) {
            echo '<p class="dt-no-groups">' . esc_html__('No groups created yet. Click "Add Root Group" to get started.', 'wp-dynamic-tags') . '</p>';
            return;
        }

        foreach ($groups as $group) {
            // Ensure $group is an object and has the required properties
            if (!is_object($group) || !isset($group->term_id) || !isset($group->name)) {
                continue;
            }

            $color = get_term_meta($group->term_id, 'color', true) ?: '#2271b1';
            $count = $this->get_group_tag_count($group->term_id);
            $has_children = !empty($group->children);

            echo '<div class="dt-group-wrapper">';
            echo '<div class="dt-group-item" data-group-id="' . esc_attr($group->term_id) . '">';

            if ($has_children) {
                echo '<span class="dt-toggle dashicons dashicons-arrow-right"></span>';
            } else {
                echo '<span style="width: 20px;"></span>';
            }

            echo '<div class="dt-group-color" style="background-color: ' . esc_attr($color) . ';"></div>';
            echo '<span class="dt-group-name">' . esc_html($group->name) . '</span>';
            echo '<span class="dt-group-count">' . esc_html((string) $count) . '</span>';

            echo '<div class="dt-group-actions">';
            echo '<button type="button" class="dt-add-child button-link" title="' . esc_attr__('Add Child Group', 'wp-dynamic-tags') . '">';
            echo '<span class="dashicons dashicons-plus-alt"></span>';
            echo '</button>';
            echo '<button type="button" class="dt-edit-group button-link" title="' . esc_attr__('Edit Group', 'wp-dynamic-tags') . '">';
            echo '<span class="dashicons dashicons-edit"></span>';
            echo '</button>';
            echo '</div>';

            echo '</div>';

            if ($has_children) {
                echo '<div class="dt-group-children" style="display: none;">';
                $this->render_groups_tree($group->children, $level + 1);
                echo '</div>';
            }

            echo '</div>';
        }
    }

    /**
     * Render group options for select dropdown
     */
    private function render_group_options($groups, $level = 0, $exclude_id = 0) {
        foreach ($groups as $group) {
            // Ensure $group is an object and has the required properties
            if (!is_object($group) || !isset($group->term_id) || !isset($group->name)) {
                continue;
            }

            if ($group->term_id == $exclude_id) {
                continue;
            }

            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
            echo '<option value="' . esc_attr($group->term_id) . '">' . $indent . esc_html($group->name) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $indent is fixed &nbsp; markup

            if (!empty($group->children)) {
                $this->render_group_options($group->children, $level + 1, $exclude_id);
            }
        }
    }

    /**
     * How many tags each group holds, from one query over the table: a tag is a row and its group is the row's category_id
     * (0 = none). Dropped at the start of every render of the page.
     *
     * @var array<int,int>|null
     */
    private $group_counts = null;

    /** @return array<int,int> group id => tags (only groups that exist here; 0 holds every tag without a group) */
    private function group_counts() {
        if ($this->group_counts === null) {
            global $wpdb;
            $table = WP_Dynamic_Tags_Database_Manager::get_instance()->get_table_name();
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT category_id, COUNT(*) AS total FROM %i GROUP BY category_id', $table));
            $existing = get_terms(array('taxonomy' => $this->taxonomy, 'hide_empty' => false, 'fields' => 'ids'));
            $existing = is_array($existing) ? array_map('intval', $existing) : array();

            $this->group_counts = array(0 => 0);
            foreach ($rows as $row) {
                $group = (int) $row->category_id;
                // A tag whose group is gone (removed before tags were released) counts as having none.
                $key = in_array($group, $existing, true) ? $group : 0;
                $this->group_counts[$key] = ($this->group_counts[$key] ?? 0) + (int) $row->total;
            }
        }
        return $this->group_counts;
    }

    private function get_group_tag_count($group_id) {
        return $this->group_counts()[(int) $group_id] ?? 0;
    }

    private function get_total_tag_count() {
        return array_sum($this->group_counts());
    }

    private function get_ungrouped_tag_count() {
        return $this->group_counts()[0] ?? 0;
    }

    /** @return array{name: string, count: int}|null */
    private function get_most_used_group() {
        $counts = $this->group_counts();
        unset($counts[0]);
        arsort($counts);
        foreach ($counts as $group_id => $count) {
            $term = get_term($group_id, $this->taxonomy);
            if ($count > 0 && $term instanceof WP_Term) {
                return array('name' => $term->name, 'count' => $count);
            }
        }
        return null;
    }

    /**
     * Releases the tags of a group that was deleted (action delete_tag_groups).
     *
     * @param int $term_id The group.
     */
    public function release_tags($term_id) {
        global $wpdb;
        $table = WP_Dynamic_Tags_Database_Manager::get_instance()->get_table_name();
        $wpdb->update($table, array('category_id' => 0), array('category_id' => (int) $term_id), array('%d'), array('%d'));
        WP_Dynamic_Tags_Table_Manager::get_instance()->flush_cache();
        $this->group_counts = null;
    }

    /**
     * Display group statistics
     */
    private function display_group_statistics() {
        $total_groups = wp_count_terms(array('taxonomy' => $this->taxonomy, 'hide_empty' => false));
        $total_groups = is_wp_error($total_groups) ? 0 : (int) $total_groups;
        $total_tags = $this->get_total_tag_count();
        $ungrouped_tags = $this->get_ungrouped_tag_count();
        $most_used_group = $this->get_most_used_group();

        echo '<div style="display: flex; gap: 20px; flex-wrap: wrap;">';

        echo '<div class="dt-stat-box" style="flex: 1; min-width: 200px; text-align: center; padding: 20px; background: #f9f9f9; border-radius: 4px;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . esc_html((string) $total_groups) . '</div>';
        echo '<div>' . esc_html__('Total Groups', 'wp-dynamic-tags') . '</div>';
        echo '</div>';

        echo '<div class="dt-stat-box" style="flex: 1; min-width: 200px; text-align: center; padding: 20px; background: #f9f9f9; border-radius: 4px;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . esc_html((string) $total_tags) . '</div>';
        echo '<div>' . esc_html__('Total Tags', 'wp-dynamic-tags') . '</div>';
        echo '</div>';

        echo '<div class="dt-stat-box" style="flex: 1; min-width: 200px; text-align: center; padding: 20px; background: #f9f9f9; border-radius: 4px;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #d63638;">' . esc_html((string) $ungrouped_tags) . '</div>';
        echo '<div>' . esc_html__('Ungrouped Tags', 'wp-dynamic-tags') . '</div>';
        echo '</div>';

        if ($most_used_group) {
            echo '<div class="dt-stat-box" style="flex: 1; min-width: 200px; text-align: center; padding: 20px; background: #f9f9f9; border-radius: 4px;">';
            echo '<div style="font-size: 16px; font-weight: bold; color: #0073aa;">' . esc_html($most_used_group['name']) . '</div>';
            /* translators: %d: number of tags in the group */
            echo '<div>' . esc_html(sprintf(__('Most Used Group (%d tags)', 'wp-dynamic-tags'), $most_used_group['count'])) . '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Get group colors option
     */
    private function get_group_colors() {
        return get_option('dt_group_colors', array());
    }

    /**
     * AJAX: Create group
     */
    public function ajax_create_group() {
        // Check nonce - pass false as 3rd param to prevent wp_die() on failure
        $nonce_check = check_ajax_referer('dt_group_operation', 'nonce', false);

        if ($nonce_check === false || $nonce_check === -1) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'wp-dynamic-tags'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized. You do not have permission to create groups.', 'wp-dynamic-tags'));
            return;
        }

        // Verify taxonomy exists
        if (!taxonomy_exists($this->taxonomy)) {
            wp_send_json_error(__('Taxonomy not registered. Please refresh the page and try again.', 'wp-dynamic-tags'));
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $color = isset($_POST['color']) ? sanitize_hex_color(wp_unslash($_POST['color'])) : null;
        $parent = isset($_POST['parent']) ? absint($_POST['parent']) : 0;

        if (empty($name)) {
            wp_send_json_error(__('Group name is required.', 'wp-dynamic-tags'));
        }

        $result = wp_insert_term($name, $this->taxonomy, array(
            'description' => $description,
            'parent' => $parent
        ));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Save color
        if ($color) {
            update_term_meta($result['term_id'], 'color', $color);
        }

        wp_send_json_success(array(
            'term_id' => $result['term_id'],
            'message' => __('Group created successfully.', 'wp-dynamic-tags')
        ));
    }

    /**
     * AJAX: Edit group
     */
    public function ajax_edit_group() {
        // Check nonce - pass false as 3rd param to prevent wp_die() on failure
        $nonce_check = check_ajax_referer('dt_group_operation', 'nonce', false);

        if ($nonce_check === false || $nonce_check === -1) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'wp-dynamic-tags'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized. You do not have permission to edit groups.', 'wp-dynamic-tags'));
            return;
        }

        // Verify taxonomy exists
        if (!taxonomy_exists($this->taxonomy)) {
            wp_send_json_error(__('Taxonomy not registered. Please refresh the page and try again.', 'wp-dynamic-tags'));
        }

        $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $color = isset($_POST['color']) ? sanitize_hex_color(wp_unslash($_POST['color'])) : null;
        $parent = isset($_POST['parent']) ? absint($_POST['parent']) : 0;

        if (empty($name)) {
            wp_send_json_error(__('Group name is required.', 'wp-dynamic-tags'));
        }

        $result = wp_update_term($group_id, $this->taxonomy, array(
            'name' => $name,
            'description' => $description,
            'parent' => $parent
        ));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Update color
        update_term_meta($group_id, 'color', $color);

        wp_send_json_success(array(
            'message' => __('Group updated successfully.', 'wp-dynamic-tags')
        ));
    }

    /**
     * AJAX: Delete group
     */
    public function ajax_delete_group() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Delete group AJAX called');
        }

        // Check nonce - pass false as 3rd param to prevent wp_die() on failure
        $nonce_check = check_ajax_referer('dt_group_operation', 'nonce', false);

        if ($nonce_check === false || $nonce_check === -1) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Nonce verification failed for group deletion');
            }
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'wp-dynamic-tags'));
            return;
        }

        if (!current_user_can('manage_options')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: User lacks manage_options capability for group deletion');
            }
            wp_send_json_error(__('Unauthorized. You do not have permission to delete groups.', 'wp-dynamic-tags'));
        }

        // Verify taxonomy exists
        if (!taxonomy_exists($this->taxonomy)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Taxonomy ' . $this->taxonomy . ' does not exist');
            }
            wp_send_json_error(__('Taxonomy not registered. Please refresh the page and try again.', 'wp-dynamic-tags'));
        }

        $group_id = isset($_POST['group_id']) ? absint(wp_unslash($_POST['group_id'])) : 0;

        if (empty($group_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: No group_id provided for deletion');
            }
            wp_send_json_error(__('Invalid group ID provided.', 'wp-dynamic-tags'));
        }

        // Check if group exists before attempting deletion
        $term = get_term($group_id, $this->taxonomy);
        if (is_wp_error($term) || !$term) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Group ' . $group_id . ' does not exist');
            }
            wp_send_json_error(__('Group not found. It may have already been deleted.', 'wp-dynamic-tags'));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Attempting to delete group: ' . $term->name . ' (ID: ' . $group_id . ')');
        }

        $result = wp_delete_term($group_id, $this->taxonomy);

        if (is_wp_error($result)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Group deletion failed: ' . $result->get_error_message());
            }
            wp_send_json_error($result->get_error_message());
        }

        // Clear any related caches
        delete_transient('dt_bulk_groups_cache');
        wp_cache_delete('dt_groups_' . get_current_blog_id(), 'dynamic_tags');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Group deleted successfully: ' . $term->name . ' (ID: ' . $group_id . ')');
        }

        wp_send_json_success(array(
            'message' => __('Group deleted successfully.', 'wp-dynamic-tags')
        ));
    }

    /**
     * AJAX: Get group details
     */
    public function ajax_get_group_details() {
        check_ajax_referer('dt_get_group_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-dynamic-tags'));
        }

        $group_id = isset($_POST['group_id']) ? absint(wp_unslash($_POST['group_id'])) : 0;

        if (empty($group_id)) {
            wp_send_json_error(__('Invalid group ID.', 'wp-dynamic-tags'));
        }

        // Get group term
        $group = get_term($group_id, $this->taxonomy);

        if (is_wp_error($group) || !$group) {
            wp_send_json_error(__('Group not found.', 'wp-dynamic-tags'));
        }

        // Get tags in this group
        $tags = WP_Dynamic_Tags_Table_Manager::get_instance()->get_tags(array('category_id' => $group_id, 'status' => '', 'orderby' => 'tag_name', 'order' => 'ASC'));

        // Build tags HTML
        $tags_html = '';
        if (empty($tags)) {
            $tags_html = '<p class="description">' . esc_html__('No tags in this group yet.', 'wp-dynamic-tags') . '</p>';
        } else {
            $tags_html .= '<table class="wp-list-table widefat fixed striped">';
            $tags_html .= '<thead><tr>';
            $tags_html .= '<th>' . esc_html__('Tag Name', 'wp-dynamic-tags') . '</th>';
            $tags_html .= '<th>' . esc_html__('Shortcode', 'wp-dynamic-tags') . '</th>';
            $tags_html .= '<th>' . esc_html__('Actions', 'wp-dynamic-tags') . '</th>';
            $tags_html .= '</tr></thead>';
            $tags_html .= '<tbody>';

            foreach ($tags as $tag) {
                $tags_html .= '<tr>';
                $tags_html .= '<td><strong>' . esc_html($tag->tag_name) . '</strong></td>';
                $tags_html .= '<td><code>' . esc_html('[' . $tag->shortcode . ']') . '</code></td>';
                $tags_html .= '<td>';
                $tags_html .= '<a href="' . esc_url(WP_Dynamic_Tags_Admin::edit_url((int) $tag->id)) . '" class="button button-small">' . esc_html__('Edit', 'wp-dynamic-tags') . '</a>';
                $tags_html .= '</td>';
                $tags_html .= '</tr>';
            }

            $tags_html .= '</tbody></table>';
            $tags_html .= '<p class="description" style="margin-top: 10px;">';
            /* translators: %d: number of tags */
            $tags_html .= esc_html(sprintf(__('Total: %d tags', 'wp-dynamic-tags'), count($tags)));
            $tags_html .= '</p>';
        }

        wp_send_json_success(array(
            'name' => $group->name,
            'description' => $group->description,
            'count' => count($tags),
            'tags_html' => $tags_html
        ));
    }

    /**
     * AJAX: Get group edit data
     */
    public function ajax_get_group_edit_data() {
        check_ajax_referer('dt_get_group_edit_data', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp-dynamic-tags'));
        }

        $group_id = isset($_POST['group_id']) ? absint(wp_unslash($_POST['group_id'])) : 0;

        if (empty($group_id)) {
            wp_send_json_error(__('Invalid group ID.', 'wp-dynamic-tags'));
        }

        // Get group term
        $group = get_term($group_id, $this->taxonomy);

        if (is_wp_error($group) || !$group) {
            wp_send_json_error(__('Group not found.', 'wp-dynamic-tags'));
        }

        // Get group color
        $color = get_term_meta($group_id, 'color', true);
        if (empty($color)) {
            $color = '#2271b1';
        }

        // Get all groups for parent dropdown (excluding current group and its descendants)
        $all_groups = $this->get_hierarchical_groups();
        $parent_options_html = $this->get_parent_options_html($all_groups, $group_id);

        wp_send_json_success(array(
            'name' => $group->name,
            'description' => $group->description,
            'color' => $color,
            'parent' => $group->parent,
            'parent_options' => $parent_options_html
        ));
    }

    /**
     * Maybe migrate existing categories to groups
     */
    public function maybe_migrate_categories() {
        if (get_option('dt_categories_migrated')) {
            return;
        }

        // Get all tags with categories
        $posts = get_posts(array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_dt_category',
                    'compare' => 'EXISTS'
                )
            )
        ));
        

        $created_groups = array();

        foreach ($posts as $post) {
            $category = get_post_meta($post->ID, '_dt_category', true);

            if (empty($category) || $category === 'general') {
                continue;
            }

            // Create group if it doesn't exist
            if (!isset($created_groups[$category])) {
                $group = get_term_by('name', ucfirst($category), $this->taxonomy);

                if (!$group) {
                    $result = wp_insert_term(ucfirst($category), $this->taxonomy, array(
                        /* translators: %s: the name of the old category */
                        'description' => sprintf(__('Migrated from category: %s', 'wp-dynamic-tags'), $category)
                    ));

                    if (!is_wp_error($result)) {
                        $created_groups[$category] = $result['term_id'];

                        // Set default colors for migrated categories
                        $colors = array(
                            'seo' => '#16a085',
                            'contact' => '#e74c3c',
                            'branding' => '#9b59b6',
                            'custom' => '#f39c12'
                        );

                        if (isset($colors[$category])) {
                            update_term_meta($result['term_id'], 'color', $colors[$category]);
                        }
                    }
                } else {
                    // Ensure $group is an object and has the required properties
                    if (is_object($group) && isset($group->term_id)) {
                        $created_groups[$category] = $group->term_id;
                    }
                }
            }

            // Assign tag to group
            if (isset($created_groups[$category])) {
                wp_set_post_terms($post->ID, array($created_groups[$category]), $this->taxonomy);
            }
        }

        update_option('dt_categories_migrated', true);
    }

    /**
     * Get parent dropdown options HTML with exclusions
     *
     * @param array $groups Hierarchical groups array
     * @param int $exclude_id Group ID to exclude (and its descendants)
     * @param int $level Current nesting level
     * @return string HTML options for select dropdown
     */
    private function get_parent_options_html($groups, $exclude_id = 0, $level = 0) {
        $html = '<option value="0">' . __('None (Root Group)', 'wp-dynamic-tags') . '</option>';
        $html .= $this->build_parent_options($groups, $exclude_id, $level);
        return $html;
    }

    /**
     * Build parent options recursively
     *
     * @param array $groups Groups to process
     * @param int $exclude_id Group ID to exclude
     * @param int $level Current nesting level
     * @return string HTML options
     */
    private function build_parent_options($groups, $exclude_id, $level) {
        $html = '';

        foreach ($groups as $group) {
            // Ensure $group is an object and has the required properties
            if (!is_object($group) || !isset($group->term_id) || !isset($group->name)) {
                continue;
            }

            // Skip current group and its descendants to prevent circular references
            if ($group->term_id == $exclude_id || $this->is_descendant($exclude_id, $group->term_id)) {
                continue;
            }

            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
            $html .= '<option value="' . esc_attr($group->term_id) . '">' . $indent . esc_html($group->name) . '</option>';

            if (!empty($group->children)) {
                $html .= $this->build_parent_options($group->children, $exclude_id, $level + 1);
            }
        }

        return $html;
    }

    /**
     * Check if a group is a descendant of another
     *
     * @param int $parent_id Potential parent group ID
     * @param int $child_id Group ID to check
     * @return bool True if child_id is a descendant of parent_id
     */
    private function is_descendant($parent_id, $child_id) {
        if (empty($parent_id) || empty($child_id)) {
            return false;
        }

        $child = get_term($child_id, $this->taxonomy);

        // Traverse up the hierarchy
        $max_iterations = 20; // Prevent infinite loops
        $iteration = 0;

        while ($child && !is_wp_error($child) && $child->parent != 0 && $iteration < $max_iterations) {
            if ($child->parent == $parent_id) {
                return true; // child_id is a descendant of parent_id
            }
            $child = get_term($child->parent, $this->taxonomy);
            $iteration++;
        }

        return false;
    }

    /**
     * Show admin notices
     */
    private function show_notices() {
        if (isset($_GET['message'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a fixed notice text, changes nothing
            $message = sanitize_key(wp_unslash($_GET['message'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a fixed notice text, changes nothing
            $notices = array(
                'group_created' => __('Group created successfully.', 'wp-dynamic-tags'),
                'group_updated' => __('Group updated successfully.', 'wp-dynamic-tags'),
                'group_deleted' => __('Group deleted successfully.', 'wp-dynamic-tags'),
                'error' => __('An error occurred.', 'wp-dynamic-tags')
            );

            if (isset($notices[$message])) {
                $class = $message === 'error' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notices[$message]) . '</p></div>';
            }
        }
    }
}