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
    // --- Admin screens (admin/class-admin.php)
    'dt_preview_tag'           => ['nonce' => 'dt_preview', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_statistics'      => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_create_table'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_check_table_health'    => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Import and export (includes/class-table-import-export.php)
    'dt_table_export'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_import'          => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_table_validate_import' => ['nonce' => 'dt_table_manager', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],

    // --- Tag groups
    'dt_create_group'          => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_edit_group'            => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
    'dt_delete_group'          => ['nonce' => 'dt_group_operation', 'key' => 'nonce', 'cap' => 'manage_options', 'kind' => 'json'],
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
