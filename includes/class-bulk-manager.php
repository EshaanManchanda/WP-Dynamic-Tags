<?php
/**
 * Bulk Operations Manager for WP Dynamic Tags
 * 
 * Handles bulk editing, deletion, and management of dynamic tags
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Bulk_Manager {

    private $post_type = 'dynamic_tag';
    private $transient_key = 'wp_dynamic_tags_cache';

    // Performance optimization - integrate with main plugin cache
    private static $static_cache = array();
    private $cache_version = '2.0.1';
    private $cache_duration = 300; // 5 minutes cache

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_init', array($this, 'handle_bulk_actions'));
        add_action('wp_ajax_dt_inline_edit', array($this, 'handle_inline_edit'));
        add_action('wp_ajax_dt_bulk_delete', array($this, 'handle_bulk_delete'));

        // Clear cache when tags are modified
        add_action('save_post_' . $this->post_type, array($this, 'clear_cache'));
        add_action('deleted_post', array($this, 'clear_cache'));
    }
    
    /**
     * Add bulk manager admin page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Bulk Manager', 'wp-dynamic-tags'),
            __('Bulk Manager', 'wp-dynamic-tags'),
            'manage_options',
            'dt-bulk-manager',
            array($this, 'bulk_manager_page')
        );
    }
    
    /**
     * Bulk manager page - Optimized with caching
     */
    public function bulk_manager_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';

        // Get paginated tags with search/filter - cached
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50; // Increased from 25 to 50 for better performance

        $result = $this->get_filtered_tags_paginated_cached($search, $category, $page, $per_page);
        $tags = $result['tags'];
        $total_tags = $result['total'];
        $total_pages = ceil($total_tags / $per_page);

        $categories = $this->get_tag_categories_cached();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Bulk Manager', 'wp-dynamic-tags'); ?></h1>
            
            <?php $this->show_notices(); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" id="dt-filter-form">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr($this->post_type); ?>">
                        <input type="hidden" name="page" value="dt-bulk-manager">
                        
                        <input type="search" name="search" placeholder="<?php esc_attr_e('Search tags...', 'wp-dynamic-tags'); ?>" 
                               value="<?php echo esc_attr($search); ?>" class="search-input">
                        
                        <select name="category">
                            <option value=""><?php _e('All Categories', 'wp-dynamic-tags'); ?></option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat); ?>" <?php selected($category, $cat); ?>>
                                    <?php echo esc_html(ucfirst($cat)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wp-dynamic-tags'); ?>">
                        
                        <?php if ($search || $category): ?>
                            <a href="<?php echo admin_url('edit.php?post_type=' . $this->post_type . '&page=dt-bulk-manager'); ?>" 
                               class="button"><?php _e('Clear', 'wp-dynamic-tags'); ?></a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="alignright actions">
                    <button type="button" id="dt-bulk-edit-btn" class="button" disabled>
                        <?php _e('Bulk Edit', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" id="dt-bulk-groups-btn" class="button" disabled>
                        <?php _e('Manage Groups', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" id="dt-bulk-delete-btn" class="button button-secondary" disabled>
                        <?php _e('Bulk Delete', 'wp-dynamic-tags'); ?>
                    </button>
                </div>
            </div>
            
            <form id="dt-bulk-form">
                <?php wp_nonce_field('dt_bulk_action', 'dt_bulk_nonce'); ?>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all">
                            </td>
                            <th class="manage-column column-title"><?php _e('Tag Key', 'wp-dynamic-tags'); ?></th>
                            <th class="manage-column column-shortcode"><?php _e('Shortcode', 'wp-dynamic-tags'); ?></th>
                            <th class="manage-column column-value"><?php _e('Tag Value', 'wp-dynamic-tags'); ?></th>
                            <th class="manage-column column-groups"><?php _e('Groups', 'wp-dynamic-tags'); ?></th>
                            <th class="manage-column column-category"><?php _e('Category', 'wp-dynamic-tags'); ?></th>
                            <th class="manage-column column-actions"><?php _e('Actions', 'wp-dynamic-tags'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tags)): ?>
                            <?php foreach ($tags as $tag): ?>
                                <?php
                                $tag_key = $this->sanitize_tag_key($tag->post_title);
                                $category = get_post_meta($tag->ID, '_dt_category', true) ?: 'general';
                                ?>
                                <tr data-tag-id="<?php echo esc_attr($tag->ID); ?>">
                                    <th class="check-column">
                                        <input type="checkbox" name="tag_ids[]" value="<?php echo esc_attr($tag->ID); ?>" 
                                               class="tag-checkbox">
                                    </th>
                                    <td class="column-title">
                                        <strong class="tag-key"><?php echo esc_html($tag_key); ?></strong>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo get_edit_post_link($tag->ID); ?>">
                                                    <?php _e('Edit', 'wp-dynamic-tags'); ?>
                                                </a> |
                                            </span>
                                            <span class="inline-edit">
                                                <button type="button" class="button-link inline-edit-btn">
                                                    <?php _e('Quick Edit', 'wp-dynamic-tags'); ?>
                                                </button> |
                                            </span>
                                            <span class="trash">
                                                <a href="<?php echo get_delete_post_link($tag->ID); ?>" 
                                                   class="delete-tag" onclick="return confirm('<?php esc_attr_e('Are you sure?', 'wp-dynamic-tags'); ?>')">
                                                    <?php _e('Delete', 'wp-dynamic-tags'); ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-shortcode">
                                        <code class="shortcode-display">[<?php echo esc_html($tag_key); ?>]</code>
                                        <button type="button" class="button-link copy-shortcode" 
                                                data-shortcode="[<?php echo esc_attr($tag_key); ?>]">
                                            <?php _e('Copy', 'wp-dynamic-tags'); ?>
                                        </button>
                                    </td>
                                    <td class="column-value">
                                        <div class="tag-value-display">
                                            <?php echo wp_trim_words(wp_strip_all_tags($tag->post_content), 10, '...'); ?>
                                        </div>
                                        <div class="tag-value-edit" style="display: none;">
                                            <textarea class="tag-value-input" rows="3"><?php echo esc_textarea($tag->post_content); ?></textarea>
                                        </div>
                                    </td>
                                    <td class="column-groups">
                                        <?php
                                        $groups = wp_get_post_terms($tag->ID, 'tag_groups');
                                        if (!empty($groups)): ?>
                                            <?php foreach ($groups as $group):
                                                // Ensure $group is an object and has the required properties
                                                if (!is_object($group) || !isset($group->term_id) || !isset($group->name)) {
                                                    continue;
                                                }
                                                $color = get_term_meta($group->term_id, 'color', true) ?: '#2271b1';
                                            ?>
                                                <span class="dt-group-badge" style="background-color: <?php echo esc_attr($color); ?>; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-right: 4px;">
                                                    <?php echo esc_html($group->name); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="dt-no-groups" style="color: #999;"><?php _e('No groups', 'wp-dynamic-tags'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-category">
                                        <span class="category-display"><?php echo esc_html(ucfirst($category)); ?></span>
                                        <select class="category-input" style="display: none;">
                                            <option value="general" <?php selected($category, 'general'); ?>><?php _e('General', 'wp-dynamic-tags'); ?></option>
                                            <option value="seo" <?php selected($category, 'seo'); ?>><?php _e('SEO', 'wp-dynamic-tags'); ?></option>
                                            <option value="contact" <?php selected($category, 'contact'); ?>><?php _e('Contact', 'wp-dynamic-tags'); ?></option>
                                            <option value="branding" <?php selected($category, 'branding'); ?>><?php _e('Branding', 'wp-dynamic-tags'); ?></option>
                                            <option value="custom" <?php selected($category, 'custom'); ?>><?php _e('Custom', 'wp-dynamic-tags'); ?></option>
                                        </select>
                                    </td>
                                    <td class="column-actions">
                                        <div class="actions-display">
                                            <button type="button" class="button button-small duplicate-tag" 
                                                    data-tag-id="<?php echo esc_attr($tag->ID); ?>">
                                                <?php _e('Duplicate', 'wp-dynamic-tags'); ?>
                                            </button>
                                        </div>
                                        <div class="actions-edit" style="display: none;">
                                            <button type="button" class="button-primary button-small save-inline">
                                                <?php _e('Save', 'wp-dynamic-tags'); ?>
                                            </button>
                                            <button type="button" class="button button-small cancel-inline">
                                                <?php _e('Cancel', 'wp-dynamic-tags'); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <?php if ($search || $category): ?>
                                        <?php _e('No tags found matching your search criteria.', 'wp-dynamic-tags'); ?>
                                    <?php else: ?>
                                        <?php _e('No dynamic tags found. Create your first tag!', 'wp-dynamic-tags'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <div class="tablenav bottom">
                <div class="alignleft actions">
                    <span class="displaying-num">
                        <?php printf(_n('%s item', '%s items', $total_tags, 'wp-dynamic-tags'), number_format_i18n($total_tags)); ?>
                    </span>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <?php
                    $base_url = add_query_arg(array(
                        'post_type' => $this->post_type,
                        'page' => 'dt-bulk-manager',
                        'search' => $search,
                        'category' => $category
                    ), admin_url('edit.php'));

                    // Previous page
                    if ($page > 1) {
                        echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $page - 1, $base_url)) . '">‹</a> ';
                    }

                    // Page numbers
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1) {
                        echo '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="button">1</a> ';
                        if ($start_page > 2) echo '<span class="button disabled">…</span> ';
                    }

                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<span class="button disabled current">' . $i . '</span> ';
                        } else {
                            echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '" class="button">' . $i . '</a> ';
                        }
                    }

                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) echo '<span class="button disabled">…</span> ';
                        echo '<a href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '" class="button">' . $total_pages . '</a> ';
                    }

                    // Next page
                    if ($page < $total_pages) {
                        echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $page + 1, $base_url)) . '">›</a>';
                    }
                    ?>

                    <span class="paging-input">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bulk Groups Modal -->
        <div id="dt-bulk-groups-modal" style="display: none;">
            <div style="background: white; padding: 20px; border-radius: 4px; max-width: 500px; margin: 50px auto; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                <h3><?php _e('Manage Groups for Selected Tags', 'wp-dynamic-tags'); ?></h3>

                <div class="dt-group-action-tabs" style="margin-bottom: 15px;">
                    <button type="button" class="button group-action-tab active" data-action="add">
                        <?php _e('Add to Groups', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" class="button group-action-tab" data-action="remove">
                        <?php _e('Remove from Groups', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" class="button group-action-tab" data-action="replace">
                        <?php _e('Replace Groups', 'wp-dynamic-tags'); ?>
                    </button>
                </div>

                <div class="dt-groups-selection">
                    <h4><?php _e('Select Groups:', 'wp-dynamic-tags'); ?></h4>
                    <div id="dt-bulk-groups-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                        <?php
                        // Optimized: Cache groups query
                        $cache_key = 'dt_bulk_groups_' . $this->cache_version;
                        $groups = get_transient($cache_key);

                        if ($groups === false) {
                            $groups = get_terms(array(
                                'taxonomy' => 'tag_groups',
                                'hide_empty' => false,
                                'orderby' => 'name',
                                'fields' => 'all'
                            ));
                            set_transient($cache_key, $groups, $this->cache_duration);
                        }

                        if (!empty($groups)):
                            foreach ($groups as $group):
                                // Ensure $group is an object and has the required properties
                                if (!is_object($group) || !isset($group->term_id) || !isset($group->name)) {
                                    continue;
                                }
                                $color = get_term_meta($group->term_id, 'color', true) ?: '#2271b1';
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="bulk_groups[]" value="<?php echo esc_attr($group->term_id); ?>">
                                <span class="dt-group-color-indicator" style="display: inline-block; width: 12px; height: 12px; background-color: <?php echo esc_attr($color); ?>; border-radius: 50%; margin: 0 5px;"></span>
                                <?php echo esc_html($group->name); ?>
                            </label>
                        <?php
                            endforeach;
                        else:
                        ?>
                            <p><?php _e('No groups available. Create groups first.', 'wp-dynamic-tags'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" id="dt-bulk-groups-cancel" class="button">
                        <?php _e('Cancel', 'wp-dynamic-tags'); ?>
                    </button>
                    <button type="button" id="dt-bulk-groups-apply" class="button button-primary">
                        <?php _e('Apply Changes', 'wp-dynamic-tags'); ?>
                    </button>
                </div>
            </div>
        </div>

        <style>
        #dt-bulk-groups-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 100000;
        }

        .group-action-tab {
            margin-right: 5px;
        }

        .group-action-tab.active {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }

        .dt-groups-selection {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
        }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Select all checkbox functionality
            $('#cb-select-all').on('change', function() {
                $('.tag-checkbox').prop('checked', this.checked);
                toggleBulkButtons();
            });
            
            $('.tag-checkbox').on('change', function() {
                toggleBulkButtons();
                var allChecked = $('.tag-checkbox:checked').length === $('.tag-checkbox').length;
                $('#cb-select-all').prop('checked', allChecked);
            });
            
            function toggleBulkButtons() {
                var hasChecked = $('.tag-checkbox:checked').length > 0;
                $('#dt-bulk-edit-btn, #dt-bulk-groups-btn, #dt-bulk-delete-btn').prop('disabled', !hasChecked);
            }
            
            // Inline edit functionality
            $('.inline-edit-btn').on('click', function() {
                var $row = $(this).closest('tr');
                $row.find('.tag-value-display, .category-display, .actions-display').hide();
                $row.find('.tag-value-edit, .category-input, .actions-edit').show();
                $row.addClass('inline-editing');
            });
            
            $('.cancel-inline').on('click', function() {
                var $row = $(this).closest('tr');
                $row.find('.tag-value-edit, .category-input, .actions-edit').hide();
                $row.find('.tag-value-display, .category-display, .actions-display').show();
                $row.removeClass('inline-editing');
            });
            
            $('.save-inline').on('click', function() {
                var $row = $(this).closest('tr');
                var tagId = $row.data('tag-id');
                var tagValue = $row.find('.tag-value-input').val();
                var category = $row.find('.category-input').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_inline_edit',
                        tag_id: tagId,
                        tag_value: tagValue,
                        category: category,
                        nonce: '<?php echo wp_create_nonce('dt_inline_edit'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.find('.tag-value-display').html(response.data.value_preview);
                            $row.find('.category-display').html(response.data.category_display);
                            $row.find('.tag-value-edit, .category-input, .actions-edit').hide();
                            $row.find('.tag-value-display, .category-display, .actions-display').show();
                            $row.removeClass('inline-editing');
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }
                });
            });
            
            // Copy shortcode functionality
            $('.copy-shortcode').on('click', function() {
                var shortcode = $(this).data('shortcode');
                navigator.clipboard.writeText(shortcode).then(function() {
                    // Show temporary success message
                    var $btn = $(this);
                    var originalText = $btn.text();
                    $btn.text('<?php _e('Copied!', 'wp-dynamic-tags'); ?>');
                    setTimeout(function() {
                        $btn.text(originalText);
                    }, 1000);
                }.bind(this));
            });
            
            // Bulk delete functionality
            $('#dt-bulk-delete-btn').on('click', function() {
                var selectedIds = $('.tag-checkbox:checked').map(function() {
                    return this.value;
                }).get();
                
                if (selectedIds.length === 0) return;
                
                if (!confirm('<?php _e('Are you sure you want to delete the selected tags?', 'wp-dynamic-tags'); ?>')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_bulk_delete',
                        tag_ids: selectedIds,
                        nonce: '<?php echo wp_create_nonce('dt_bulk_delete'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }
                });
            });

            // Bulk groups functionality
            var currentGroupAction = 'add';

            $('#dt-bulk-groups-btn').on('click', function() {
                var selectedIds = $('.tag-checkbox:checked').map(function() {
                    return this.value;
                }).get();

                if (selectedIds.length === 0) return;

                $('#dt-bulk-groups-modal').show();
            });

            $('.group-action-tab').on('click', function() {
                $('.group-action-tab').removeClass('active');
                $(this).addClass('active');
                currentGroupAction = $(this).data('action');
            });

            $('#dt-bulk-groups-cancel').on('click', function() {
                $('#dt-bulk-groups-modal').hide();
                $('input[name="bulk_groups[]"]').prop('checked', false);
            });

            $('#dt-bulk-groups-apply').on('click', function() {
                var selectedTagIds = $('.tag-checkbox:checked').map(function() {
                    return this.value;
                }).get();

                var selectedGroupIds = $('input[name="bulk_groups[]"]:checked').map(function() {
                    return this.value;
                }).get();

                if (selectedTagIds.length === 0 || selectedGroupIds.length === 0) {
                    alert('<?php _e('Please select both tags and groups.', 'wp-dynamic-tags'); ?>');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_assign_groups',
                        tag_ids: selectedTagIds,
                        group_ids: selectedGroupIds,
                        assign_action: currentGroupAction,
                        nonce: '<?php echo wp_create_nonce('dt_assign_groups'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#dt-bulk-groups-modal').hide();
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('<?php _e('An error occurred while updating groups.', 'wp-dynamic-tags'); ?>');
                    }
                });
            });

            // Close modal when clicking outside
            $('#dt-bulk-groups-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get filtered tags based on search and category
     */
    /**
     * Optimized paginated tag retrieval with caching
     */
    private function get_filtered_tags_paginated($search = '', $category = '', $page = 1, $per_page = 25) {
        $cache_key = 'bulk_tags_' . md5($search . '_' . $category . '_' . $page . '_' . $per_page);

        // Check static cache first
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        global $wpdb;

        $offset = ($page - 1) * $per_page;

        // Build WHERE clauses for direct SQL
        $where_clauses = array("p.post_type = %s", "p.post_status = 'publish'");
        $sql_params = array($this->post_type);

        // Add search filter
        if (!empty($search)) {
            $where_clauses[] = "(p.post_title LIKE %s OR p.post_content LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search) . '%';
            $sql_params[] = $search_param;
            $sql_params[] = $search_param;
        }

        // Add category filter
        $join_meta = '';
        if (!empty($category)) {
            $join_meta = "INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id";
            $where_clauses[] = "pm.meta_key = '_dt_category' AND pm.meta_value = %s";
            $sql_params[] = $category;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Get total count for pagination
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join_meta} WHERE {$where_sql}",
            $sql_params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Get paginated results
        $results_sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_date
             FROM {$wpdb->posts} p {$join_meta}
             WHERE {$where_sql}
             ORDER BY p.post_title ASC
             LIMIT %d OFFSET %d",
            array_merge($sql_params, array($per_page, $offset))
        );

        $posts = $wpdb->get_results($results_sql);

        // Convert to post objects for compatibility
        $tags = array();
        foreach ($posts as $post) {
            $tag = new stdClass();
            $tag->ID = $post->ID;
            $tag->post_title = $post->post_title;
            $tag->post_content = $post->post_content;
            $tag->post_date = $post->post_date;
            $tags[] = $tag;
        }

        $result = array(
            'tags' => $tags,
            'total' => $total
        );

        // Cache the result
        self::$static_cache[$cache_key] = $result;

        return $result;
    }
    
    /**
     * Optimized tag categories retrieval with caching
     */
    private function get_tag_categories() {
        $cache_key = 'tag_categories';

        // Check static cache first
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        // Check transient cache
        $categories = get_transient($this->transient_key . '_categories');
        if ($categories !== false) {
            self::$static_cache[$cache_key] = $categories;
            return $categories;
        }

        global $wpdb;

        $categories = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_dt_category'
            AND pm.meta_value != ''
            AND p.post_type = %s
            AND p.post_status = 'publish'
            ORDER BY pm.meta_value
        ", $this->post_type));

        $categories = array_merge(array('general'), $categories);

        // Cache the results
        self::$static_cache[$cache_key] = $categories;
        set_transient($this->transient_key . '_categories', $categories, HOUR_IN_SECONDS);

        return $categories;
    }
    
    /**
     * Handle AJAX inline edit
     */
    public function handle_inline_edit() {
        check_ajax_referer('dt_inline_edit', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $tag_id = intval($_POST['tag_id']);
        $tag_value = wp_kses_post($_POST['tag_value']);
        $category = sanitize_text_field($_POST['category']);
        
        // Update post content
        wp_update_post(array(
            'ID' => $tag_id,
            'post_content' => $tag_value
        ));
        
        // Update category
        update_post_meta($tag_id, '_dt_category', $category);
        
        // Clear cache
        delete_transient($this->transient_key);
        
        wp_send_json_success(array(
            'value_preview' => wp_trim_words(wp_strip_all_tags($tag_value), 10, '...'),
            'category_display' => ucfirst($category)
        ));
    }
    
    /**
     * Handle AJAX bulk delete
     */
    public function handle_bulk_delete() {
        check_ajax_referer('dt_bulk_delete', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $tag_ids = array_map('intval', $_POST['tag_ids']);
        
        foreach ($tag_ids as $tag_id) {
            wp_delete_post($tag_id, true);
        }
        
        // Clear cache
        delete_transient($this->transient_key);
        
        wp_send_json_success();
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions() {
        // Handle any additional bulk actions here
    }
    
    /**
     * Show admin notices
     */
    private function show_notices() {
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            $notices = array(
                'updated' => __('Tags updated successfully.', 'wp-dynamic-tags'),
                'deleted' => __('Tags deleted successfully.', 'wp-dynamic-tags'),
                'error' => __('An error occurred.', 'wp-dynamic-tags')
            );
            
            if (isset($notices[$message])) {
                $class = $message === 'error' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . $class . ' is-dismissible"><p>' . $notices[$message] . '</p></div>';
            }
        }
    }
    
    /**
     * Sanitize tag key
     */
    private function sanitize_tag_key($key) {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_-]/', '_', $key);
        $key = preg_replace('/[_-]+/', '_', $key);
        $key = trim($key, '_-');
        return $key;
    }

    /**
     * Get filtered tags with pagination - cached version
     */
    private function get_filtered_tags_paginated_cached($search, $category, $page, $per_page) {
        $cache_key = 'dt_filtered_tags_' . md5($search . $category . $page . $per_page) . '_' . $this->cache_version;

        // Check static cache first
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        // Check transient cache
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            self::$static_cache[$cache_key] = $cached_result;
            return $cached_result;
        }

        // Build optimized query
        $args = array(
            'post_type' => $this->post_type,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => false
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        if (!empty($category)) {
            $args['meta_query'] = array(
                array(
                    'key' => '_dt_category',
                    'value' => $category,
                    'compare' => '='
                )
            );
        }

        $query = new WP_Query($args);

        $result = array(
            'tags' => $query->posts,
            'total' => $query->found_posts
        );

        // Cache the result
        self::$static_cache[$cache_key] = $result;
        set_transient($cache_key, $result, $this->cache_duration);

        return $result;
    }

    /**
     * Get tag categories - cached version
     */
    private function get_tag_categories_cached() {
        $cache_key = 'dt_tag_categories_' . $this->cache_version;

        // Check static cache first
        if (isset(self::$static_cache[$cache_key])) {
            return self::$static_cache[$cache_key];
        }

        // Check transient cache
        $cached_categories = get_transient($cache_key);
        if ($cached_categories !== false) {
            self::$static_cache[$cache_key] = $cached_categories;
            return $cached_categories;
        }

        // Query categories
        global $wpdb;
        $categories = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_dt_category'
             AND meta_value != ''
             ORDER BY meta_value"
        );

        $categories = array_merge(array('general'), $categories);

        // Cache the results
        self::$static_cache[$cache_key] = $categories;
        set_transient($cache_key, $categories, $this->cache_duration);

        return $categories;
    }

    /**
     * Clear all bulk manager caches
     */
    public function clear_cache() {
        // Clear static cache
        self::$static_cache = array();

        // Clear all related transients
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            '%dt_filtered_tags_%'
        ));

        delete_transient('dt_bulk_groups_' . $this->cache_version);
        delete_transient('dt_tag_categories_' . $this->cache_version);
        delete_transient($this->transient_key);
    }
}