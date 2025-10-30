<?php
/**
 * Plugin Name: ETracker
 * Plugin URI: https://charter.com/
 * Description: Manage inventory enforcement exceptions sourced from MongoDB.
 * Version: 0.1.0
 * Author: Charter Communications
 * License: GPLv2 or later
 * Text Domain: etracker
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ETRACKER_PLUGIN_VERSION', '0.1.0');
define('ETRACKER_PLUGIN_FILE', __FILE__);
define('ETRACKER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ETRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ETRACKER_MANAGE_CAPABILITY', 'manage_etracker_exceptions');

require_once ETRACKER_PLUGIN_DIR . 'includes/functions-autoload.php';

if (defined('WP_CLI') && WP_CLI && class_exists('\\ETracker\\CLI\\Command')) {
    \ETracker\CLI\Command::register();
}

if (class_exists('\\ETracker\\Activation\\Activator')) {
    register_activation_hook(__FILE__, ['\\ETracker\\Activation\\Activator', 'activate']);
    register_deactivation_hook(__FILE__, ['\\ETracker\\Activation\\Activator', 'deactivate']);
}

add_action('plugins_loaded', static function (): void {
    if (! class_exists('\\ETracker\\ETracker')) {
        return;
    }

    $plugin = \ETracker\ETracker::instance();
    $plugin->init();
});

