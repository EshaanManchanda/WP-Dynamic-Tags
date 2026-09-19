<?php
/**
 * Conflict Manager Admin Interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Conflict_Manager_Admin {

    private $post_type = 'dynamic_tag';
    private $taxonomy = 'tag_groups';

    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_dt_auto_resolve_conflicts', array($this, 'ajax_auto_resolve_conflicts'));
        add_action('wp_ajax_dt_merge_duplicate_tags', array($this, 'ajax_merge_duplicate_tags'));
        add_action('wp_ajax_dt_delete_duplicate_tags', array($this, 'ajax_delete_duplicate_tags'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Conflict Manager', 'wp-dynamic-tags'),
            __('Conflicts', 'wp-dynamic-tags'),
            'manage_options',
            'dt-conflict-manager',
            array($this, 'conflict_manager_page')
        );
    }

    /**
     * Conflict manager page
     */
    public function conflict_manager_page() {
        // Security check: verify user has proper capabilities
        if (!current_user_can('manage_options')) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'wp-dynamic-tags'),
                __('Permission Denied', 'wp-dynamic-tags'),
                array('response' => 403)
            );
        }

        // Get conflict resolver instance
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();

        // Check if plugin instance exists
        if (!$plugin) {
            echo '<div class="wrap"><h1>' . esc_html__('Conflict Manager', 'wp-dynamic-tags') . '</h1>';
            echo '<p>' . esc_html__('Plugin instance not available.', 'wp-dynamic-tags') . '</p></div>';
            return;
        }

        // Check if conflict_resolver property exists and is accessible
        if (!isset($plugin->conflict_resolver) || !$plugin->conflict_resolver) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Conflict Manager', 'wp-dynamic-tags') . '</h1>';
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Conflict resolver is not initialized. Please try reloading the page or deactivating and reactivating the plugin.', 'wp-dynamic-tags');
            echo '</p></div>';
            echo '</div>';
            return;
        }

        $resolver = $plugin->conflict_resolver;

        // Get current conflicts
        $conflicts = $resolver->detect_all_conflicts();
        $stats = $resolver->get_conflict_statistics();

        ?>
        <div class="wrap">
            <h1><?php _e('Dynamic Tags - Conflict Manager', 'wp-dynamic-tags'); ?></h1>

            <?php $this->show_notices(); ?>

            <!-- Statistics Dashboard -->
            <div class="dt-stats-dashboard" style="display: flex; gap: 20px; margin: 20px 0;">
                <div class="postbox" style="flex: 1; padding: 15px;">
                    <h3><?php _e('Conflict Statistics', 'wp-dynamic-tags'); ?></h3>
                    <div class="dt-stat-item">
                        <span class="dt-stat-number" style="font-size: 24px; font-weight: bold; color: #d63638;"><?php echo $stats['total_conflicts']; ?></span>
                        <span class="dt-stat-label"><?php _e('Total Conflicts', 'wp-dynamic-tags'); ?></span>
                    </div>
                </div>

                <div class="postbox" style="flex: 1; padding: 15px;">
                    <h3><?php _e('Severity Breakdown', 'wp-dynamic-tags'); ?></h3>
                    <div style="display: flex; gap: 15px;">
                        <div class="dt-severity-stat">
                            <span style="color: #d63638; font-weight: bold;"><?php echo $stats['high_severity']; ?></span>
                            <small><?php _e('High', 'wp-dynamic-tags'); ?></small>
                        </div>
                        <div class="dt-severity-stat">
                            <span style="color: #ffc107; font-weight: bold;"><?php echo $stats['medium_severity']; ?></span>
                            <small><?php _e('Medium', 'wp-dynamic-tags'); ?></small>
                        </div>
                        <div class="dt-severity-stat">
                            <span style="color: #28a745; font-weight: bold;"><?php echo $stats['low_severity']; ?></span>
                            <small><?php _e('Low', 'wp-dynamic-tags'); ?></small>
                        </div>
                    </div>
                </div>

                <div class="postbox" style="flex: 1; padding: 15px;">
                    <h3><?php _e('Auto-Resolvable', 'wp-dynamic-tags'); ?></h3>
                    <div class="dt-stat-item">
                        <span class="dt-stat-number" style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo $stats['auto_resolvable']; ?></span>
                        <span class="dt-stat-label"><?php _e('Can be auto-resolved', 'wp-dynamic-tags'); ?></span>
                    </div>
                    <?php if ($stats['auto_resolvable'] > 0): ?>
                        <button type="button" id="dt-auto-resolve-all" class="button button-primary" style="margin-top: 10px;">
                            <?php _e('Auto-Resolve All', 'wp-dynamic-tags'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($conflicts)): ?>
                <!-- No Conflicts -->
                <div class="notice notice-success">
                    <h3><?php _e('🎉 No Conflicts Found!', 'wp-dynamic-tags'); ?></h3>
                    <p><?php _e('Your dynamic tags system is working perfectly with no conflicts detected.', 'wp-dynamic-tags'); ?></p>
                </div>
            <?php else: ?>
                <!-- Conflicts List -->
                <div class="postbox">
                    <h2 class="hndle"><?php _e('Detected Conflicts', 'wp-dynamic-tags'); ?></h2>
                    <div class="inside">
                        <?php foreach ($conflicts as $tag_key => $conflict): ?>
                            <div class="dt-conflict-item" data-tag-key="<?php echo esc_attr($tag_key); ?>" style="border: 1px solid #ddd; margin: 15px 0; padding: 15px; border-radius: 5px;">

                                <!-- Conflict Header -->
                                <div class="dt-conflict-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <div>
                                        <h3 style="margin: 0; color: #333;">
                                            <?php _e('Tag:', 'wp-dynamic-tags'); ?>
                                            <code><?php echo esc_html($tag_key); ?></code>
                                        </h3>
                                        <div class="dt-conflict-meta">
                                            <span class="dt-severity dt-severity-<?php echo esc_attr($conflict['severity']); ?>" style="padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                                                <?php echo esc_html(strtoupper($conflict['severity'])); ?>
                                            </span>
                                            <span class="dt-tag-count" style="margin-left: 10px; color: #666;">
                                                <?php printf(__('%d duplicate tags', 'wp-dynamic-tags'), count($conflict['tags'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="dt-conflict-actions">
                                        <?php if ($conflict['recommended_action'] === 'delete_ungrouped'): ?>
                                            <button type="button" class="button button-secondary dt-quick-resolve"
                                                    data-action="delete_ungrouped"
                                                    data-tag-key="<?php echo esc_attr($tag_key); ?>">
                                                <?php _e('Delete Ungrouped', 'wp-dynamic-tags'); ?>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" class="button dt-manual-resolve"
                                                data-tag-key="<?php echo esc_attr($tag_key); ?>">
                                            <?php _e('Manual Resolve', 'wp-dynamic-tags'); ?>
                                        </button>
                                    </div>
                                </div>

                                <!-- Conflict Details -->
                                <div class="dt-conflict-details">
                                    <div class="dt-recommended-action" style="background: #f0f8ff; padding: 10px; border-radius: 3px; margin-bottom: 15px;">
                                        <strong><?php _e('Recommended Action:', 'wp-dynamic-tags'); ?></strong>
                                        <?php echo $this->get_action_description($conflict['recommended_action']); ?>
                                    </div>

                                    <!-- Tags Table -->
                                    <table class="wp-list-table widefat fixed striped">
                                        <thead>
                                            <tr>
                                                <th><?php _e('Select', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Tag ID', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Content', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Groups', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Shortcode', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Modified', 'wp-dynamic-tags'); ?></th>
                                                <th><?php _e('Actions', 'wp-dynamic-tags'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($conflict['tags'] as $tag): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox"
                                                               name="conflict_tags[<?php echo esc_attr($tag_key); ?>][]"
                                                               value="<?php echo esc_attr($tag['id']); ?>"
                                                               class="dt-tag-checkbox">
                                                    </td>
                                                    <td><?php echo esc_html($tag['id']); ?></td>
                                                    <td>
                                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                            <?php echo esc_html(wp_trim_words($tag['content'], 10)); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($tag['is_grouped']): ?>
                                                            <span class="dt-grouped" style="color: #28a745;">
                                                                <?php echo esc_html(implode(', ', $tag['groups'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="dt-ungrouped" style="color: #d63638;">
                                                                <?php _e('Ungrouped', 'wp-dynamic-tags'); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <code>[<?php echo esc_html($tag['shortcode']); ?>]</code>
                                                    </td>
                                                    <td><?php echo esc_html($tag['modified']); ?></td>
                                                    <td>
                                                        <a href="<?php echo get_edit_post_link($tag['id']); ?>" class="button button-small">
                                                            <?php _e('Edit', 'wp-dynamic-tags'); ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Manual Resolution Options -->
                                <div class="dt-manual-options" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 3px;">
                                    <h4><?php _e('Manual Resolution Options', 'wp-dynamic-tags'); ?></h4>

                                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                        <button type="button" class="button dt-merge-tags" data-tag-key="<?php echo esc_attr($tag_key); ?>">
                                            <?php _e('Merge Selected Tags', 'wp-dynamic-tags'); ?>
                                        </button>

                                        <button type="button" class="button dt-delete-selected" data-tag-key="<?php echo esc_attr($tag_key); ?>">
                                            <?php _e('Delete Selected', 'wp-dynamic-tags'); ?>
                                        </button>

                                        <button type="button" class="button dt-assign-group" data-tag-key="<?php echo esc_attr($tag_key); ?>">
                                            <?php _e('Assign to Group', 'wp-dynamic-tags'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('Understanding Conflicts', 'wp-dynamic-tags'); ?></h2>
                <div class="inside">
                    <h4><?php _e('Conflict Types:', 'wp-dynamic-tags'); ?></h4>
                    <ul>
                        <li><strong><?php _e('Ungrouped Duplicates:', 'wp-dynamic-tags'); ?></strong> <?php _e('Same tag exists both grouped and ungrouped', 'wp-dynamic-tags'); ?></li>
                        <li><strong><?php _e('Multiple Groups:', 'wp-dynamic-tags'); ?></strong> <?php _e('Same tag in different groups', 'wp-dynamic-tags'); ?></li>
                        <li><strong><?php _e('Content Differences:', 'wp-dynamic-tags'); ?></strong> <?php _e('Same tag name with different content', 'wp-dynamic-tags'); ?></li>
                    </ul>

                    <h4><?php _e('Resolution Strategies:', 'wp-dynamic-tags'); ?></h4>
                    <ul>
                        <li><strong><?php _e('Auto-Resolve:', 'wp-dynamic-tags'); ?></strong> <?php _e('Automatically handles simple conflicts', 'wp-dynamic-tags'); ?></li>
                        <li><strong><?php _e('Merge:', 'wp-dynamic-tags'); ?></strong> <?php _e('Combines multiple tags into one', 'wp-dynamic-tags'); ?></li>
                        <li><strong><?php _e('Delete:', 'wp-dynamic-tags'); ?></strong> <?php _e('Removes duplicate tags', 'wp-dynamic-tags'); ?></li>
                        <li><strong><?php _e('Group Assignment:', 'wp-dynamic-tags'); ?></strong> <?php _e('Assigns ungrouped tags to appropriate groups', 'wp-dynamic-tags'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <style>
        .dt-severity-high { background: #f8d7da; color: #721c24; }
        .dt-severity-medium { background: #fff3cd; color: #856404; }
        .dt-severity-low { background: #d4edda; color: #155724; }
        .dt-severity-stat { text-align: center; }
        .dt-conflict-item { transition: all 0.3s ease; }
        .dt-conflict-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .dt-manual-options { border: 1px solid #ddd; }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle manual options
            $('.dt-manual-resolve').on('click', function() {
                var $item = $(this).closest('.dt-conflict-item');
                $item.find('.dt-manual-options').toggle();
            });

            // Auto-resolve all conflicts
            $('#dt-auto-resolve-all').on('click', function() {
                if (confirm('<?php _e('Are you sure you want to auto-resolve all conflicts? This action cannot be undone.', 'wp-dynamic-tags'); ?>')) {
                    performAutoResolve();
                }
            });

            // Quick resolve
            $('.dt-quick-resolve').on('click', function() {
                var action = $(this).data('action');
                var tagKey = $(this).data('tag-key');

                if (confirm('<?php _e('Are you sure you want to resolve this conflict?', 'wp-dynamic-tags'); ?>')) {
                    performQuickResolve(action, tagKey);
                }
            });

            function performAutoResolve() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_auto_resolve_conflicts',
                        nonce: '<?php echo wp_create_nonce('dt_auto_resolve_conflicts'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }
                });
            }

            function performQuickResolve(action, tagKey) {
                var $item = $('.dt-conflict-item[data-tag-key="' + tagKey + '"]');
                var tagIds = [];

                $item.find('.dt-tag-checkbox').each(function() {
                    tagIds.push($(this).val());
                });

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dt_resolve_conflict',
                        resolution_action: action,
                        tag_ids: tagIds,
                        nonce: '<?php echo wp_create_nonce('dt_resolve_conflict'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $item.fadeOut(function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Get action description
     */
    private function get_action_description($action) {
        $descriptions = array(
            'delete_ungrouped' => __('Delete the ungrouped duplicate tags and keep the grouped versions.', 'wp-dynamic-tags'),
            'merge_with_content_choice' => __('Merge tags with different content - choose which content to keep.', 'wp-dynamic-tags'),
            'consolidate_groups' => __('Consolidate multiple grouped versions into a single group.', 'wp-dynamic-tags'),
            'assign_to_group' => __('Assign ungrouped tags to an appropriate group.', 'wp-dynamic-tags'),
            'manual_review' => __('This conflict requires manual review and resolution.', 'wp-dynamic-tags')
        );

        return isset($descriptions[$action]) ? $descriptions[$action] : __('Unknown action required.', 'wp-dynamic-tags');
    }

    /**
     * Show admin notices
     */
    private function show_notices() {
        if (isset($_GET['resolved'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Conflicts resolved successfully!', 'wp-dynamic-tags'); ?></p>
            </div>
            <?php
        }

        if (isset($_GET['error'])) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('An error occurred while resolving conflicts.', 'wp-dynamic-tags'); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'dynamic_tag_page_dt-conflict-manager') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-list-table');
    }

    /**
     * AJAX: Auto-resolve conflicts
     */
    public function ajax_auto_resolve_conflicts() {
        check_ajax_referer('dt_auto_resolve_conflicts', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-dynamic-tags'));
            return;
        }

        $plugin = WP_Dynamic_Tags_Plugin::get_instance();

        if (!$plugin || !isset($plugin->conflict_resolver) || !$plugin->conflict_resolver) {
            wp_send_json_error(__('Conflict resolver not available. Please try reloading the page.', 'wp-dynamic-tags'));
            return;
        }

        $resolver = $plugin->conflict_resolver;

        try {
            $results = $resolver->auto_resolve_simple_conflicts();

            wp_send_json_success(array(
                'resolved_count' => count($results),
                'results' => $results,
                'message' => sprintf(__('%d conflict(s) resolved successfully.', 'wp-dynamic-tags'), count($results))
            ));
        } catch (Exception $e) {
            wp_send_json_error(sprintf(__('Error resolving conflicts: %s', 'wp-dynamic-tags'), $e->getMessage()));
        }
    }
}