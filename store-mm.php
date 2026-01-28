<?php
/**
 * Plugin Name: Store MM
 * Plugin URI: 
 * Description: Store Manufacturing Marketplace Plugin for WooCommerce
 * Version: 1.0.7
 * Author: 
 * License: GPL v2 or later
 * Text Domain: store-mm
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('STORE_MM_VERSION', '1.0.7');
define('STORE_MM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STORE_MM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STORE_MM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Define workflow states
define('STORE_MM_STATE_DRAFT', 'draft');
define('STORE_MM_STATE_SUBMITTED', 'submitted');
define('STORE_MM_STATE_CHANGES_REQUESTED', 'changes_requested');
define('STORE_MM_STATE_PROTOTYPING', 'prototyping');
define('STORE_MM_STATE_APPROVED', 'approved');
define('STORE_MM_STATE_REJECTED', 'rejected');

// Properly load text domain for the plugin
add_action('init', function () {
    load_plugin_textdomain('store-mm', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}, 10);

// Include core files
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/submission-form.php';
require_once STORE_MM_PLUGIN_DIR . 'includes/workflow-enforcement.php';
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/designer-dashboard.php';

/**
 * Clean output and send JSON response - FIXED VERSION
 */
function store_mm_send_json_response($data, $is_success = true) {
    // Clean ALL output buffers completely
    $buffer_levels = ob_get_level();
    for ($i = 0; $i < $buffer_levels; $i++) {
        ob_end_clean();
    }

    // Set headers for JSON response
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        nocache_headers();
    }

    // Prepare response
    $response = $is_success 
        ? ['success' => true, 'data' => $data]
        : ['success' => false, 'data' => $data];

    // Output JSON and exit
    echo wp_json_encode($response);
    wp_die();
}

/**
 * Get Store category (top-level)
 */
function store_mm_get_store_category() {
    // Try to find a category named "Store" or similar
    $store_categories = get_terms([
        'taxonomy' => 'product_cat',
        'name' => 'Store',
        'hide_empty' => false,
        'parent' => 0,
    ]);

    if (!empty($store_categories)) {
        return array_shift($store_categories);
    }

    return null;
}

// FIX FOR WOOCOMMERCE CONVERSION TRACKING PLUGIN ISSUE
// This plugin loads translations too early and breaks AJAX
if (defined('DOING_AJAX') && DOING_AJAX) {
    // Suppress translation errors from other plugins during our AJAX calls
    add_action('init', function() {
        if (isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'store_mm_') === 0) {
            // Turn off error reporting for notices during our AJAX calls
            error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

            // Start output buffering to catch any stray output
            if (!ob_get_level()) {
                ob_start();
            }

            // Clean up on shutdown
            add_action('shutdown', function() {
                if (ob_get_level() > 0) {
                    $output = ob_get_clean();
                    // If it's not JSON, discard it
                    if (!json_decode($output)) {
                        // Discard non-JSON output
                    }
                }
            }, 0);
        }
    }, 1);
}