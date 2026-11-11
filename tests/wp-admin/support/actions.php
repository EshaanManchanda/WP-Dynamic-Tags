<?php
/**
 * Action-security registry: one entry for every wp_ajax_* action the plugin registers.
 *
 * nonce : the nonce action the handler must verify          key  : request key that carries the nonce
 * cap   : capability the handler must require               kind : json (answers through wp_send_json / wp_die),
 *                                                                 stream (sends a file and exits; covered separately)
 * known : bug id from docs/BUG-REGISTER.md while the handler still violates this entry
 *
 * ActionSecurityTest generates its tests from this table and fails if the plugin registers an action that is not
 * listed here (or lists one that no longer exists), so a new endpoint cannot ship without a security decision.
 */
return [
    // --- Table Manager page (admin/table-manager-admin.php)
    'dt_table_migrate'         => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_sync'            => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_reset'           => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_statistics'      => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_create_table'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_check_table_health'    => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_export'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_import'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_validate_import' => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Table Manager facade (includes/class-table-manager.php): nobody creates this nonce today (S6)
    'dt_table_create_tag'      => ['nonce' => 'wp_dynamic_tags_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_update_tag'      => ['nonce' => 'wp_dynamic_tags_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_delete_tag'      => ['nonce' => 'wp_dynamic_tags_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_get_tags'        => ['nonce' => 'wp_dynamic_tags_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_bulk_action'     => ['nonce' => 'wp_dynamic_tags_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Main plugin file
    'dt_dismiss_menu_notice'   => ['nonce' => 'dt_dismiss_notice', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_test_shortcode'        => ['nonce' => 'dt_test_shortcode', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_check_duplicate'       => ['nonce' => 'dt_check_duplicate', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_duplicate_tag'         => ['nonce' => 'dt_duplicate', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Bulk manager / old import-export (deleted in Phase 4)
    'dt_inline_edit'           => ['nonce' => 'dt_inline_edit', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_bulk_delete'           => ['nonce' => 'dt_bulk_delete', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_export_json'           => ['nonce' => 'dt_export', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'stream'],
    'dt_export_csv'            => ['nonce' => 'dt_export', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'stream'],

    // --- Conflict tools (deleted in Phase 4)
    'dt_auto_resolve_conflicts' => ['nonce' => 'dt_auto_resolve_conflicts', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_resolve_conflict'      => ['nonce' => 'dt_resolve_conflict', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Tag groups
    'dt_create_group'          => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_edit_group'            => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_delete_group'          => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_assign_groups'         => ['nonce' => 'dt_assign_groups', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_get_group_details'     => ['nonce' => 'dt_get_group_details', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_get_group_edit_data'   => ['nonce' => 'dt_get_group_edit_data', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- License
    'dt_activate_license'      => ['nonce' => 'dt_license_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_deactivate_license'    => ['nonce' => 'dt_license_nonce', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Migration (includes/class-migration-admin.php)
    'dt_migration_step'        => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_status'      => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_dry_run'     => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_verify'      => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_cleanup'     => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_resolve'     => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_restore'     => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_migration_rescan'      => ['nonce' => 'dt_migration', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
];
