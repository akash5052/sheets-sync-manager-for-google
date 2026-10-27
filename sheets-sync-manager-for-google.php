<?php
/**
 * Plugin Name: Sheets Sync Manager for Google
 * Plugin URI: https://kalksolutions.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Description: Synchronize Elementor Forms, Contact Form 7, WooCommerce orders and customer accounts with Google Sheets.
 * Version: 1.0.0
 * Author: Akash Kate
 * Author URI: https://profiles.wordpress.org/akash5052/
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: sheets-sync-manager-for-google
 */
if (!defined('ABSPATH')) { exit; }

if (!defined('WPGSS_VERSION')) { define('WPGSS_VERSION', '1.0.0'); }
if (!defined('WPGSS_FILE')) { define('WPGSS_FILE', __FILE__); }
if (!defined('WPGSS_PATH')) { define('WPGSS_PATH', plugin_dir_path(__FILE__)); }
if (!defined('WPGSS_URL')) { define('WPGSS_URL', plugin_dir_url(__FILE__)); }

// Keep bootstrap defensive: optional integrations must never be required directly.
require_once WPGSS_PATH . 'includes/class-google-sheets.php';
require_once WPGSS_PATH . 'includes/class-sync-manager.php';
require_once WPGSS_PATH . 'includes/class-admin.php';

register_activation_hook(__FILE__, array('WPGSS_Sync_Manager', 'activate'));
register_deactivation_hook(__FILE__, array('WPGSS_Sync_Manager', 'deactivate'));
add_action('plugins_loaded', function () {
    WPGSS_Sync_Manager::instance();
    if (is_admin()) { WPGSS_Admin::instance(); }
});
