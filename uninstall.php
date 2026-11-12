<?php
/**
 * Uninstall WP Dynamic Tags Plugin
 *
 * @package WP_Dynamic_Tags
 * @version 3.1.0
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
        // Create a final backup before deletion (true when it was written to the private folder)
        $backup_in_file = wp_dynamic_tags_create_backup_before_deletion();
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

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name) {
            // Count records before deletion
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $table_record_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Dynamic Tags: Dropped custom table with {$table_record_count} records");
            }
        }

        // 4. Delete all plugin options
        // The option copy of the backup holds every tag (certificate tags carry student data). Once the private file exists it is
        // not needed: it used to stay in wp_options for good, although a comment said it went with the other options. If the file
        // could not be written the option stays, because then it is the only backup there is.
        if (!empty($backup_in_file)) {
            delete_option('wp_dynamic_tags_deletion_backup');
        }
        delete_option('wp_dynamic_tags_version');
        delete_option('wp_dynamic_tags_db_version');
        delete_option('wp_dynamic_tags_table_version'); // the schema version vouches for the table, which was just dropped
        delete_option('wp_dynamic_tags_track_usage');
        delete_option('wp_dynamic_tags_delete_on_uninstall');
        delete_option('wp_dynamic_tags_storage_mode');
        delete_option('wp_dynamic_tags_migration_status');
        delete_option('wp_dynamic_tags_cache_version');
        delete_option('dt_menu_notice_dismissed');

        // The rest of what the plugin keeps in wp_options: the debug log, the legacy-write record and flag, setup errors and
        // activation markers, group colours, the import/export counters.
        foreach (array(
            'wp_dynamic_tags_debug_log', 'wp_dynamic_tags_legacy_writes', 'wp_dynamic_tags_legacy_pending',
            'wp_dynamic_tags_table_creation_error', 'wp_dynamic_tags_table_error', 'wp_dynamic_tags_activated',
            'wp_dynamic_tags_activation_time', 'wp_dynamic_tags_table_integration_enabled',
            'dt_group_colors', 'dt_categories_migrated', 'dt_total_exports', 'dt_total_imports', 'dt_last_export', 'dt_last_import',
            // what the keep-data path of an earlier uninstall left behind (a plugin that was removed, reinstalled, then removed again)
            'wp_dynamic_tags_last_uninstall', 'wp_dynamic_tags_data_preserved',
        ) as $own_option) {
            delete_option($own_option);
        }

        // Migration state (see includes/class-migration-engine.php) and its own, now superseded, migration backups.
        // The uninstall backup written above is deliberately left in place.
        foreach (array('wp_dynamic_tags_migration', 'wp_dynamic_tags_migration_log', 'wp_dynamic_tags_migration_lock', 'wp_dynamic_tags_migration_status', 'wp_dynamic_tags_posts_cleaned') as $migration_option) {
            delete_option($migration_option);
        }
        wp_clear_scheduled_hook('dt_migration_cron');
        $migration_upload_dir = wp_upload_dir();
        if (empty($migration_upload_dir['error']) && !empty($migration_upload_dir['basedir'])) {
            foreach ((array) glob(trailingslashit($migration_upload_dir['basedir']) . 'wp-dynamic-tags-private/backup-*.json*') as $migration_backup) {
                wp_delete_file($migration_backup);
            }
        }

        // License state (see includes/class-license-manager.php)
        foreach (array('dt_plan', 'dt_license_key', 'dt_license_expiry', 'dt_license_server_url', 'dt_last_heartbeat') as $license_option) {
            delete_option($license_option);
        }
        wp_clear_scheduled_hook('dt_license_heartbeat');

        // 5. Delete all transients
        $transients = array(
            'wp_dynamic_tags_cache',
            'dt_registration_lock',
            'dt_conflict_notice_',
            'dt_performance_stats',
            'dt_tag_groups_',
            'dt_migration_progress',
            'dt_table_stats',
            'dt_heartbeat_',
            'dt_api_',                       // cached answers of {api:} placeholders
            'dt_export_',                    // pending export downloads (they hold every tag)
            'wp_dynamic_tags_data_restored',
            'wp_dynamic_tags_deactivated'
        );

        foreach ($transients as $transient_prefix) {
            // Delete transients that match the prefix
            // esc_like(): the underscores in these names are LIKE wildcards and would also match other plugins' rows (S8).
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE %s
                    OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_' . $transient_prefix) . '%',
                    $wpdb->esc_like('_transient_timeout_' . $transient_prefix) . '%'
                )
            );
        }

        // 6. Delete user meta: the exact key(s) this plugin writes. Pattern deletes (LIKE 'dt_%') removed other plugins'
        // user meta for every user on the site (S8).
        delete_metadata('user', 0, 'dt_menu_consolidation_dismissed', '', true);

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

        $final_table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name);

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
 * Create backup before deletion: an option (kept until the file exists) and a private file in the uploads folder.
 *
 * @return bool Whether the file was written.
 */
function wp_dynamic_tags_create_backup_before_deletion() {
    global $wpdb;

    $file_saved = false;

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
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own (prefix + dynamic_tags) or a core table from $wpdb; every value goes through prepare()
            $table_data = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

        // Also try to write to uploads, into the private, web-denied directory with an unguessable name
        // (tag content can hold personal data such as names and e-mail addresses, so a predictable
        // public file name is not acceptable).
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['error']) && !empty($upload_dir['basedir'])) {
            $private_dir = trailingslashit($upload_dir['basedir']) . 'wp-dynamic-tags-private';
            $result      = false;
            $backup_file = '';

            if (wp_mkdir_p($private_dir)) {
                if (!is_file($private_dir . '/index.php')) {
                    file_put_contents($private_dir . '/index.php', "<?php\n// Silence is golden.\n");
                }
                if (!is_file($private_dir . '/.htaccess')) {
                    file_put_contents($private_dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
                }
                $backup_file = $private_dir . '/uninstall-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(16)) . '.json';

                // Use error suppression and check return value to prevent uninstall failure
                $result = @file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));
                if ($result !== false) {
                    @chmod($backup_file, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- owner-only permissions for a private backup
                }
            }

            if ($result !== false) {
                $file_saved = true;
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

    return $file_saved;
}

// Run the uninstall function. WordPress runs uninstall.php once when a plugin is deleted, and on a network that is once for the
// whole network: every site keeps its own table, options and choice (delete or keep), so each one is handled in turn.
if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $dt_blog_id) {
        switch_to_blog((int) $dt_blog_id);
        wp_dynamic_tags_uninstall();
        restore_current_blog();
    }
} else {
    wp_dynamic_tags_uninstall();
}
