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
        return $store_categories[0];
    }
    
    // If no "Store" category exists, get all top-level categories
    $top_categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'parent' => 0,
    ]);
    
    if (!empty($top_categories)) {
        return $top_categories[0];
    }
    
    return null;
}

/**
 * Get Category X (children of Store)
 */
function store_mm_get_category_x_options() {
    $store_category = store_mm_get_store_category();
    
    if (!$store_category) {
        return [];
    }
    
    $category_x_terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'parent' => $store_category->term_id,
    ]);
    
    return $category_x_terms;
}

/**
 * Helper function to get NDA text
 */
function store_mm_get_nda_text() {
    $nda_file = STORE_MM_PLUGIN_DIR . 'config/nda-text.txt';
    
    if (file_exists($nda_file)) {
        return file_get_contents($nda_file);
    }
    
    return "Design Submission & Preliminary Disclosure Agreement

Before uploading or sharing any design, the Designer acknowledges and agrees to the following:

By submitting my design(s) through this platform, I confirm that I am the legitimate creator and/or rights holder of the submitted content.

I understand that this submission constitutes a preliminary disclosure of information for the sole purpose of evaluation, validation, manufacturing feasibility, and potential commercial collaboration through the website.

All intellectual property rights remain the exclusive property of the Designer, unless a separate written agreement is signed between the Designer and the Platform.

The Platform agrees to treat the submitted designs as confidential and not to reproduce, distribute, modify, or commercially exploit the designs outside the scope of the platform without the Designer's explicit consent.

This submission does not constitute a transfer, sale, or assignment of ownership rights.

By proceeding, I confirm that I have read, understood, and accepted these terms.";
}

/**
 * Check if user has Store MM role
 */
function store_mm_user_has_role($user, $role) {
    if (function_exists('um_user')) {
        return um_user('role') === $role;
    }
    
    $allowed_roles = ['administrator'];
    
    switch ($role) {
        case 'store_mm_moderator':
            $allowed_roles = ['administrator', 'editor', 'shop_manager'];
            break;
        case 'store_mm_designer':
            $allowed_roles = ['administrator', 'editor', 'shop_manager', 'author', 'contributor'];
            break;
    }
    
    return array_intersect($allowed_roles, (array) $user->roles);
}

/**
 * Check if user can moderate
 */
function store_mm_user_can_moderate($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    
    if (in_array('administrator', (array) $user->roles)) {
        return true;
    }
    
    if (function_exists('um_user')) {
        $role = um_user('role');
        return in_array($role, ['administrator', 'store_mm_moderator', 'moderator']);
    }
    
    $moderator_roles = ['administrator', 'editor', 'shop_manager'];
    return array_intersect($moderator_roles, (array) $user->roles);
}

/**
 * Check if user can submit designs
 */
function store_mm_user_can_submit($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    
    if (in_array('administrator', (array) $user->roles)) {
        return true;
    }
    
    if (function_exists('um_user')) {
        $role = um_user('role');
        return in_array($role, ['administrator', 'store_mm_moderator', 'store_mm_designer', 'moderator', 'designer']);
    }
    
    $designer_roles = ['administrator', 'editor', 'shop_manager', 'author', 'contributor'];
    return array_intersect($designer_roles, (array) $user->roles);
}

// ==================== ADMIN DASHBOARD ====================

add_action('admin_menu', 'store_mm_admin_menu');
function store_mm_admin_menu() {
    if (!store_mm_user_can_moderate()) {
        return;
    }
    
    $menu_icon = 'dashicons-products';
    
    add_menu_page(
        __('Store MM Dashboard', 'store-mm'),
        __('Store MM', 'store-mm'),
        'read',
        'store-mm-dashboard',
        'store_mm_render_dashboard',
        $menu_icon,
        30
    );
    
    add_submenu_page(
        'store-mm-dashboard',
        __('Store MM Settings', 'store-mm'),
        __('Settings', 'store-mm'),
        'manage_options',
        'store-mm-settings',
        'store_mm_render_settings_page'
    );
}

function store_mm_render_dashboard() {
    if (!store_mm_user_can_moderate()) {
        wp_die(__('You do not have permission to access this page.', 'store-mm'));
    }
    
    store_mm_enqueue_admin_assets();
    
    ?>
    <div class="wrap store-mm-admin-wrap">
        <h1 class="store-mm-page-title">
            <span class="dashicons dashicons-products"></span>
            <?php _e('Store MM Dashboard', 'store-mm'); ?>
        </h1>
        
        <div class="store-mm-stats-row">
            <div class="store-mm-stat-box">
                <div class="store-mm-stat-number" id="stat-submitted">0</div>
                <div class="store-mm-stat-label">Submitted</div>
            </div>
            <div class="store-mm-stat-box">
                <div class="store-mm-stat-number" id="stat-changes-requested">0</div>
                <div class="store-mm-stat-label">Changes Requested</div>
            </div>
            <div class="store-mm-stat-box">
                <div class="store-mm-stat-number" id="stat-prototyping">0</div>
                <div class="store-mm-stat-label">Prototyping</div>
            </div>
            <div class="store-mm-stat-box">
                <div class="store-mm-stat-number" id="stat-approved">0</div>
                <div class="store-mm-stat-label">Approved</div>
            </div>
            <div class="store-mm-stat-box">
                <div class="store-mm-stat-number" id="stat-rejected">0</div>
                <div class="store-mm-stat-label">Rejected</div>
            </div>
        </div>
        
        <div class="store-mm-filters">
            <select id="store-mm-filter-state" class="store-mm-filter-select">
                <option value="all">All States</option>
                <option value="<?php echo STORE_MM_STATE_SUBMITTED; ?>">Submitted</option>
                <option value="<?php echo STORE_MM_STATE_CHANGES_REQUESTED; ?>">Changes Requested</option>
                <option value="<?php echo STORE_MM_STATE_PROTOTYPING; ?>">Prototyping</option>
                <option value="<?php echo STORE_MM_STATE_APPROVED; ?>">Approved</option>
                <option value="<?php echo STORE_MM_STATE_REJECTED; ?>">Rejected</option>
            </select>
            
            <select id="store-mm-filter-category" class="store-mm-filter-select">
                <option value="all">All Categories</option>
            </select>
            
            <input type="text" id="store-mm-search" class="store-mm-search-input" placeholder="Search designs...">
            
            <button id="store-mm-apply-filters" class="button button-primary">Apply Filters</button>
            <button id="store-mm-reset-filters" class="button button-secondary">Reset</button>
        </div>
        
        <div class="store-mm-table-container">
            <table class="wp-list-table widefat fixed striped store-mm-submissions-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="store-mm-select-all"></th>
                        <th>ID</th>
                        <th>Design Title</th>
                        <th>Designer</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Royalty %</th>
                        <th>Submitted</th>
                        <th>State</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="store-mm-submissions-body">
                    <tr>
                        <td colspan="10" class="store-mm-loading-row">
                            <div class="store-mm-spinner"></div>
                            <span>Loading submissions...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="store-mm-bulk-actions">
            <select id="store-mm-bulk-action" class="store-mm-bulk-select">
                <option value="">Bulk Actions</option>
                <option value="request_changes">Request Changes</option>
                <option value="move_to_prototyping">Move to Prototyping</option>
                <option value="approve">Approve</option>
                <option value="reject">Reject</option>
            </select>
            <button id="store-mm-apply-bulk-action" class="button button-primary" disabled>Apply</button>
        </div>
        
        <div class="store-mm-pagination">
            <span class="store-mm-pagination-info" id="store-mm-pagination-info">Showing 0 of 0</span>
            <button id="store-mm-prev-page" class="button button-secondary" disabled>Previous</button>
            <button id="store-mm-next-page" class="button button-secondary" disabled>Next</button>
        </div>
    </div>
    
    <div id="store-mm-action-modal" class="store-mm-modal" style="display: none;">
        <div class="store-mm-modal-content">
            <div class="store-mm-modal-header">
                <h3 id="store-mm-modal-title">Action Required</h3>
                <button type="button" class="store-mm-modal-close">&times;</button>
            </div>
            <div class="store-mm-modal-body">
                <div id="store-mm-modal-message"></div>
                <div id="store-mm-action-notes" style="display: none;">
                    <label for="store-mm-notes-textarea">Add notes (optional):</label>
                    <textarea id="store-mm-notes-textarea" rows="4" class="store-mm-textarea"></textarea>
                </div>
                <div id="store-mm-action-reason" style="display: none;">
                    <label for="store-mm-reason-select">Reason for rejection:</label>
                    <select id="store-mm-reason-select" class="store-mm-select">
                        <option value="">Select a reason</option>
                        <option value="design_quality">Design Quality Issues</option>
                        <option value="manufacturing">Not Manufacturable</option>
                        <option value="market_fit">Poor Market Fit</option>
                        <option value="ip_concerns">IP Concerns</option>
                        <option value="other">Other</option>
                    </select>
                    <div id="store-mm-other-reason" style="display: none;">
                        <label for="store-mm-custom-reason">Please specify:</label>
                        <input type="text" id="store-mm-custom-reason" class="store-mm-input">
                    </div>
                </div>
            </div>
            <div class="store-mm-modal-footer">
                <button type="button" class="button button-secondary store-mm-modal-cancel">Cancel</button>
                <button type="button" class="button button-primary" id="store-mm-confirm-action">Confirm</button>
            </div>
        </div>
    </div>
    <?php
}

function store_mm_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'store-mm'));
    }
    
    ?>
    <div class="wrap">
        <h1><?php _e('Store MM Settings', 'store-mm'); ?></h1>
        <div class="notice notice-warning">
            <p><?php _e('Settings page will be implemented in Group F.', 'store-mm'); ?></p>
        </div>
    </div>
    <?php
}

function store_mm_enqueue_admin_assets() {
    wp_enqueue_style(
        'store-mm-admin',
        STORE_MM_PLUGIN_URL . 'assets/css/admin.css',
        [],
        STORE_MM_VERSION
    );
    
    wp_enqueue_script(
        'store-mm-admin',
        STORE_MM_PLUGIN_URL . 'assets/js/admin.js',
        ['jquery', 'jquery-ui-dialog'],
        STORE_MM_VERSION,
        true
    );
    
    $user = wp_get_current_user();
    $can_approve = in_array('administrator', (array) $user->roles);
    
    wp_localize_script('store-mm-admin', 'store_mm_admin_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('store_mm_admin_nonce'),
        'states' => [
            'submitted' => STORE_MM_STATE_SUBMITTED,
            'changes_requested' => STORE_MM_STATE_CHANGES_REQUESTED,
            'prototyping' => STORE_MM_STATE_PROTOTYPING,
            'approved' => STORE_MM_STATE_APPROVED,
            'rejected' => STORE_MM_STATE_REJECTED,
        ],
        'user_can' => [
            'approve' => $can_approve ? 'yes' : 'no',
            'reject' => store_mm_user_can_moderate($user) ? 'yes' : 'no',
        ],
        'strings' => [
            'confirm_approve' => __('Are you sure you want to approve this design?', 'store-mm'),
            'confirm_reject' => __('Are you sure you want to reject this design?', 'store-mm'),
            'confirm_request_changes' => __('Request changes for this design?', 'store-mm'),
            'confirm_prototyping' => __('Move this design to prototyping?', 'store-mm'),
            'bulk_confirm' => __('Apply this action to selected items?', 'store-mm'),
            'loading' => __('Loading...', 'store-mm'),
            'no_items' => __('No designs found.', 'store-mm'),
        ]
    ]);
}

add_action('wp_ajax_store_mm_get_submissions', 'store_mm_ajax_get_submissions');
add_action('wp_ajax_store_mm_update_state', 'store_mm_ajax_update_state');
add_action('wp_ajax_store_mm_get_categories', 'store_mm_ajax_get_categories');

function store_mm_ajax_get_submissions() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $user = wp_get_current_user();
    if (!store_mm_user_can_moderate($user)) {
        store_mm_send_json_response('Insufficient permissions.', false);
    }
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    $state = isset($_POST['state']) && $_POST['state'] !== 'all' ? sanitize_text_field($_POST['state']) : '';
    $category = isset($_POST['category']) && $_POST['category'] !== 'all' ? intval($_POST['category']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    $args = [
        'post_type' => 'product',
        'post_status' => ['draft', 'publish', 'pending'],
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [
            [
                'key' => '_store_mm_workflow_state',
                'compare' => 'EXISTS',
            ]
        ],
    ];
    
    if ($state) {
        $args['meta_query'][] = [
            'key' => '_store_mm_workflow_state',
            'value' => $state,
            'compare' => '=',
        ];
    }
    
    if ($search) {
        $args['s'] = $search;
    }
    
    $query = new WP_Query($args);
    
    $submissions = [];
    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        
        $designer_id = get_post_meta($post->ID, '_store_mm_designer_id', true);
        $designer = get_user_by('id', $designer_id);
        $designer_name = $designer ? $designer->display_name : 'Unknown';
        
        $categories = wp_get_post_terms($post->ID, 'product_cat');
        $category_names = [];
        foreach ($categories as $cat) {
            if ($cat->slug !== 'store') {
                $category_names[] = $cat->name;
            }
        }
        
        $submissions[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'workflow_state' => get_post_meta($post->ID, '_store_mm_workflow_state', true) ?: STORE_MM_STATE_DRAFT,
            'designer_id' => $designer_id,
            'designer_name' => $designer_name,
            'categories' => implode(' → ', $category_names),
            'price' => get_post_meta($post->ID, '_store_mm_estimated_price', true),
            'royalty' => get_post_meta($post->ID, '_store_mm_royalty_percentage', true),
            'submission_date' => get_post_meta($post->ID, '_store_mm_submission_date', true),
            'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
            'view_url' => get_permalink($post->ID),
        ];
    }
    
    $stats = store_mm_get_submission_stats();
    
    store_mm_send_json_response([
        'submissions' => $submissions,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $query->max_num_pages,
            'total_items' => $query->found_posts,
        ],
        'stats' => $stats,
    ]);
}

function store_mm_get_submission_stats() {
    global $wpdb;
    
    $stats = [
        STORE_MM_STATE_SUBMITTED => 0,
        STORE_MM_STATE_CHANGES_REQUESTED => 0,
        STORE_MM_STATE_PROTOTYPING => 0,
        STORE_MM_STATE_APPROVED => 0,
        STORE_MM_STATE_REJECTED => 0,
    ];
    
    foreach ($stats as $state => $count) {
        $query = $wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_store_mm_workflow_state'
            AND pm.meta_value = %s
            AND p.post_type = 'product'
            AND p.post_status IN ('draft', 'publish', 'pending')
        ", $state);
        
        $stats[$state] = (int) $wpdb->get_var($query);
    }
    
    return $stats;
}

function store_mm_ajax_get_categories() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $store_category = store_mm_get_store_category();
    $categories = [];
    
    if ($store_category) {
        $category_x_terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => $store_category->term_id,
        ]);
        
        foreach ($category_x_terms as $cat) {
            $categories[] = [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'count' => $cat->count,
            ];
        }
    }
    
    store_mm_send_json_response($categories);
}

function store_mm_ajax_update_state() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $user = wp_get_current_user();
    if (!store_mm_user_can_moderate($user)) {
        store_mm_send_json_response('Insufficient permissions.', false);
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
    
    if (!$product_id || !$action) {
        store_mm_send_json_response('Missing parameters.', false);
    }
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    if ($action === 'approve' && !in_array('administrator', (array) $user->roles)) {
        store_mm_send_json_response('Only administrators can approve designs.', false);
    }
    
    $state_map = [
        'request_changes' => STORE_MM_STATE_CHANGES_REQUESTED,
        'move_to_prototyping' => STORE_MM_STATE_PROTOTYPING,
        'approve' => STORE_MM_STATE_APPROVED,
        'reject' => STORE_MM_STATE_REJECTED,
    ];
    
    if (!isset($state_map[$action])) {
        store_mm_send_json_response('Invalid action.', false);
    }
    
    $new_state = $state_map[$action];
    
    if (!store_mm_validate_state_transition($current_state, $new_state, $user)) {
        store_mm_send_json_response('Invalid state transition.', false);
    }
    
    update_post_meta($product_id, '_store_mm_workflow_state', $new_state);
    
    $log_entry = [
        'date' => current_time('mysql'),
        'action' => 'state_changed',
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'from_state' => $current_state,
        'to_state' => $new_state,
        'notes' => $notes,
        'reason' => $reason,
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) {
        $existing_logs = [];
    }
    $existing_logs[] = $log_entry;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'store_mm_workflow_log';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $wpdb->insert(
            $table_name,
            [
                'product_id' => $product_id,
                'user_id' => $user->ID,
                'from_state' => $current_state,
                'to_state' => $new_state,
                'action' => $action,
                'notes' => $notes,
                'metadata' => json_encode(['reason' => $reason]),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
    
    update_post_meta($product_id, '_store_mm_last_action', [
        'type' => $action,
        'by_user_id' => $user->ID,
        'timestamp' => current_time('mysql'),
        'email_should_send' => true,
    ]);
    
    store_mm_send_json_response([
        'message' => 'State updated successfully.',
        'new_state' => $new_state,
        'product_id' => $product_id,
    ]);
}

add_action('admin_init', 'store_mm_check_dependencies');
function store_mm_check_dependencies() {
    if (is_admin() && current_user_can('activate_plugins')) {
        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            deactivate_plugins(STORE_MM_PLUGIN_BASENAME);
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Store MM requires WooCommerce to be installed and activated.</p></div>';
            });
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
        }
    }
}

add_action('wp_enqueue_scripts', 'store_mm_enqueue_locking_assets');
function store_mm_enqueue_locking_assets() {
    if (is_product()) {
        wp_enqueue_style(
            'store-mm-locking',
            STORE_MM_PLUGIN_URL . 'assets/css/locking.css',
            [],
            STORE_MM_VERSION
        );
        
        wp_enqueue_script(
            'store-mm-locking',
            STORE_MM_PLUGIN_URL . 'assets/js/locking.js',
            ['jquery'],
            STORE_MM_VERSION,
            true
        );
        
        wp_localize_script('store-mm-locking', 'store_mm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('store_mm_ajax_nonce')
        ]);
    }
}

add_action('admin_enqueue_scripts', 'store_mm_enqueue_admin_locking_assets');
function store_mm_enqueue_admin_locking_assets($hook) {
    if ($hook === 'post.php' && get_post_type() === 'product') {
        wp_enqueue_script(
            'store-mm-admin-locking',
            STORE_MM_PLUGIN_URL . 'assets/js/locking.js',
            ['jquery'],
            STORE_MM_VERSION,
            true
        );
        
        wp_localize_script('store-mm-admin-locking', 'store_mm_admin_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('store_mm_admin_nonce')
        ]);
    }
}

add_action('wp_enqueue_scripts', 'store_mm_enqueue_dashboard_assets');
function store_mm_enqueue_dashboard_assets() {
    if (is_page() && has_shortcode(get_post()->post_content, 'store_mm_designer_dashboard')) {
        wp_enqueue_style(
            'store-mm-dashboard',
            STORE_MM_PLUGIN_URL . 'assets/css/dashboard.css',
            [],
            STORE_MM_VERSION
        );
    }
}

register_activation_hook(__FILE__, 'store_mm_activate');
function store_mm_activate() {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        wp_die('Store MM requires WooCommerce to be installed and activated.');
    }
    
    $config_dir = STORE_MM_PLUGIN_DIR . 'config';
    if (!file_exists($config_dir)) {
        wp_mkdir_p($config_dir);
    }
    
    $nda_file = $config_dir . '/nda-text.txt';
    if (!file_exists($nda_file)) {
        $nda_content = "Design Submission & Preliminary Disclosure Agreement\n\nBefore uploading or sharing any design, the Designer acknowledges and agrees to the following:\n\nBy submitting my design(s) through this platform, I confirm that I am the legitimate creator and/or rights holder of the submitted content.\n\nI understand that this submission constitutes a preliminary disclosure of information for the sole purpose of evaluation, validation, manufacturing feasibility, and potential commercial collaboration through the website.\n\nAll intellectual property rights remain the exclusive property of the Designer, unless a separate written agreement is signed between the Designer and the Platform.\n\nThe Platform agrees to treat the submitted designs as confidential and not to reproduce, distribute, modify, or commercially exploit the designs outside the scope of the platform without the Designer's explicit consent.\n\nThis submission does not constitute a transfer, sale, or assignment of ownership rights.\n\nBy proceeding, I confirm that I have read, understood, and accepted these terms.";
        
        file_put_contents($nda_file, $nda_content);
    }
    
    $upload_dir = wp_upload_dir();
    $store_mm_dir = $upload_dir['basedir'] . '/store-mm-uploads';
    if (!file_exists($store_mm_dir)) {
        wp_mkdir_p($store_mm_dir);
    }
    
    $htaccess = $store_mm_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
    }
    
    store_mm_create_database_tables();
    
    flush_rewrite_rules();
}

function store_mm_create_database_tables() {
    global $wpdb;
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'store_mm_workflow_log';
    
    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        from_state VARCHAR(50) NOT NULL,
        to_state VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        notes TEXT,
        metadata TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_product_id (product_id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at),
        KEY idx_state_transition (from_state, to_state)
    ) $charset_collate;";
    
    dbDelta($sql);
    
    update_option('store_mm_db_version', STORE_MM_VERSION);
}

register_deactivation_hook(__FILE__, 'store_mm_deactivate');
function store_mm_deactivate() {
    flush_rewrite_rules();
}

function store_mm_validate_state_transition($current_state, $new_state, $user) {
    $valid_transitions = [
        STORE_MM_STATE_SUBMITTED => [
            STORE_MM_STATE_CHANGES_REQUESTED,
            STORE_MM_STATE_PROTOTYPING,
            STORE_MM_STATE_REJECTED
        ],
        STORE_MM_STATE_CHANGES_REQUESTED => [
            STORE_MM_STATE_SUBMITTED,
            STORE_MM_STATE_PROTOTYPING,
            STORE_MM_STATE_REJECTED
        ],
        STORE_MM_STATE_PROTOTYPING => [
            STORE_MM_STATE_APPROVED,
            STORE_MM_STATE_REJECTED
        ],
        STORE_MM_STATE_APPROVED => [
            STORE_MM_STATE_REJECTED
        ],
        STORE_MM_STATE_REJECTED => [
            STORE_MM_STATE_SUBMITTED
        ]
    ];
    
    if (in_array('administrator', (array) $user->roles)) {
        return true;
    }
    
    if (isset($valid_transitions[$current_state])) {
        return in_array($new_state, $valid_transitions[$current_state]);
    }
    
    return false;
}