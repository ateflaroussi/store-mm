
<?php
/**
 * Plugin Name: Store MM
 * Plugin URI: 
 * Description: Store Manufacturing Marketplace Plugin for WooCommerce
 * Version: 1.0.6
 * Author: 
 * License: GPL v2 or later
 * Text Domain: store-mm
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('STORE_MM_VERSION', '1.0.6');
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

// Define currency
define('STORE_MM_CURRENCY', 'TND');
define('STORE_MM_CURRENCY_SYMBOL', 'TND');
define('STORE_MM_PRICE_DECIMALS', 3);

// Include core files
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/submission-form.php';
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/my-products.php';
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/store-grid.php'; // ADDED: Group D
require_once STORE_MM_PLUGIN_DIR . 'includes/shortcodes/store-single-product.php'; // ADDED: Group D

/**
 * Format price with TND currency
 */
function store_mm_format_price($price) {
    $formatted = number_format(floatval($price), STORE_MM_PRICE_DECIMALS, '.', '');
    return STORE_MM_CURRENCY_SYMBOL . ' ' . $formatted;
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
        'parent' => 0, // Top level
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
    
    // Return the first top-level category or create one if needed
    if (!empty($top_categories)) {
        return $top_categories[0];
    }
    
    // If no categories exist at all, return null
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
    
    // Get direct children of Store category
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
    
    // Default NDA text
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
 * Check if user can moderate
 */
function store_mm_user_can_moderate($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    
    // Admin can always moderate
    if (in_array('administrator', (array) $user->roles)) {
        return true;
    }
    
    // Check Ultimate Member role
    if (function_exists('um_user')) {
        $role = um_user('role');
        return in_array($role, ['administrator', 'store_mm_moderator', 'moderator']);
    }
    
    // Fallback to WordPress roles
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
    
    // Admin can always submit
    if (in_array('administrator', (array) $user->roles)) {
        return true;
    }
    
    // Check Ultimate Member role
    if (function_exists('um_user')) {
        $role = um_user('role');
        return in_array($role, ['administrator', 'store_mm_moderator', 'store_mm_designer', 'moderator', 'designer']);
    }
    
    // Fallback to WordPress roles
    $designer_roles = ['administrator', 'editor', 'shop_manager', 'author', 'contributor'];
    return array_intersect($designer_roles, (array) $user->roles);
}

// ==================== GROUP B: ADMIN/MODERATOR REVIEW DASHBOARD (READ-ONLY) ====================

// B.1: Admin menu "Store MM" - VIEW ONLY
add_action('admin_menu', 'store_mm_admin_menu');
function store_mm_admin_menu() {
    // Check user permissions - Admin or Moderator only
    if (!store_mm_user_can_moderate()) {
        return; // User doesn't have permission
    }
    
    $menu_icon = 'dashicons-products'; // WooCommerce compatible icon
    
    add_menu_page(
        __('Store MM Dashboard', 'store-mm'),
        __('Store MM', 'store-mm'),
        'read', // Basic read capability
        'store-mm-dashboard',
        'store_mm_render_dashboard_readonly',
        $menu_icon,
        30 // Position after WooCommerce (which is at 55)
    );
    
    // Add submenu for settings (will be used in Group F)
    add_submenu_page(
        'store-mm-dashboard',
        __('Store MM Settings', 'store-mm'),
        __('Settings', 'store-mm'),
        'manage_options', // Admin only
        'store-mm-settings',
        'store_mm_render_settings_page'
    );
}

// B.1: Read-only Dashboard page renderer
function store_mm_render_dashboard_readonly() {
    // Double-check user permissions
    if (!store_mm_user_can_moderate()) {
        wp_die(__('You do not have permission to access this page.', 'store-mm'));
    }
    
    // Enqueue admin styles and scripts
    store_mm_enqueue_admin_assets_readonly();
    
    ?>
    <div class="wrap store-mm-admin-wrap">
        <h1 class="store-mm-page-title">
            <span class="dashicons dashicons-products"></span>
            <?php _e('Store MM Dashboard (View Only)', 'store-mm'); ?>
            <span class="store-mm-view-only-badge">View Only - Use Frontend for Actions</span>
        </h1>
        
        <div class="notice notice-info">
            <p><?php _e('<strong>Important:</strong> This dashboard is for monitoring only. All workflow actions must be performed from the frontend.', 'store-mm'); ?></p>
        </div>
        
        <!-- Dashboard Stats -->
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
        
        <!-- Filters -->
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
                <!-- Will be populated via AJAX -->
            </select>
            
            <input type="text" id="store-mm-search" class="store-mm-search-input" placeholder="Search designs...">
            
            <button id="store-mm-apply-filters" class="button button-primary">Apply Filters</button>
            <button id="store-mm-reset-filters" class="button button-secondary">Reset</button>
        </div>
        
        <!-- Submissions Table - READ ONLY -->
        <div class="store-mm-table-container">
            <table class="wp-list-table widefat fixed striped store-mm-submissions-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Design Title</th>
                        <th>Designer</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Royalty %</th>
                        <th>Submitted</th>
                        <th>State</th>
                        <th>View</th>
                    </tr>
                </thead>
                <tbody id="store-mm-submissions-body">
                    <!-- Will be populated via AJAX -->
                    <tr>
                        <td colspan="9" class="store-mm-loading-row">
                            <div class="store-mm-spinner"></div>
                            <span>Loading submissions...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="store-mm-pagination">
            <span class="store-mm-pagination-info" id="store-mm-pagination-info">Showing 0 of 0</span>
            <button id="store-mm-prev-page" class="button button-secondary" disabled>Previous</button>
            <button id="store-mm-next-page" class="button button-secondary" disabled>Next</button>
        </div>
        
        <div class="store-mm-admin-notice">
            <h3><span class="dashicons dashicons-info"></span> Frontend Management Required</h3>
            <p>All workflow actions must be performed from the frontend:</p>
            <ul>
                <li><strong>Designers:</strong> Use <code>[store_mm_my_products_dev]</code> and <code>[store_mm_my_products_live]</code> shortcodes</li>
                <li><strong>Moderators:</strong> Use frontend review panel for state changes</li>
                <li><strong>Admins:</strong> Final approvals from frontend only</li>
            </ul>
        </div>
    </div>
    <?php
}

// B.1: Settings page renderer (placeholder for Group F)
function store_mm_render_settings_page() {
    // Admin only check
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

// B.1: Enqueue read-only admin assets
function store_mm_enqueue_admin_assets_readonly() {
    wp_enqueue_style(
        'store-mm-admin',
        STORE_MM_PLUGIN_URL . 'assets/css/admin.css',
        [],
        STORE_MM_VERSION
    );
    
    wp_enqueue_script(
        'store-mm-admin',
        STORE_MM_PLUGIN_URL . 'assets/js/admin.js',
        ['jquery'],
        STORE_MM_VERSION,
        true
    );
    
    // Localize script - NO action capabilities
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
        'currency' => STORE_MM_CURRENCY_SYMBOL,
        'decimals' => STORE_MM_PRICE_DECIMALS,
        'readonly' => true, // Explicitly mark as read-only
        'strings' => [
            'loading' => __('Loading...', 'store-mm'),
            'no_items' => __('No designs found.', 'store-mm'),
        ]
    ]);
}

// B.2: Register AJAX handlers for read-only dashboard (admin only)
add_action('wp_ajax_store_mm_get_submissions', 'store_mm_ajax_get_submissions');
add_action('wp_ajax_store_mm_get_categories', 'store_mm_ajax_get_categories');

function store_mm_ajax_get_submissions() {
    // Check nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_admin_nonce')) {
        wp_send_json_error('Security check failed.');
    }
    
    $user = wp_get_current_user();
    if (!store_mm_user_can_moderate($user)) {
        wp_send_json_error('Insufficient permissions.');
    }
    
    // Get filter parameters
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    $state = isset($_POST['state']) && $_POST['state'] !== 'all' ? sanitize_text_field($_POST['state']) : '';
    $category = isset($_POST['category']) && $_POST['category'] !== 'all' ? intval($_POST['category']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    // Build query args
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
    
    // Add state filter
    if ($state) {
        $args['meta_query'][] = [
            'key' => '_store_mm_workflow_state',
            'value' => $state,
            'compare' => '=',
        ];
    }
    
    // Add search filter
    if ($search) {
        $args['s'] = $search;
    }
    
    // Execute query
    $query = new WP_Query($args);
    
    // Prepare response data
    $submissions = [];
    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        
        // Get Store MM metadata
        $designer_id = get_post_meta($post->ID, '_store_mm_designer_id', true);
        $designer = get_user_by('id', $designer_id);
        $designer_name = $designer ? $designer->display_name : 'Unknown';
        
        // Get category info
        $categories = wp_get_post_terms($post->ID, 'product_cat');
        $category_names = [];
        foreach ($categories as $cat) {
            if ($cat->slug !== 'store') { // Skip "Store" category
                $category_names[] = $cat->name;
            }
        }
        
        // Format price with TND
        $raw_price = get_post_meta($post->ID, '_store_mm_estimated_price', true);
        $formatted_price = $raw_price ? store_mm_format_price($raw_price) : store_mm_format_price(0);
        
        $submissions[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'workflow_state' => get_post_meta($post->ID, '_store_mm_workflow_state', true) ?: STORE_MM_STATE_DRAFT,
            'designer_id' => $designer_id,
            'designer_name' => $designer_name,
            'categories' => implode(' → ', $category_names),
            'price' => $raw_price,
            'price_display' => $formatted_price,
            'royalty' => get_post_meta($post->ID, '_store_mm_royalty_percentage', true),
            'submission_date' => get_post_meta($post->ID, '_store_mm_submission_date', true),
            'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
            'view_url' => get_permalink($post->ID),
        ];
    }
    
    // Get stats
    $stats = store_mm_get_submission_stats();
    
    wp_send_json_success([
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
        wp_send_json_error('Security check failed.');
    }
    
    // Get all Category X items (children of Store)
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
    
    wp_send_json_success($categories);
}

// ==================== DESIGNER PROFILE FUNCTIONS ====================

/**
 * Get designer profile data from wp_designer_profiles table
 */
function store_mm_get_designer_profile($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'designer_profiles';
    
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d",
        $user_id
    ));
    
    return $profile;
}

/**
 * Update designer portfolio counts in the designer_profiles table
 */
function store_mm_update_designer_portfolio_counts($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'designer_profiles';
    
    // Count all designer products (portfolio_designs)
    $total_products_args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];
    $total_products_query = new WP_Query($total_products_args);
    $portfolio_designs = $total_products_query->found_posts;
    
    // Count approved products in store (portfolio_products)
    $approved_products_args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => '_store_mm_workflow_state',
                'value' => STORE_MM_STATE_APPROVED,
            ]
        ],
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];
    $approved_products_query = new WP_Query($approved_products_args);
    $portfolio_products = $approved_products_query->found_posts;
    
    // Check if profile exists
    $profile_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
        $user_id
    ));
    
    if ($profile_exists) {
        // Update existing profile
        $wpdb->update(
            $table_name,
            [
                'portfolio_designs' => json_encode(array_fill(0, $portfolio_designs, null)),
                'portfolio_products' => json_encode(array_fill(0, $portfolio_products, null)),
                'last_updated' => current_time('mysql'),
            ],
            ['user_id' => $user_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    } else {
        // Create new profile with basic info
        $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'portfolio_designs' => json_encode(array_fill(0, $portfolio_designs, null)),
                'portfolio_products' => json_encode(array_fill(0, $portfolio_products, null)),
                'created_at' => current_time('mysql'),
                'last_updated' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }
    
    return [
        'portfolio_designs' => $portfolio_designs,
        'portfolio_products' => $portfolio_products,
    ];
}

/**
 * Get designer portfolio counts (returns actual counts, not JSON arrays)
 */
function store_mm_get_designer_portfolio_counts($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'designer_profiles';
    
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT portfolio_designs, portfolio_products FROM $table_name WHERE user_id = %d",
        $user_id
    ));
    
    if ($profile) {
        // Decode JSON arrays and count elements
        $portfolio_designs = json_decode($profile->portfolio_designs, true);
        $portfolio_products = json_decode($profile->portfolio_products, true);
        
        return [
            'portfolio_designs' => is_array($portfolio_designs) ? count($portfolio_designs) : 0,
            'portfolio_products' => is_array($portfolio_products) ? count($portfolio_products) : 0,
        ];
    }
    
    // If no profile exists, create it and return counts
    return store_mm_update_designer_portfolio_counts($user_id);
}

// Hook to update designer portfolio counts when product is saved
add_action('save_post_product', 'store_mm_sync_designer_portfolio_on_save', 10, 3);
function store_mm_sync_designer_portfolio_on_save($post_id, $post, $update) {
    // Get designer ID
    $designer_id = get_post_meta($post_id, '_store_mm_designer_id', true);
    
    if ($designer_id) {
        // Update designer portfolio counts
        store_mm_update_designer_portfolio_counts($designer_id);
    }
}

// Hook to update counts when workflow state changes
add_action('updated_post_meta', 'store_mm_sync_designer_portfolio_on_meta_update', 10, 4);
function store_mm_sync_designer_portfolio_on_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
    // Only trigger on workflow state changes
    if ($meta_key === '_store_mm_workflow_state') {
        $designer_id = get_post_meta($post_id, '_store_mm_designer_id', true);
        
        if ($designer_id) {
            store_mm_update_designer_portfolio_counts($designer_id);
        }
    }
}

// Check WooCommerce is active
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

// Activation hook
register_activation_hook(__FILE__, 'store_mm_activate');
function store_mm_activate() {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        wp_die('Store MM requires WooCommerce to be installed and activated.');
    }
    
    // Create config directory and NDA file if they don't exist
    $config_dir = STORE_MM_PLUGIN_DIR . 'config';
    if (!file_exists($config_dir)) {
        wp_mkdir_p($config_dir);
    }
    
    $nda_file = $config_dir . '/nda-text.txt';
    if (!file_exists($nda_file)) {
        $nda_content = "Design Submission & Preliminary Disclosure Agreement

Before uploading or sharing any design, the Designer acknowledges and agrees to the following:

By submitting my design(s) through this platform, I confirm that I am the legitimate creator and/or rights holder of the submitted content.

I understand that this submission constitutes a preliminary disclosure of information for the sole purpose of evaluation, validation, manufacturing feasibility, and potential commercial collaboration through the website.

All intellectual property rights remain the exclusive property of the Designer, unless a separate written agreement is signed between the Designer and the Platform.

The Platform agrees to treat the submitted designs as confidential and not to reproduce, distribute, modify, or commercially exploit the designs outside the scope of the platform without the Designer's explicit consent.

This submission does not constitute a transfer, sale, or assignment of ownership rights.

By proceeding, I confirm that I have read, understood, and accepted these terms.";
        
        file_put_contents($nda_file, $nda_content);
    }
    
    // Create upload directories
    $upload_dir = wp_upload_dir();
    $store_mm_dir = $upload_dir['basedir'] . '/store-mm-uploads';
    if (!file_exists($store_mm_dir)) {
        wp_mkdir_p($store_mm_dir);
    }
    
    // Create .htaccess to protect design files
    $htaccess = $store_mm_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
    }
    
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'store_mm_deactivate');
function store_mm_deactivate() {
    flush_rewrite_rules();
}