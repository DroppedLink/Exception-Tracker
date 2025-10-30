<?php

namespace ETracker\Activation;

use function apply_filters;
use function do_action;
use function get_role;
use function dbDelta;

class Activator
{
    public static function activate(): void
    {
        self::ensure_capabilities();
        self::create_audit_table();
    }

    public static function deactivate(): void
    {
        do_action('etracker/deactivated');
    }

    private static function ensure_capabilities(): void
    {
        $capability = ETRACKER_MANAGE_CAPABILITY;
        $roles = apply_filters('etracker/manage_capability_roles', ['administrator']);

        foreach ((array) $roles as $role_name) {
            $role = get_role($role_name);
            if (! $role instanceof \WP_Role) {
                continue;
            }

            if (! $role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }

    private static function create_audit_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'etracker_audit';
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_id VARCHAR(64) NOT NULL,
            item_type VARCHAR(32) NOT NULL,
            item_key VARCHAR(190) NOT NULL,
            previous_state LONGTEXT NULL,
            new_state LONGTEXT NULL,
            reason TEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            user_name VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY document_id (document_id),
            KEY item_key (item_key)
        ) {$charset};";

        dbDelta($sql);
    }
}

