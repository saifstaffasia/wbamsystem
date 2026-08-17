<?php
/**
 * Plugin Name: WBAM Hub
 * Description: WeBuyAnyMobile operations hub — used-device intake & IMEI registry, label printing, repair tickets, parts & vendor POs, and live sales/profit reporting on top of Shopify.
 * Version: 0.3.3
 * Author: Staff Asia
 * Text Domain: wbam-hub
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('WBAM_VER', '0.3.3');
define('WBAM_FILE', __FILE__);
define('WBAM_DIR', plugin_dir_path(__FILE__));
define('WBAM_URL', plugin_dir_url(__FILE__));

foreach ([
    'class-wbam-install',
    'class-wbam-settings',
    'class-wbam-shopify',
    'class-wbam-catalog',
    'class-wbam-units',
    'class-wbam-labels',
    'class-wbam-warehouse',
    'class-wbam-webhooks',
    'class-wbam-sync',
    'class-wbam-reports',
    'class-wbam-tickets',
    'class-wbam-parts',
    'class-wbam-notify',
    'class-wbam-rest',
    'class-wbam-admin',
] as $inc) {
    require_once WBAM_DIR . 'includes/' . $inc . '.php';
}

register_activation_hook(__FILE__, ['WBAM_Install', 'activate']);
add_action('plugins_loaded', ['WBAM_Install', 'maybe_upgrade']);

add_action('rest_api_init', ['WBAM_Rest', 'routes']);
add_action('admin_menu', ['WBAM_Admin', 'menu']);
add_action('admin_enqueue_scripts', ['WBAM_Admin', 'assets']);
add_action('admin_post_wbam_label', ['WBAM_Labels', 'render_from_request']);

// Cron.
add_action('wbam_nightly_sync', ['WBAM_Sync', 'nightly']);
add_action('wbam_retry_queue', ['WBAM_Sync', 'run_queue']);

// Public shortcodes (report for POS-side tablets, repair tracking page).
add_shortcode('wbam_report', ['WBAM_Reports', 'shortcode']);
add_shortcode('wbam_track', ['WBAM_Tickets', 'track_shortcode']);
