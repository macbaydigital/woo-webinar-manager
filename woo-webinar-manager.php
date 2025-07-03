<?php
/**
 * Plugin Name: Woo Webinar Manager
 * Description: Verwaltet Webinar-Produkte im WooCommerce Account-Bereich mit GeneratePress Theme Integration
 * Version: 1.0.0
 * Author: Macbay Digital
 * Author URI: https://macbay-digital.com
 * Text Domain: woo-webinar
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * GitHub Plugin URI: macbay-digital/woo-webinar-manager
 * GitHub Branch: main
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WOO_WEBINAR_VERSION', '1.0.0');
define('WOO_WEBINAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_WEBINAR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_WEBINAR_PLUGIN_FILE', __FILE__);
define('WOO_WEBINAR_GITHUB_REPO', 'macbay-digital/woo-webinar-manager');

// Check if WooCommerce is active
register_activation_hook(__FILE__, 'woo_webinar_check_dependencies');
function woo_webinar_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Woo Webinar Manager benötigt WooCommerce um zu funktionieren.', 'woo-webinar'));
    }
}

// Load plugin classes
require_once WOO_WEBINAR_PLUGIN_DIR . 'includes/class-woo-webinar-updater.php';
require_once WOO_WEBINAR_PLUGIN_DIR . 'includes/class-woo-webinar-core.php';

// Initialize the plugin
add_action('plugins_loaded', 'woo_webinar_init');
function woo_webinar_init() {
    if (class_exists('WooCommerce')) {
        // Initialize updater
        new WooWebinarUpdater(__FILE__, WOO_WEBINAR_GITHUB_REPO, WOO_WEBINAR_VERSION);
        
        // Initialize core functionality
        WooWebinarManager::get_instance();
    } else {
        add_action('admin_notices', 'woo_webinar_missing_woocommerce_notice');
    }
}

function woo_webinar_missing_woocommerce_notice() {
    ?>
    <div class="error">
        <p><?php _e('Woo Webinar Manager benötigt WooCommerce um zu funktionieren.', 'woo-webinar'); ?></p>
    </div>
    <?php
}

// Plugin activation hook
register_activation_hook(__FILE__, 'woo_webinar_activate');
function woo_webinar_activate() {
    // Create database tables if needed
    // Set default options
    add_option('woo_webinar_version', WOO_WEBINAR_VERSION);
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'woo_webinar_deactivate');
function woo_webinar_deactivate() {
    // Clean up scheduled events
    wp_clear_scheduled_hook('woo_webinar_cleanup');
}
