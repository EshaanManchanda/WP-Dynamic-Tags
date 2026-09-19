<?php
/**
 * Uninstall WP Dynamic Tags Plugin
 *
 * @package WP_Dynamic_Tags
 * @version 3.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 */
function wp_dynamic_tags_uninstall() {
    global $wpdb;

    // Get the option to determine if data should be deleted
    // Note: WordPress validates permissions before running uninstall.php
    $delete_data = get_option('wp_dynamic_tags_delete_on_uninstall', false);

    if (!$delete_data) {
        // User chose to keep data, log and exit early
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Uninstall - Data retention enabled, preserving all data');
        }

        // Store uninstall timestamp for reference (wrapped in try-catch to prevent errors)
        try {
            update_option('wp_dynamic_tags_last_uninstall', current_time('mysql'));
            update_option('wp_dynamic_tags_data_preserved', true);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WP Dynamic Tags: Failed to update preservation options: ' . $e->getMessage());
            }
        }

        return;
    }

    // Log deletion start
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('WP Dynamic Tags: Beginning complete data deletion...');
    }

    try {
        // Create a final backup before deletion
        wp_dynamic_tags_create_backup_before_deletion();
        // 1. Delete all dynamic tag posts (including trash)
        $post_type = 'dynamic_tag';

        // Count posts before deletion for verification
        $initial_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            )
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Found {$initial_count} total posts (including trash)");
        }

        // Get all dynamic tag posts with explicit status array (including trash)
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'trash', 'pending', 'private', 'future', 'auto-draft', 'inherit'),
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: get_posts() returned " . count($posts) . " posts");
        }

        // Delete each post and ALL its meta
        $deleted_posts = 0;
        foreach ($posts as $post_id) {
            // Delete ALL post meta for this post (not just specific keys)
            $wpdb->delete(
                $wpdb->postmeta,
                array('post_id' => $post_id),
                array('%d')
            );

            // Force delete the post (bypass trash)
            if (wp_delete_post($post_id, true)) {
                $deleted_posts++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: wp_delete_post() deleted {$deleted_posts} posts via API");
        }

        // Fallback: Direct database cleanup for any missed posts
        $remaining_posts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            )
        );

        if (!empty($remaining_posts)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Found " . count($remaining_posts) . " remaining posts, cleaning up via direct database query");
            }

            foreach ($remaining_posts as $post_id) {
                // Delete post meta
                $wpdb->delete(
                    $wpdb->postmeta,
                    array('post_id' => $post_id),
                    array('%d')
                );
            }

            // Delete posts directly from database
            $direct_deleted = $wpdb->delete(
                $wpdb->posts,
                array('post_type' => $post_type),
                array('%s')
            );

            $deleted_posts += $direct_deleted;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Direct database cleanup deleted {$direct_deleted} additional posts");
            }
        }

        // Final verification
        $final_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            )
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Post deletion complete. Started with {$initial_count}, deleted {$deleted_posts}, remaining: {$final_count}");
        }

        // 2. Delete taxonomy terms (tag groups)
        $taxonomy = 'tag_groups';

        // Count terms before deletion for verification
        $initial_term_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            )
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Found {$initial_term_count} total tag groups");
        }

        // Get all terms (including empty ones)
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids'
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $term_count = is_array($terms) ? count($terms) : 0;
            error_log("WP Dynamic Tags: get_terms() returned {$term_count} terms");
        }

        // Delete each term using WordPress API
        $deleted_terms = 0;
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term_id) {
                if (wp_delete_term($term_id, $taxonomy)) {
                    $deleted_terms++;
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: wp_delete_term() deleted {$deleted_terms} terms via API");
        }

        // Fallback: Direct database cleanup for any remaining term relationships
        $remaining_relationships = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = %s",
                $taxonomy
            )
        );

        if ($remaining_relationships > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Found {$remaining_relationships} remaining term relationships, cleaning up");
            }

            // Delete term relationships for this taxonomy
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE tr FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.taxonomy = %s",
                    $taxonomy
                )
            );
        }

        // Get remaining term_taxonomy entries
        $remaining_term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            )
        );

        if (!empty($remaining_term_ids)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Found " . count($remaining_term_ids) . " remaining terms in term_taxonomy, cleaning up");
            }

            // Delete from term_taxonomy
            $direct_deleted = $wpdb->delete(
                $wpdb->term_taxonomy,
                array('taxonomy' => $taxonomy),
                array('%s')
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Direct database cleanup deleted {$direct_deleted} term_taxonomy entries");
            }

            // Delete orphaned terms from terms table (only if not used by other taxonomies)
            foreach ($remaining_term_ids as $term_id) {
                $usage_count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                        $term_id
                    )
                );

                // If term is not used by any other taxonomy, delete it
                if ($usage_count == 0) {
                    $wpdb->delete(
                        $wpdb->terms,
                        array('term_id' => $term_id),
                        array('%d')
                    );
                }
            }

            $deleted_terms += $direct_deleted;
        }

        // Final verification
        $final_term_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            )
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Dynamic Tags: Term deletion complete. Started with {$initial_term_count}, deleted {$deleted_terms}, remaining: {$final_term_count}");
        }

        // 3. Delete custom table if it exists
        $table_name = $wpdb->prefix . 'dynamic_tags';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            // Count records before deletion
            $table_record_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

            $wpdb->query("DROP TABLE IF EXISTS $table_name");

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Dropped custom table with {$table_record_count} records");
            }
        }

        // 4. Delete all plugin options
        delete_option('wp_dynamic_tags_version');
        delete_option('wp_dynamic_tags_db_version');
        delete_option('wp_dynamic_tags_delete_on_uninstall');
        delete_option('wp_dynamic_tags_storage_mode');
        delete_option('wp_dynamic_tags_migration_status');
        delete_option('wp_dynamic_tags_cache_version');
        delete_option('dt_menu_notice_dismissed');

        // 5. Delete all transients
        $transients = array(
            'wp_dynamic_tags_cache',
            'dt_registration_lock',
            'dt_conflict_notice_',
            'dt_performance_stats',
            'dt_tag_groups_',
            'dt_migration_progress',
            'dt_table_stats'
        );

        foreach ($transients as $transient_prefix) {
            // Delete transients that match the prefix
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE %s
                    OR option_name LIKE %s",
                    '_transient_' . $transient_prefix . '%',
                    '_transient_timeout_' . $transient_prefix . '%'
                )
            );
        }

        // 6. Delete user meta
        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta}
            WHERE meta_key LIKE '_dt_%'
            OR meta_key LIKE 'dt_%'"
        );

        // 7. Clear any cached data
        wp_cache_flush();

        // 8. Final verification and summary
        $final_post_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                'dynamic_tag'
            )
        );

        $final_term_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                'tag_groups'
            )
        );

        $final_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name);

        // 9. Log uninstall completion with summary
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Complete data cleanup finished successfully');
            error_log('WP Dynamic Tags: CLEANUP SUMMARY:');
            error_log("  - Posts remaining: {$final_post_count} (should be 0)");
            error_log("  - Terms remaining: {$final_term_count} (should be 0)");
            error_log("  - Custom table exists: " . ($final_table_exists ? 'YES (should be NO)' : 'NO (correct)'));

            if ($final_post_count > 0 || $final_term_count > 0 || $final_table_exists) {
                error_log('WP Dynamic Tags: WARNING - Some data may not have been fully deleted!');
            } else {
                error_log('WP Dynamic Tags: ✓ All plugin data successfully removed');
            }
        }

    } catch (Exception $e) {
        // Log error if debug is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags Uninstall Error: ' . $e->getMessage());
        }
    }
}

/**
 * Create backup before deletion
 */
function wp_dynamic_tags_create_backup_before_deletion() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('WP Dynamic Tags: Creating backup before deletion...');
    }

    try {
        $backup_data = array(
            'timestamp' => current_time('mysql'),
            'site_url' => get_site_url(),
            'data' => array()
        );

        // Backup posts
        $posts = get_posts(array(
            'post_type' => 'dynamic_tag',
            'post_status' => 'any',
            'posts_per_page' => -1
        ));

        if (!empty($posts)) {
            $backup_data['data']['posts'] = array();
            foreach ($posts as $post) {
                $backup_data['data']['posts'][] = array(
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'status' => $post->post_status,
                    'date' => $post->post_date
                );
            }
        }

        // Backup custom table
        $table_name = $wpdb->prefix . 'dynamic_tags';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $table_data = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);
            if (!empty($table_data)) {
                $backup_data['data']['table'] = $table_data;
            }
        }

        // Backup groups
        $groups = get_terms(array(
            'taxonomy' => 'tag_groups',
            'hide_empty' => false
        ));

        if (!empty($groups) && !is_wp_error($groups)) {
            $backup_data['data']['groups'] = array();
            foreach ($groups as $group) {
                $backup_data['data']['groups'][] = array(
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'description' => $group->description
                );
            }
        }

        // Store backup as an option (will be auto-deleted when options are deleted)
        update_option('wp_dynamic_tags_deletion_backup', $backup_data);

        // Also try to write to uploads directory
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            $backup_file = $upload_dir['basedir'] . '/wp-dynamic-tags-backup-' . date('Y-m-d-His') . '.json';

            // Use error suppression and check return value to prevent uninstall failure
            $result = @file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));

            if ($result !== false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Backup saved to: ' . $backup_file);
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Dynamic Tags: Failed to write backup file (permissions issue or directory not writable)');
                }
            }
        }

    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Dynamic Tags: Backup creation failed: ' . $e->getMessage());
        }
    }
}

// Run the uninstall function
wp_dynamic_tags_uninstall();
