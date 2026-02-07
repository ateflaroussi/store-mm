<?php
/**
 * Store MM Frontend Product Management - Optimized
 * Shortcodes: [store_mm_my_products_dev] and [store_mm_my_products_live]
 */

if (!defined('ABSPATH')) exit;

// Add shortcodes
add_shortcode('store_mm_my_products_dev', 'store_mm_render_my_products_dev');
add_shortcode('store_mm_my_products_live', 'store_mm_render_my_products_live');

// AJAX handlers
add_action('wp_ajax_store_mm_change_state', 'store_mm_ajax_change_state');
add_action('wp_ajax_store_mm_get_product_details', 'store_mm_ajax_get_product_details');
add_action('wp_ajax_store_mm_update_product', 'store_mm_ajax_update_product');
add_action('wp_ajax_store_mm_get_dev_products', 'store_mm_ajax_get_dev_products');
add_action('wp_ajax_store_mm_get_live_products', 'store_mm_ajax_get_live_products');
add_action('wp_ajax_store_mm_get_product_files', 'store_mm_ajax_get_product_files');
add_action('wp_ajax_store_mm_set_final_price', 'store_mm_ajax_set_final_price');
add_action('wp_ajax_store_mm_add_note', 'store_mm_ajax_add_note');
add_action('wp_ajax_store_mm_archive_product', 'store_mm_ajax_archive_product');
add_action('wp_ajax_store_mm_delete_product', 'store_mm_ajax_delete_product');

// Helper function for product data
function store_mm_get_product_common_data($post_id, $user) {
    $product = wc_get_product($post_id);
    if (!$product) return null;
    
    $designer_id = get_post_meta($post_id, '_store_mm_designer_id', true);
    $designer = get_user_by('id', $designer_id);
    
    // Get categories (skip "store")
    $categories = wp_get_post_terms($post_id, 'product_cat');
    $category_names = [];
    foreach ($categories as $cat) {
        if ($cat->slug !== 'store') $category_names[] = $cat->name;
    }
    
    $current_state = get_post_meta($post_id, '_store_mm_workflow_state', true);
    $price_info = store_mm_get_product_price_display($post_id);
    
    return [
        'id' => $post_id,
        'title' => $product->get_title(),
        'designer_id' => $designer_id,
        'designer_name' => $designer ? $designer->display_name : 'Unknown',
        'categories' => implode(' → ', $category_names),
        'workflow_state' => $current_state,
        'price' => $price_info['price'],
        'price_display' => $price_info['display'],
        'price_type' => $price_info['type'],
        'proposed_price' => $price_info['proposed_price'],
        'royalty' => get_post_meta($post_id, '_store_mm_royalty_percentage', true),
        'has_design_files' => !empty(get_post_meta($post_id, '_store_mm_design_files', true)),
    ];
}

function store_mm_get_product_price_display($product_id) {
    $final_price = get_post_meta($product_id, '_store_mm_final_price', true);
    $proposed_price = get_post_meta($product_id, '_store_mm_proposed_price', true);
    
    if ($final_price) {
        return [
            'price' => $final_price,
            'display' => store_mm_format_price($final_price),
            'type' => 'final', 
            'proposed_price' => $proposed_price
        ];
    } else {
        return [
            'price' => $proposed_price ?: 0,
            'display' => $proposed_price ? store_mm_format_price($proposed_price) : store_mm_format_price(0),
            'type' => 'proposed', 
            'proposed_price' => $proposed_price ?: 0
        ];
    }
}

function store_mm_ajax_add_note() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $is_admin = in_array('administrator', (array) $user->roles);
    $is_moderator = store_mm_user_can_moderate($user);
    
    if (!$is_admin && !$is_moderator) wp_send_json_error('Insufficient permissions.');
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    
    if (!$product_id || empty($notes)) wp_send_json_error('Missing parameters.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if ($current_state !== STORE_MM_STATE_PROTOTYPING) {
        wp_send_json_error('Notes can only be added to products in prototyping state.');
    }
    
    // Log note
    $log_entry = [
        'date' => current_time('mysql'),
        'action' => 'internal_note_added',
        'user_id' => $user_id,
        'user_name' => $user->display_name,
        'notes' => $notes,
        'internal' => true,
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) $existing_logs = [];
    $existing_logs[] = $log_entry;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    wp_send_json_success(['message' => 'Note added successfully.', 'product_id' => $product_id]);
}

function store_mm_ajax_archive_product() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in() || !in_array('administrator', (array) wp_get_current_user()->roles)) {
        wp_send_json_error('Only administrators can archive products.');
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) wp_send_json_error('Missing product ID.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if ($current_state !== STORE_MM_STATE_REJECTED) {
        wp_send_json_error('Only rejected products can be archived.');
    }
    
    // Archive by marking with custom meta
    update_post_meta($product_id, '_store_mm_archived', true);
    update_post_meta($product_id, '_store_mm_archived_date', current_time('mysql'));
    update_post_meta($product_id, '_store_mm_archived_by', get_current_user_id());
    
    wp_send_json_success(['message' => 'Product archived successfully.', 'product_id' => $product_id]);
}

function store_mm_ajax_delete_product() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in() || !in_array('administrator', (array) wp_get_current_user()->roles)) {
        wp_send_json_error('Only administrators can delete products.');
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) wp_send_json_error('Missing product ID.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if ($current_state !== STORE_MM_STATE_REJECTED) {
        wp_send_json_error('Only rejected products can be deleted.');
    }
    
    // Check if archived first
    $is_archived = get_post_meta($product_id, '_store_mm_archived', true);
    if (!$is_archived) {
        wp_send_json_error('Product must be archived before deletion.');
    }
    
    // Move to trash instead of permanent delete for safety
    wp_trash_post($product_id);
    
    wp_send_json_success(['message' => 'Product moved to trash successfully.', 'product_id' => $product_id]);
}

function store_mm_ajax_set_final_price() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) {
        wp_send_json_error('Security check failed.');
    }
    
    if (!is_user_logged_in() || !in_array('administrator', (array) wp_get_current_user()->roles)) {
        wp_send_json_error('Only administrators can set final prices.');
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $final_price = isset($_POST['final_price']) ? floatval($_POST['final_price']) : 0;
    $final_royalty = isset($_POST['final_royalty']) ? floatval($_POST['final_royalty']) : null;
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    
    if (!$product_id || $final_price <= 0) wp_send_json_error('Invalid parameters.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    if ($current_state !== STORE_MM_STATE_SUBMITTED) {
        wp_send_json_error('Price can only be set for submitted products (before prototyping).');
    }
    
    // Store as proposed changes that need designer confirmation
    update_post_meta($product_id, '_store_mm_proposed_changes', [
        'price' => $final_price,
        'royalty' => $final_royalty,
        'proposed_by' => get_current_user_id(),
        'proposed_at' => current_time('mysql'),
        'notes' => $notes
    ]);
    
    // Change state to changes_requested so designer can confirm
    update_post_meta($product_id, '_store_mm_workflow_state', STORE_MM_STATE_CHANGES_REQUESTED);
    
    // Log action
    $user = wp_get_current_user();
    $log_entry = [
        'date' => current_time('mysql'),
        'action' => 'price_proposed_by_admin',
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'price' => $final_price,
        'royalty' => $final_royalty,
        'notes' => $notes,
        'requires_designer_confirmation' => true,
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) $existing_logs = [];
    $existing_logs[] = $log_entry;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    // Mark for notification
    update_post_meta($product_id, '_store_mm_pending_notification', [
        'type' => 'price_proposal',
        'new_price' => $final_price,
        'new_royalty' => $final_royalty,
        'set_by' => $user->ID,
        'timestamp' => current_time('mysql'),
        'requires_confirmation' => true,
    ]);
    
    wp_send_json_success([
        'message' => 'Price proposal sent to designer for confirmation.', 
        'product_id' => $product_id, 
        'final_price' => $final_price,
        'new_state' => STORE_MM_STATE_CHANGES_REQUESTED
    ]);
}

function store_mm_ajax_change_state() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) {
        wp_send_json_error('Security check failed.');
    }
    
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $is_admin = in_array('administrator', (array) $user->roles);
    $is_moderator = store_mm_user_can_moderate($user);
    $is_designer = store_mm_user_can_submit($user);
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
    
    if (!$product_id || !$action) wp_send_json_error('Missing parameters.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    
    // Check permissions and state transitions
    $allowed = false;
    $new_state = '';
    
    $transitions = [
        'request_changes' => [
            'admin' => [STORE_MM_STATE_SUBMITTED, STORE_MM_STATE_PROTOTYPING],
            'moderator' => [STORE_MM_STATE_SUBMITTED],
            'state' => STORE_MM_STATE_CHANGES_REQUESTED
        ],
        'move_to_prototyping' => [
            'admin' => true, 'moderator' => true,
            'states' => [STORE_MM_STATE_SUBMITTED],
            'state' => STORE_MM_STATE_PROTOTYPING
        ],
        'approve' => [
            'admin' => true,
            'states' => [STORE_MM_STATE_PROTOTYPING, STORE_MM_STATE_DRAFT, STORE_MM_STATE_REJECTED],
            'state' => STORE_MM_STATE_APPROVED
        ],
        'reject' => [
            'admin_states' => ['all_except_approved'],
            'moderator_states' => [STORE_MM_STATE_SUBMITTED],
            'state' => STORE_MM_STATE_REJECTED
        ],
        'submit_changes' => [
            'designer_only' => true,
            'states' => [STORE_MM_STATE_CHANGES_REQUESTED],
            'state' => STORE_MM_STATE_SUBMITTED
        ]
    ];
    
    if (isset($transitions[$action])) {
        $t = $transitions[$action];
        
        if ($action === 'request_changes') {
            if ($is_admin && in_array($current_state, $t['admin'])) {
                $allowed = true; $new_state = $t['state'];
            } elseif ($is_moderator && $current_state === STORE_MM_STATE_SUBMITTED) {
                $allowed = true; $new_state = $t['state'];
            }
        } elseif ($action === 'move_to_prototyping') {
            if (($is_admin || $is_moderator) && $current_state === STORE_MM_STATE_SUBMITTED) {
                $allowed = true; $new_state = $t['state'];
            }
        } elseif ($action === 'approve') {
            if ($is_admin && in_array($current_state, $t['states'])) {
                $allowed = true; $new_state = $t['state'];
            }
        } elseif ($action === 'reject') {
            if ($is_admin && $current_state !== STORE_MM_STATE_APPROVED) {
                $allowed = true; $new_state = $t['state'];
            } elseif ($is_moderator && $current_state === STORE_MM_STATE_SUBMITTED) {
                $allowed = true; $new_state = $t['state'];
            }
        } elseif ($action === 'submit_changes') {
            if ($is_designer && $designer_id == $user_id && $current_state === STORE_MM_STATE_CHANGES_REQUESTED) {
                $allowed = true; $new_state = $t['state'];
            }
        }
    }
    
    if (!$allowed) wp_send_json_error('You do not have permission to perform this action, or invalid state transition.');
    
    // Handle price proposal acceptance
    if ($action === 'submit_changes' && $current_state === STORE_MM_STATE_CHANGES_REQUESTED) {
        $proposed_changes = get_post_meta($product_id, '_store_mm_proposed_changes', true);
        if ($proposed_changes && isset($proposed_changes['price'])) {
            // Apply proposed price changes
            update_post_meta($product_id, '_store_mm_proposed_price', $proposed_changes['price']);
            if (isset($proposed_changes['royalty'])) {
                update_post_meta($product_id, '_store_mm_royalty_percentage', $proposed_changes['royalty']);
            }
            // Clear proposed changes
            delete_post_meta($product_id, '_store_mm_proposed_changes');
        }
    }
    
    update_post_meta($product_id, '_store_mm_workflow_state', $new_state);
    
    // Publish on approval
    if ($new_state === STORE_MM_STATE_APPROVED) {
        $final_price = get_post_meta($product_id, '_store_mm_final_price', true);
        $price = $final_price ?: get_post_meta($product_id, '_store_mm_proposed_price', true);
        
        wp_update_post(['ID' => $product_id, 'post_status' => 'publish']);
        
        if ($price) {
            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_regular_price', $price);
        }
    }
    
    // If rejecting an approved product, unpublish it
    if ($new_state === STORE_MM_STATE_REJECTED && $current_state === STORE_MM_STATE_APPROVED) {
        wp_update_post(['ID' => $product_id, 'post_status' => 'draft']);
    }
    
    // Log action
    $log_entry = [
        'date' => current_time('mysql'),
        'action' => 'state_changed_frontend',
        'user_id' => $user_id,
        'user_name' => $user->display_name,
        'from_state' => $current_state,
        'to_state' => $new_state,
        'notes' => $notes,
        'reason' => $reason,
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) $existing_logs = [];
    $existing_logs[] = $log_entry;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    update_post_meta($product_id, '_store_mm_pending_notification', [
        'type' => 'state_change',
        'new_state' => $new_state,
        'changed_by' => $user_id,
        'timestamp' => current_time('mysql'),
    ]);
    
    wp_send_json_success(['message' => 'State updated successfully.', 'new_state' => $new_state, 'product_id' => $product_id]);
}

function store_mm_ajax_get_product_details() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) wp_send_json_error('Missing product ID.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    if ($designer_id != $user->ID || $current_state !== STORE_MM_STATE_CHANGES_REQUESTED) {
        wp_send_json_error('You cannot edit this product.');
    }
    
    // Check for proposed changes from admin
    $proposed_changes = get_post_meta($product_id, '_store_mm_proposed_changes', true);
    
    $product_data = [
        'id' => $product_id,
        'title' => $product->get_title(),
        'description' => $product->get_description(),
        'current_price' => get_post_meta($product_id, '_store_mm_proposed_price', true),
        'current_royalty' => get_post_meta($product_id, '_store_mm_royalty_percentage', true),
        'current_state' => $current_state,
        'has_proposed_changes' => !empty($proposed_changes),
        'proposed_price' => $proposed_changes['price'] ?? null,
        'proposed_royalty' => $proposed_changes['royalty'] ?? null,
        'proposed_notes' => $proposed_changes['notes'] ?? null,
    ];
    
    wp_send_json_success($product_data);
}

function store_mm_ajax_update_product() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $royalty = isset($_POST['royalty']) ? floatval($_POST['royalty']) : null;
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    
    if (!$product_id || !$price) wp_send_json_error('Missing required fields.');
    
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Product not found.');
    
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    $current_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    if ($designer_id != $user->ID || $current_state !== STORE_MM_STATE_CHANGES_REQUESTED) {
        wp_send_json_error('You cannot edit this product.');
    }
    
    // Check if there are proposed changes from admin
    $proposed_changes = get_post_meta($product_id, '_store_mm_proposed_changes', true);
    $is_accepting_proposal = false;
    
    if ($proposed_changes && isset($proposed_changes['price'])) {
        // Designer is accepting admin's proposal
        $is_accepting_proposal = true;
        $price = $proposed_changes['price'];
        if (isset($proposed_changes['royalty'])) {
            $royalty = $proposed_changes['royalty'];
        }
    }
    
    update_post_meta($product_id, '_store_mm_proposed_price', $price);
    if ($royalty !== null) {
        update_post_meta($product_id, '_store_mm_royalty_percentage', $royalty);
    }
    
    // Handle file uploads
    $new_design_files = [];
    if (!empty($_FILES['edit_design_files'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $files = $_FILES['edit_design_files'];
        foreach ($files['name'] as $key => $value) {
            if ($files['name'][$key]) {
                $file = [
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                ];
                
                $uploaded_file = store_mm_handle_file_upload($file, $product_id, true);
                if ($uploaded_file && !is_wp_error($uploaded_file)) {
                    $new_design_files[] = $uploaded_file;
                }
            }
        }
        
        if (!empty($new_design_files)) {
            $existing_design_files = get_post_meta($product_id, '_store_mm_design_files', true);
            if (!is_array($existing_design_files)) $existing_design_files = [];
            update_post_meta($product_id, '_store_mm_design_files', array_merge($existing_design_files, $new_design_files));
        }
    }
    
    // Clear proposed changes if they existed
    if ($proposed_changes) {
        delete_post_meta($product_id, '_store_mm_proposed_changes');
    }
    
    // Log edit
    $log_action = $is_accepting_proposal ? 'price_proposal_accepted' : 'product_edited_by_designer';
    $log_entry = [
        'date' => current_time('mysql'),
        'action' => $log_action,
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'notes' => $notes,
        'changes' => [
            'price_updated' => true, 
            'price' => $price, 
            'royalty' => $royalty,
            'files_uploaded' => count($new_design_files),
            'accepted_proposal' => $is_accepting_proposal
        ],
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) $existing_logs = [];
    $existing_logs[] = $log_entry;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    wp_send_json_success([
        'message' => $is_accepting_proposal ? 'Price proposal accepted. Changes submitted for review.' : 'Price updated successfully. Files have been added.',
        'product_id' => $product_id,
        'accepted_proposal' => $is_accepting_proposal
    ]);
}

function store_mm_ajax_get_dev_products() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $is_admin = in_array('administrator', (array) $user->roles);
    $is_moderator = store_mm_user_can_moderate($user);
    $is_designer = store_mm_user_can_submit($user);
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
    $state = isset($_POST['state']) && $_POST['state'] !== 'all' ? sanitize_text_field($_POST['state']) : '';
    $designer = isset($_POST['designer']) && $_POST['designer'] !== 'all' ? intval($_POST['designer']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    $args = [
        'post_type' => 'product',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [['key' => '_store_mm_workflow_state', 'compare' => 'EXISTS']]
    ];
    
    $states = [STORE_MM_STATE_DRAFT, STORE_MM_STATE_SUBMITTED, STORE_MM_STATE_CHANGES_REQUESTED, STORE_MM_STATE_PROTOTYPING, STORE_MM_STATE_REJECTED];
    if ($state && in_array($state, $states)) {
        $args['meta_query'][] = ['key' => '_store_mm_workflow_state', 'value' => $state, 'compare' => '='];
    } else {
        $args['meta_query'][] = ['key' => '_store_mm_workflow_state', 'value' => $states, 'compare' => 'IN'];
    }
    
    if ($designer && ($is_admin || $is_moderator)) {
        $args['meta_query'][] = ['key' => '_store_mm_designer_id', 'value' => $designer, 'compare' => '='];
    } elseif ($is_designer && !$is_admin && !$is_moderator) {
        $args['meta_query'][] = ['key' => '_store_mm_designer_id', 'value' => $user_id, 'compare' => '='];
    }
    
    if ($search) $args['s'] = $search;
    
    $query = new WP_Query($args);
    $products = [];
    
    foreach ($query->posts as $post) {
        $common_data = store_mm_get_product_common_data($post->ID, $user);
        if (!$common_data) continue;
        
        $current_state = $common_data['workflow_state'];
        
        // Initialize all capabilities to false
        $can_edit = false;
        $can_request_changes = false;
        $can_move_to_prototyping = false;
        $can_approve = false;
        $can_reject = false;
        $can_submit_changes = false;
        $can_view_files = false;
        $can_set_price = false;
        $can_add_note = false;
        $can_archive = false;
        $can_delete = false;
        
        // Check for proposed changes
        $has_proposed_changes = !empty(get_post_meta($post->ID, '_store_mm_proposed_changes', true));
        
        // Determine capabilities based on state and role
        switch ($current_state) {
            case STORE_MM_STATE_DRAFT:
                // Designer: no actions
                // Moderator: no actions
                // Admin: Approve, Reject, View Files
                if ($is_admin) {
                    $can_approve = true;
                    $can_reject = true;
                    $can_view_files = true;
                }
                break;
                
            case STORE_MM_STATE_SUBMITTED:
                // Designer: no actions
                if ($is_moderator) {
                    $can_request_changes = true;
                    $can_move_to_prototyping = true;
                    $can_reject = true;
                    $can_view_files = true;
                }
                if ($is_admin) {
                    $can_approve = true;
                    $can_reject = true;
                    $can_request_changes = true;
                    $can_move_to_prototyping = true;
                    $can_view_files = true;
                    $can_set_price = true; // Admin can set price in submitted state
                }
                break;
                
            case STORE_MM_STATE_CHANGES_REQUESTED:
                // Designer: Submit Changes (with file uploads and royalty)
                if ($common_data['designer_id'] == $user_id) {
                    $can_submit_changes = true;
                }
                // Moderator: View Files only
                if ($is_moderator || $is_admin) {
                    $can_view_files = true;
                }
                // Admin: Approve, Reject, View Files
                if ($is_admin) {
                    $can_approve = true;
                    $can_reject = true;
                }
                break;
                
            case STORE_MM_STATE_PROTOTYPING:
                // Designer: View Status only (no button needed)
                // Moderator: Add Note, View Files
                if ($is_moderator) {
                    $can_add_note = true;
                    $can_view_files = true;
                }
                // Admin: Approve, Reject, Add Note, View Files
                if ($is_admin) {
                    $can_approve = true;
                    $can_reject = true;
                    $can_add_note = true;
                    $can_view_files = true;
                    // NO set price in prototyping - must be done before
                }
                break;
                
            case STORE_MM_STATE_REJECTED:
                // Designer: View Rejection Reason (via files modal)
                if ($common_data['designer_id'] == $user_id || $is_moderator || $is_admin) {
                    $can_view_files = true;
                }
                // Moderator: View Only (no actions)
                // Admin: Approve (republish), Archive, Delete
                if ($is_admin) {
                    $can_approve = true;
                    $can_archive = true;
                    $can_delete = true;
                }
                break;
        }
        
        $products[] = array_merge($common_data, [
            'submission_date' => get_post_meta($post->ID, '_store_mm_submission_date', true),
            'has_proposed_changes' => $has_proposed_changes,
            'can_edit' => $can_edit,
            'can_request_changes' => $can_request_changes,
            'can_move_to_prototyping' => $can_move_to_prototyping,
            'can_approve' => $can_approve,
            'can_reject' => $can_reject,
            'can_submit_changes' => $can_submit_changes,
            'can_view_files' => $can_view_files,
            'can_set_price' => $can_set_price,
            'can_add_note' => $can_add_note,
            'can_archive' => $can_archive,
            'can_delete' => $can_delete,
        ]);
    }
    
    wp_send_json_success([
        'products' => $products,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $query->max_num_pages,
            'total_items' => $query->found_posts,
        ],
    ]);
}

function store_mm_ajax_get_live_products() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $is_admin = in_array('administrator', (array) $user->roles);
    $is_moderator = store_mm_user_can_moderate($user);
    $is_designer = store_mm_user_can_submit($user);
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
    $category = isset($_POST['category']) && $_POST['category'] !== 'all' ? intval($_POST['category']) : 0;
    $designer = isset($_POST['designer']) && $_POST['designer'] !== 'all' ? intval($_POST['designer']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [['key' => '_store_mm_workflow_state', 'value' => STORE_MM_STATE_APPROVED, 'compare' => '=']]
    ];
    
    if ($designer && ($is_admin || $is_moderator)) {
        $args['meta_query'][] = ['key' => '_store_mm_designer_id', 'value' => $designer, 'compare' => '='];
    } elseif ($is_designer && !$is_admin && !$is_moderator) {
        $args['meta_query'][] = ['key' => '_store_mm_designer_id', 'value' => $user_id, 'compare' => '='];
    }
    
    if ($category) {
        $args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $category,
        ]];
    }
    
    if ($search) $args['s'] = $search;
    
    $query = new WP_Query($args);
    $products = [];
    
    foreach ($query->posts as $post) {
        $common_data = store_mm_get_product_common_data($post->ID, $user);
        if (!$common_data) continue;
        
        $can_reject = $is_admin; // Only admin can reject (unpublish) approved products
        $can_view_files = ($is_admin || $is_moderator || $common_data['designer_id'] == $user_id);
        
        $products[] = array_merge($common_data, [
            'published_date' => $post->post_date,
            'sales_count' => get_post_meta($post->ID, '_store_mm_sales_count', true) ?: 0,
            'can_reject' => $can_reject,
            'can_view_files' => $can_view_files,
        ]);
    }
    
    wp_send_json_success([
        'products' => $products,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $query->max_num_pages,
            'total_items' => $query->found_posts,
        ],
    ]);
}

function store_mm_ajax_get_product_files() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_workflow_nonce')) wp_send_json_error('Security check failed.');
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in.');
    
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $is_admin = in_array('administrator', (array) $user->roles);
    $is_moderator = store_mm_user_can_moderate($user);
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) wp_send_json_error('Missing product ID.');
    
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    $can_view = ($is_admin || $is_moderator || $designer_id == $user_id);
    if (!$can_view) wp_send_json_error('Insufficient permissions.');
    
    // Get product images
    $product_images = [];
    $featured_image_id = get_post_thumbnail_id($product_id);
    
    if ($featured_image_id) {
        $product_images[] = [
            'id' => $featured_image_id,
            'name' => get_the_title($featured_image_id) ?: 'Featured Image',
            'url' => wp_get_attachment_url($featured_image_id),
            'thumb' => wp_get_attachment_image_url($featured_image_id, 'thumbnail'),
            'type' => 'image',
        ];
    }
    
    // Get gallery images
    $gallery_image_ids = get_post_meta($product_id, '_product_image_gallery', true);
    if ($gallery_image_ids) {
        $gallery_ids = explode(',', $gallery_image_ids);
        foreach ($gallery_ids as $gallery_id) {
            if ($gallery_id) {
                $product_images[] = [
                    'id' => $gallery_id,
                    'name' => get_the_title($gallery_id) ?: 'Gallery Image',
                    'url' => wp_get_attachment_url($gallery_id),
                    'thumb' => wp_get_attachment_image_url($gallery_id, 'thumbnail'),
                    'type' => 'image',
                ];
            }
        }
    }
    
    // Get design files
    $design_files = [];
    $design_file_ids = get_post_meta($product_id, '_store_mm_design_files', true);
    
    if (is_array($design_file_ids)) {
        foreach ($design_file_ids as $file_id) {
            $file_url = wp_get_attachment_url($file_id);
            $file_ext = pathinfo($file_url, PATHINFO_EXTENSION);
            
            $design_files[] = [
                'id' => $file_id,
                'name' => get_post_meta($file_id, '_store_mm_original_filename', true) ?: get_the_title($file_id),
                'url' => $file_url,
                'icon' => store_mm_get_file_icon($file_ext),
                'type' => 'design_file',
                'extension' => $file_ext,
                'size' => store_mm_format_file_size(filesize(get_attached_file($file_id))),
            ];
        }
    }
    
    // Get latest notes and check for proposed changes
    $latest_notes = '';
    $has_proposed_changes = false;
    $proposed_changes = get_post_meta($product_id, '_store_mm_proposed_changes', true);
    $submission_log = get_post_meta($product_id, '_store_mm_submission_log', true);
    
    if (is_array($submission_log)) {
        $reversed_log = array_reverse($submission_log);
        foreach ($reversed_log as $log) {
            if (isset($log['action']) && in_array($log['action'], ['request_changes', 'state_changed', 'state_changed_frontend', 'internal_note_added', 'price_proposed_by_admin'])) {
                if (!empty($log['notes'])) {
                    $latest_notes = $log['notes'];
                    break;
                }
            }
        }
    }
    
    if ($proposed_changes && !empty($proposed_changes['notes'])) {
        $has_proposed_changes = true;
        $latest_notes = "Admin Price Proposal:\n" . $proposed_changes['notes'] . "\n\n" . $latest_notes;
    }
    
    wp_send_json_success([
        'images' => $product_images,
        'design_files' => $design_files,
        'notes' => $latest_notes,
        'has_proposed_changes' => $has_proposed_changes,
        'product_title' => get_the_title($product_id),
    ]);
}

function store_mm_get_file_icon($extension) {
    $icons = [
        'stl' => '📦', 'obj' => '📦', '3mf' => '📦', 'step' => '📦', 'iges' => '📦',
        'sldprt' => '⚙️', 'f3d' => '⚙️', 'skp' => '⚙️', 'dwg' => '⚙️',
        'pdf' => '📄', 'doc' => '📄', 'docx' => '📄', 'txt' => '📄',
    ];
    return $icons[strtolower($extension)] ?? '📎';
}

function store_mm_format_file_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    else return $bytes . ' B';
}

function store_mm_render_my_products_dev($atts) {
    if (!is_user_logged_in()) return store_mm_render_login_required();
    
    $user = wp_get_current_user();
    $is_designer = store_mm_user_can_submit($user);
    $is_moderator = store_mm_user_can_moderate($user);
    $is_admin = in_array('administrator', (array) $user->roles);
    
    if (!$is_designer && !$is_moderator && !$is_admin) return store_mm_render_no_permission();
    
    store_mm_enqueue_frontend_workflow_assets();
    
    ob_start();
    ?>
    <div class="store-mm-workflow-container" id="store-mm-dev-products">
        <div class="store-mm-workflow-header">
            <h1><span class="store-mm-icon">🚧</span>Products Under Development</h1>
            <p class="store-mm-workflow-description">
                <?php if ($is_designer): ?>Manage your designs that are being reviewed or prototyped
                <?php elseif ($is_moderator): ?>Review and manage submissions awaiting action
                <?php else: ?>Admin: Manage all products in development pipeline<?php endif; ?>
            </p>
        </div>
        
        <?php if ($is_admin): ?>
        <div class="store-mm-role-notice store-mm-notice-admin">
            <h3><span class="dashicons dashicons-admin-settings"></span> Admin Access</h3>
            <p>You have full control over all products. You can request changes, move to prototyping, approve, reject, and set prices (before prototyping).</p>
        </div>
        <?php elseif ($is_moderator): ?>
        <div class="store-mm-role-notice store-mm-notice-moderator">
            <h3><span class="dashicons dashicons-shield"></span> Moderator Access</h3>
            <p>You can review submissions, request changes, move to prototyping, and reject designs. Final approval and price setting requires admin.</p>
        </div>
        <?php elseif ($is_designer): ?>
        <div class="store-mm-role-notice store-mm-notice-designer">
            <h3><span class="dashicons dashicons-admin-users"></span> Designer Access</h3>
            <p>You can only view and edit your own designs. You'll be notified when your designs require changes or when admin proposes a price.</p>
        </div>
        <?php endif; ?>
        
        <div class="store-mm-workflow-filters">
            <div class="store-mm-filter-group">
                <label for="store-mm-dev-state-filter">Filter by State:</label>
                <select id="store-mm-dev-state-filter" class="store-mm-filter-select">
                    <option value="all">All States</option>
                    <option value="<?php echo STORE_MM_STATE_DRAFT; ?>">Draft</option>
                    <option value="<?php echo STORE_MM_STATE_SUBMITTED; ?>">Submitted</option>
                    <option value="<?php echo STORE_MM_STATE_CHANGES_REQUESTED; ?>">Changes Requested</option>
                    <option value="<?php echo STORE_MM_STATE_PROTOTYPING; ?>">Prototyping</option>
                    <option value="<?php echo STORE_MM_STATE_REJECTED; ?>">Rejected</option>
                </select>
            </div>
            
            <?php if ($is_admin || $is_moderator): ?>
            <div class="store-mm-filter-group">
                <label for="store-mm-dev-designer-filter">Filter by Designer:</label>
                <select id="store-mm-dev-designer-filter" class="store-mm-filter-select">
                    <option value="all">All Designers</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="store-mm-filter-group">
                <input type="text" id="store-mm-dev-search" class="store-mm-search-input" placeholder="Search products...">
                <button id="store-mm-dev-apply-filters" class="store-mm-button store-mm-button-primary">Apply</button>
                <button id="store-mm-dev-reset-filters" class="store-mm-button store-mm-button-secondary">Reset</button>
            </div>
        </div>
        
        <div class="store-mm-workflow-table-container">
            <table class="store-mm-workflow-table">
                <thead>
                    <tr>
                        <?php if ($is_admin || $is_moderator): ?><th>Designer</th><?php endif; ?>
                        <th>Product</th><th>Category</th><th>Price</th><th>Royalty</th><th>Submitted</th><th>State</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="store-mm-dev-products-body">
                    <tr><td colspan="<?php echo ($is_admin || $is_moderator) ? '8' : '7'; ?>" class="store-mm-loading-row">
                        <div class="store-mm-spinner"></div><span>Loading products...</span>
                    </td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="store-mm-workflow-pagination">
            <span class="store-mm-pagination-info" id="store-mm-dev-pagination-info">Showing 0 of 0</span>
            <button id="store-mm-dev-prev-page" class="store-mm-button store-mm-button-secondary" disabled>Previous</button>
            <button id="store-mm-dev-next-page" class="store-mm-button store-mm-button-secondary" disabled>Next</button>
        </div>
    </div>
    
    <!-- Submit Changes Modal (for designer when changes requested) -->
    <div id="store-mm-submit-changes-modal" class="store-mm-modal" style="display: none;">
        <div class="store-mm-modal-content">
            <div class="store-mm-modal-header">
                <h3 id="store-mm-submit-changes-title">Submit Changes</h3>
                <button type="button" class="store-mm-modal-close">&times;</button>
            </div>
            <div class="store-mm-modal-body">
                <div id="store-mm-submit-changes-message">
                    <p id="proposal-notice" style="display: none; background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2196F3;">
                        <strong>Admin has proposed a price change. Review and confirm:</strong>
                        <br><span id="proposal-details"></span>
                    </p>
                    <p>Update your product details and submit for review.</p>
                </div>
                
                <div class="store-mm-form-group">
                    <label for="store-mm-submit-changes-price">Update Price *</label>
                    <div class="store-mm-input-with-prefix"><span class="store-mm-input-prefix"><?php echo STORE_MM_CURRENCY_SYMBOL; ?></span>
                        <input type="number" id="store-mm-submit-changes-price" class="store-mm-input" min="0.001" step="0.001" required>
                    </div>
                    <div class="store-mm-hint">Update the proposed price for your design (3 decimal places)</div>
                </div>
                
                <div class="store-mm-form-group">
                    <label for="store-mm-submit-changes-royalty">Update Royalty Percentage *</label>
                    <div class="store-mm-input-with-suffix">
                        <input type="number" id="store-mm-submit-changes-royalty" class="store-mm-input" min="1" max="50" step="0.1" required>
                        <span class="store-mm-input-suffix">%</span>
                    </div>
                    <div class="store-mm-hint">Enter royalty percentage (1-50%)</div>
                </div>
                
                <div class="store-mm-form-group">
                    <label>Upload Additional Design Files (Optional)</label>
                    <div class="store-mm-upload-zone" id="submit-changes-file-upload-zone">
                        <div class="store-mm-upload-placeholder">
                            <span class="store-mm-upload-icon">📎</span>
                            <p>Upload additional design files</p>
                            <p class="store-mm-upload-subtext">Click or drag & drop (optional)</p>
                        </div>
                    </div>
                    <input type="file" id="submit-changes-design-files" name="submit_changes_design_files[]" multiple style="display: none;">
                    <div class="store-mm-upload-preview" id="submit-changes-file-preview"></div>
                    <div class="store-mm-hint">Upload additional 3D models, CAD files, or technical drawings. Existing files will be kept.</div>
                </div>
                
                <div class="store-mm-form-group">
                    <label for="store-mm-submit-changes-notes">Add notes for reviewers (optional):</label>
                    <textarea id="store-mm-submit-changes-notes" rows="4" class="store-mm-textarea" placeholder="Explain what changes you made..."></textarea>
                </div>
            </div>
            <div class="store-mm-modal-footer">
                <button type="button" class="store-mm-button store-mm-button-secondary store-mm-modal-cancel">Cancel</button>
                <button type="button" class="store-mm-button store-mm-button-primary" id="store-mm-confirm-submit-changes">Submit Changes for Review</button>
            </div>
        </div>
    </div>
    
    <!-- Action Modal -->
    <div id="store-mm-action-modal" class="store-mm-modal" style="display: none;">
        <div class="store-mm-modal-content">
            <div class="store-mm-modal-header">
                <h3 id="store-mm-modal-title">Action</h3>
                <button type="button" class="store-mm-modal-close">&times;</button>
            </div>
            <div class="store-mm-modal-body">
                <div id="store-mm-modal-message"><p>Are you sure you want to perform this action?</p></div>
                <div id="store-mm-action-reason" style="display: none;">
                    <div class="store-mm-modal-field">
                        <label for="store-mm-reason-select">Reason for rejection:</label>
                        <select id="store-mm-reason-select" class="store-mm-select">
                            <option value="">Select a reason</option>
                            <option value="not_manufacturable">Not manufacturable</option>
                            <option value="poor_quality">Poor quality</option>
                            <option value="intellectual_property">Intellectual property concerns</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="store-mm-modal-field" id="store-mm-other-reason" style="display: none;">
                        <label for="store-mm-custom-reason">Please specify:</label>
                        <input type="text" id="store-mm-custom-reason" class="store-mm-input">
                    </div>
                </div>
                <div class="store-mm-modal-field" id="store-mm-action-notes">
                    <label for="store-mm-notes-textarea">Add notes (optional):</label>
                    <textarea id="store-mm-notes-textarea" rows="4" class="store-mm-textarea" placeholder="Add notes..."></textarea>
                </div>
                <div class="store-mm-modal-field" id="store-mm-set-price" style="display: none;">
                    <h4>Set Price & Royalty (Before Prototyping)</h4>
                    <div class="store-mm-form-group">
                        <label for="store-mm-final-price">Proposed Price (<?php echo STORE_MM_CURRENCY_SYMBOL; ?>):</label>
                        <input type="number" id="store-mm-final-price" class="store-mm-input" min="0.001" step="0.001">
                        <div class="store-mm-hint">Price will be sent to designer for confirmation</div>
                    </div>
                    <div class="store-mm-form-group">
                        <label for="store-mm-final-royalty">Proposed Royalty (%):</label>
                        <input type="number" id="store-mm-final-royalty" class="store-mm-input" min="1" max="50" step="0.1" value="10">
                        <div class="store-mm-hint">Royalty percentage (1-50%)</div>
                    </div>
                </div>
            </div>
            <div class="store-mm-modal-footer">
                <button type="button" class="store-mm-button store-mm-button-secondary store-mm-modal-cancel">Cancel</button>
                <button type="button" class="store-mm-button store-mm-button-primary" id="store-mm-confirm-action">Confirm</button>
            </div>
        </div>
    </div>
    
    <!-- Files Modal -->
    <div id="store-mm-files-modal" class="store-mm-modal" style="display: none;">
        <div class="store-mm-modal-content" style="max-width: 800px;">
            <div class="store-mm-modal-header">
                <h3 id="store-mm-files-modal-title">Product Files</h3>
                <button type="button" class="store-mm-modal-close">&times;</button>
            </div>
            <div class="store-mm-modal-body">
                <div id="store-mm-files-loading" class="store-mm-loading-row">
                    <div class="store-mm-spinner"></div><span>Loading files...</span>
                </div>
                <div id="store-mm-files-content" style="display: none;"></div>
            </div>
            <div class="store-mm-modal-footer">
                <button type="button" class="store-mm-button store-mm-button-secondary store-mm-modal-close">Close</button>
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div id="store-mm-note-modal" class="store-mm-modal" style="display: none;">
        <div class="store-mm-modal-content" style="max-width: 500px;">
            <div class="store-mm-modal-header">
                <h3 id="store-mm-note-modal-title">Add Internal Note</h3>
                <button type="button" class="store-mm-modal-close">&times;</button>
            </div>
            <div class="store-mm-modal-body">
                <div id="store-mm-note-message"><p>Add an internal note about this product (visible to staff only).</p></div>
                <div class="store-mm-modal-field">
                    <label for="store-mm-note-textarea">Note *</label>
                    <textarea id="store-mm-note-textarea" rows="4" class="store-mm-textarea" placeholder="Add internal note..." required></textarea>
                </div>
            </div>
            <div class="store-mm-modal-footer">
                <button type="button" class="store-mm-button store-mm-button-secondary store-mm-modal-cancel">Cancel</button>
                <button type="button" class="store-mm-button store-mm-button-primary" id="store-mm-confirm-note">Add Note</button>
            </div>
        </div>
    </div>
    <?php
    
    wp_localize_script('store-mm-workflow', 'store_mm_workflow', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('store_mm_workflow_nonce'),
        'currency' => STORE_MM_CURRENCY_SYMBOL,
        'decimals' => STORE_MM_PRICE_DECIMALS,
        'user' => ['id' => $user->ID, 'is_admin' => $is_admin, 'is_moderator' => $is_moderator, 'is_designer' => $is_designer],
        'states' => [
            'draft' => STORE_MM_STATE_DRAFT,
            'submitted' => STORE_MM_STATE_SUBMITTED,
            'changes_requested' => STORE_MM_STATE_CHANGES_REQUESTED,
            'prototyping' => STORE_MM_STATE_PROTOTYPING,
            'approved' => STORE_MM_STATE_APPROVED,
            'rejected' => STORE_MM_STATE_REJECTED,
        ],
        'strings' => store_mm_get_workflow_strings()
    ]);
    
    return ob_get_clean();
}

function store_mm_render_my_products_live($atts) {
    if (!is_user_logged_in()) return store_mm_render_login_required();
    
    $user = wp_get_current_user();
    $is_designer = store_mm_user_can_submit($user);
    $is_moderator = store_mm_user_can_moderate($user);
    $is_admin = in_array('administrator', (array) $user->roles);
    
    if (!$is_designer && !$is_moderator && !$is_admin) return store_mm_render_no_permission();
    
    store_mm_enqueue_frontend_workflow_assets();
    
    ob_start();
    ?>
    <div class="store-mm-workflow-container" id="store-mm-live-products">
        <div class="store-mm-workflow-header">
            <h1><span class="store-mm-icon">🏪</span>Live Products in Store</h1>
            <p class="store-mm-workflow-description">
                <?php if ($is_designer): ?>Your approved designs that are available for purchase
                <?php elseif ($is_moderator): ?>Approved products available in the store (read-only)
                <?php else: ?>Admin: All approved products in the store<?php endif; ?>
            </p>
        </div>
        
        <div class="store-mm-workflow-filters">
            <div class="store-mm-filter-group">
                <label for="store-mm-live-category-filter">Filter by Category:</label>
                <select id="store-mm-live-category-filter" class="store-mm-filter-select">
                    <option value="all">All Categories</option>
                </select>
            </div>
            
            <?php if ($is_admin || $is_moderator): ?>
            <div class="store-mm-filter-group">
                <label for="store-mm-live-designer-filter">Filter by Designer:</label>
                <select id="store-mm-live-designer-filter" class="store-mm-filter-select">
                    <option value="all">All Designers</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="store-mm-filter-group">
                <input type="text" id="store-mm-live-search" class="store-mm-search-input" placeholder="Search products...">
                <button id="store-mm-live-apply-filters" class="store-mm-button store-mm-button-primary">Apply</button>
                <button id="store-mm-live-reset-filters" class="store-mm-button store-mm-button-secondary">Reset</button>
            </div>
        </div>
        
        <div class="store-mm-workflow-table-container">
            <table class="store-mm-workflow-table">
                <thead>
                    <tr>
                        <?php if ($is_admin || $is_moderator): ?><th>Designer</th><?php endif; ?>
                        <th>Product</th><th>Category</th><th>Price</th><th>Royalty</th><th>Published</th><th>Sales</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="store-mm-live-products-body">
                    <tr><td colspan="<?php echo ($is_admin || $is_moderator) ? '8' : '7'; ?>" class="store-mm-loading-row">
                        <div class="store-mm-spinner"></div><span>Loading products...</span>
                    </td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="store-mm-workflow-pagination">
            <span class="store-mm-pagination-info" id="store-mm-live-pagination-info">Showing 0 of 0</span>
            <button id="store-mm-live-prev-page" class="store-mm-button store-mm-button-secondary" disabled>Previous</button>
            <button id="store-mm-live-next-page" class="store-mm-button store-mm-button-secondary" disabled>Next</button>
        </div>
    </div>
    <?php
    
    wp_localize_script('store-mm-workflow', 'store_mm_workflow', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('store_mm_workflow_nonce'),
        'currency' => STORE_MM_CURRENCY_SYMBOL,
        'decimals' => STORE_MM_PRICE_DECIMALS,
        'user' => ['id' => $user->ID, 'is_admin' => $is_admin, 'is_moderator' => $is_moderator, 'is_designer' => $is_designer],
        'states' => ['approved' => STORE_MM_STATE_APPROVED],
        'strings' => store_mm_get_workflow_strings()
    ]);
    
    return ob_get_clean();
}

function store_mm_enqueue_frontend_workflow_assets() {
    wp_enqueue_style('store-mm-workflow', STORE_MM_PLUGIN_URL . 'assets/css/workflow.css', [], STORE_MM_VERSION);
    wp_enqueue_script('store-mm-workflow', STORE_MM_PLUGIN_URL . 'assets/js/workflow.js', ['jquery'], STORE_MM_VERSION, true);
}

function store_mm_get_workflow_strings() {
    return [
        'loading' => __('Loading...', 'store-mm'),
        'no_products' => __('No products found.', 'store-mm'),
        'error' => __('An error occurred. Please try again.', 'store-mm'),
        'confirm_request_changes' => __('Request changes for this design?', 'store-mm'),
        'confirm_move_to_prototyping' => __('Move this design to prototyping?', 'store-mm'),
        'confirm_approve' => __('Approve this design for sale?', 'store-mm'),
        'confirm_reject' => __('Reject this design?', 'store-mm'),
        'confirm_set_price' => __('Set price for this design?', 'store-mm'),
        'saving' => __('Saving...', 'store-mm'),
        'saved' => __('Changes saved!', 'store-mm'),
        'submitting' => __('Submitting...', 'store-mm'),
        'submitted' => __('Submitted!', 'store-mm'),
        'setting_price' => __('Setting price...', 'store-mm'),
        'price_set' => __('Price proposal sent to designer!', 'store-mm'),
    ];
}

function store_mm_render_login_required() {
    return '<div class="store-mm-error-box"><h3>Login Required</h3><p>Please log in to access your products.</p></div>';
}

function store_mm_render_no_permission() {
    return '<div class="store-mm-error-box"><h3>Access Denied</h3><p>You do not have permission to view this page.</p><p>Please contact an administrator if you believe this is an error.</p></div>';
}