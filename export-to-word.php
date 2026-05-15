<?php
/**
 * Plugin Name: Export to Word
 * Plugin URI: https://www.niallmcnulty.com
 * Description: Adds a button to export post content to a Word document.
 * Version: 1.0
 * Author: Niall McNulty
 * Author URI: https://www.niallmcnulty.com
 * Text Domain: export-to-word
 * Domain Path: /languages
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ETW_VERSION', '1.0');
define('ETW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ETW_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include PHPWord library
if (file_exists(ETW_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ETW_PLUGIN_DIR . 'vendor/autoload.php';
}

// Include the main plugin class
require_once ETW_PLUGIN_DIR . 'includes/class-export-to-word.php';

// Initialize the plugin
function etw_init() {
    $plugin = new Export_To_Word();
    $plugin->run();
}
add_action('plugins_loaded', 'etw_init');

// Activation hook
register_activation_hook(__FILE__, 'etw_activate');

function etw_activate() {
    if (version_compare(PHP_VERSION, '7.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Export to Word requires PHP 7.0 or higher.', 'export-to-word'));
    }

    if (!extension_loaded('zip')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Export to Word requires the ZIP extension to be installed.', 'export-to-word'));
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'etw_deactivate');

function etw_deactivate() {
    // Perform any cleanup if necessary
}
