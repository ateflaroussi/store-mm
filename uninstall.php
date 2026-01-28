<?php
/**
 * Store MM Uninstall Script
 * Cleans up database entries when plugin is deleted
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if we should delete all data
$delete_data = get_option('store_mm_delete_data_on_uninstall', false);

if ($delete_data) {
    global $wpdb;
    
    // Delete all Store MM metadata
    $meta_keys = [
        '_store_mm_designer_id',
        '_store_mm_designer_name',
        '_store_mm_designer_email',
        '_store_mm_estimated_price',
        '_store_mm_royalty_percentage',
        '_store_mm_royalty_option',
        '_store_mm_requested_royalty',
        '_store_mm_workflow_state',
        '_store_mm_submission_date',
        '_store_mm_nda_agreed',
        '_store_mm_design_files',
        '_store_mm_submission_log',
        '_store_mm_internal_notes',
        '_store_mm_last_action'
    ];
    
    foreach ($meta_keys as $key) {
        $wpdb->delete($wpdb->postmeta, ['meta_key' => $key]);
    }
    
    // Delete workflow log table
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}store_mm_workflow_log");
    
    // Delete options
    delete_option('store_mm_version');
    delete_option('store_mm_settings');
    delete_option('store_mm_delete_data_on_uninstall');
    delete_option('store_mm_db_version');
    
    // Delete upload directory
    $upload_dir = wp_upload_dir();
    $store_mm_dir = $upload_dir['basedir'] . '/store-mm-uploads';
    if (file_exists($store_mm_dir)) {
        // Remove all files in directory
        $files = glob($store_mm_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        // Remove directory
        rmdir($store_mm_dir);
    }
}