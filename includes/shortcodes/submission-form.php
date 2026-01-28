<?php
/**
 * Store MM Submission Form Shortcode
 * Handles all frontend form functionality including file uploads
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

add_shortcode('store_mm_submit', 'store_mm_render_submission_form');

add_action('wp_ajax_store_mm_submit_design', 'store_mm_ajax_submit_design');
add_action('wp_ajax_nopriv_store_mm_submit_design', 'store_mm_ajax_no_priv');
add_action('wp_ajax_store_mm_get_subcategories', 'store_mm_ajax_get_subcategories');

function store_mm_ajax_no_priv() {
    store_mm_send_json_response('You must be logged in to submit designs.', false);
}

function store_mm_ajax_get_subcategories() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_ajax_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    $parent_id = intval($_POST['parent_id']);
    $parent_term = get_term($parent_id, 'product_cat');
    
    if (!$parent_term) {
        store_mm_send_json_response('Invalid category.', false);
    }
    
    $subcategories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'parent' => $parent_id,
    ]);
    
    $options = '<option value="">Select sub-category</option>';
    foreach ($subcategories as $subcat) {
        $options .= '<option value="' . esc_attr($subcat->term_id) . '">' . esc_html($subcat->name) . '</option>';
    }
    
    store_mm_send_json_response($options);
}

function store_mm_ajax_submit_design() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_ajax_nonce')) {
        store_mm_send_json_response('Security check failed.', false);
    }
    
    if (!is_user_logged_in()) {
        store_mm_send_json_response('You must be logged in to submit designs.', false);
    }
    
    $user = wp_get_current_user();
    if (!store_mm_user_can_submit($user)) {
        store_mm_send_json_response('You do not have permission to submit designs.', false);
    }
    
    $required_fields = [
        'design_title' => 'Design title',
        'design_description' => 'Design description',
        'category_x' => 'Main category',
        'category_y' => 'Sub-category',
        'estimated_price' => 'Estimated price',
    ];
    
    $errors = [];
    foreach ($required_fields as $field => $label) {
        if (empty($_POST[$field])) {
            $errors[] = $label . ' is required';
        }
    }
    
    if (empty($_POST['agree_nda'])) {
        $errors[] = 'You must agree to the NDA terms';
    }
    
    if (empty($_POST['confirm_terms']) || empty($_POST['confirm_review'])) {
        $errors[] = 'You must confirm all terms';
    }
    
    if (!empty($errors)) {
        store_mm_send_json_response(implode(', ', $errors), false);
    }
    
    $design_title = sanitize_text_field($_POST['design_title']);
    $design_description = wp_kses_post($_POST['design_description']);
    $category_x = intval($_POST['category_x']);
    $category_y = intval($_POST['category_y']);
    $estimated_price = floatval($_POST['estimated_price']);
    $royalty_option = sanitize_text_field($_POST['royalty_option']);
    $custom_royalty = !empty($_POST['custom_royalty']) ? floatval($_POST['custom_royalty']) : null;
    
    $royalty_percentage = 10;
    if ($royalty_option === 'custom' && $custom_royalty) {
        $royalty_percentage = max(1, min(50, $custom_royalty));
    }
    
    $store_category = store_mm_get_store_category();
    
    if (!$store_category) {
        store_mm_send_json_response('Store category not found. Please contact administrator.', false);
    }
    
    $category_x_term = get_term($category_x, 'product_cat');
    $category_y_term = get_term($category_y, 'product_cat');
    
    if (!$category_x_term || !$category_y_term) {
        store_mm_send_json_response('Invalid categories selected.', false);
    }
    
    if ($category_y_term->parent != $category_x) {
        store_mm_send_json_response('Selected sub-category does not belong to the selected main category.', false);
    }
    
    if ($category_x_term->parent != $store_category->term_id) {
        store_mm_send_json_response('Selected category does not belong to Store.', false);
    }
    
    $product_args = [
        'post_title'   => $design_title,
        'post_content' => $design_description,
        'post_status'  => 'draft',
        'post_type'    => 'product',
        'post_author'  => $user->ID,
    ];
    
    $product_id = wp_insert_post($product_args);
    
    if (is_wp_error($product_id) || !$product_id) {
        store_mm_send_json_response('Failed to create product: ' . (is_wp_error($product_id) ? $product_id->get_error_message() : 'Unknown error'), false);
    }
    
    wp_set_object_terms($product_id, 'simple', 'product_type');
    
    $category_ids = [
        $store_category->term_id,
        $category_x,
        $category_y
    ];
    
    wp_set_object_terms($product_id, $category_ids, 'product_cat');
    
    update_post_meta($product_id, '_price', $estimated_price);
    update_post_meta($product_id, '_regular_price', $estimated_price);
    
    update_post_meta($product_id, '_store_mm_designer_id', $user->ID);
    update_post_meta($product_id, '_store_mm_designer_name', $user->display_name);
    update_post_meta($product_id, '_store_mm_designer_email', $user->user_email);
    update_post_meta($product_id, '_store_mm_estimated_price', $estimated_price);
    update_post_meta($product_id, '_store_mm_royalty_percentage', $royalty_percentage);
    update_post_meta($product_id, '_store_mm_royalty_option', $royalty_option);
    update_post_meta($product_id, '_store_mm_requested_royalty', $custom_royalty);
    update_post_meta($product_id, '_store_mm_workflow_state', STORE_MM_STATE_SUBMITTED);
    update_post_meta($product_id, '_store_mm_submission_date', current_time('mysql'));
    update_post_meta($product_id, '_store_mm_nda_agreed', true);
    
    $uploaded_files = [];
    
    if (!empty($_FILES['product_images'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $image_ids = [];
        $files = $_FILES['product_images'];
        
        foreach ($files['name'] as $key => $value) {
            if ($files['name'][$key]) {
                $file = [
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                ];
                
                $uploaded_file = store_mm_handle_file_upload($file, $product_id, false);
                if ($uploaded_file && !is_wp_error($uploaded_file)) {
                    $image_ids[] = $uploaded_file;
                }
            }
        }
        
        if (!empty($image_ids)) {
            set_post_thumbnail($product_id, $image_ids[0]);
            
            if (count($image_ids) > 1) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($image_ids, 1)));
            }
            
            $uploaded_files['images'] = $image_ids;
        }
    }
    
    if (!empty($_FILES['design_files'])) {
        $design_file_ids = [];
        $files = $_FILES['design_files'];
        
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
                    $design_file_ids[] = $uploaded_file;
                }
            }
        }
        
        if (!empty($design_file_ids)) {
            update_post_meta($product_id, '_store_mm_design_files', $design_file_ids);
            $uploaded_files['design_files'] = $design_file_ids;
        }
    }
    
    $submission_log = [
        'date' => current_time('mysql'),
        'action' => 'design_submitted',
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'details' => [
            'title' => $design_title,
            'price' => $estimated_price,
            'royalty' => $royalty_percentage . '%',
            'categories' => $category_ids
        ]
    ];
    
    $existing_logs = get_post_meta($product_id, '_store_mm_submission_log', true);
    if (!is_array($existing_logs)) {
        $existing_logs = [];
    }
    $existing_logs[] = $submission_log;
    update_post_meta($product_id, '_store_mm_submission_log', $existing_logs);
    
    $response = [
        'success' => true,
        'message' => 'Design submitted successfully! Your product is now under review.',
        'data' => [
            'product_id' => $product_id,
            'product_title' => $design_title,
            'uploaded_files' => count($uploaded_files)
        ]
    ];
    
    store_mm_send_json_response($response);
}

function store_mm_handle_file_upload($file, $product_id, $is_design_file = false) {
    $max_size = $is_design_file ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
    
    if ($file['size'] > $max_size) {
        return new WP_Error('file_too_large', 'File is too large');
    }
    
    $allowed_mimes = $is_design_file ? [
        'stl' => 'application/sla',
        'obj' => 'text/plain',
        '3mf' => 'model/3mf',
        'step' => 'application/step',
        'iges' => 'application/iges',
        'sldprt' => 'application/octet-stream',
        'f3d' => 'application/octet-stream',
        'skp' => 'application/octet-stream',
        'dwg' => 'image/vnd.dwg',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
    ] : [
        'jpg|jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    
    $filetype = wp_check_filetype($file['name'], $allowed_mimes);
    if (!$filetype['type']) {
        return new WP_Error('invalid_file_type', 'Invalid file type');
    }
    
    $upload = wp_handle_upload($file, ['test_form' => false]);
    
    if (isset($upload['error'])) {
        return new WP_Error('upload_error', $upload['error']);
    }
    
    $attachment = [
        'post_mime_type' => $upload['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $product_id,
    ];
    
    $attach_id = wp_insert_attachment($attachment, $upload['file'], $product_id);
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    if ($is_design_file) {
        update_post_meta($attach_id, '_store_mm_design_file', true);
        update_post_meta($attach_id, '_store_mm_original_filename', $file['name']);
    }
    
    return $attach_id;
}

function store_mm_render_submission_form($atts) {
    if (!is_user_logged_in()) {
        return '<p class="store-mm-error">' . esc_html__('Please log in to access the design submission form.', 'store-mm') . '</p>';
    }
    
    if (!store_mm_user_can_submit()) {
        return '<p class="store-mm-error">' . esc_html__('You do not have permission to submit designs. Please contact the administrator to request designer access.', 'store-mm') . '</p>';
    }
    
    $store_category = store_mm_get_store_category();
    
    if (!$store_category) {
        return '<div class="store-mm-error-box">
            <h3>Store Category Not Found</h3>
            <p>Please set up your category structure before submitting designs:</p>
            <ol>
                <li>Go to Products → Categories in WordPress admin</li>
                <li>Create a top-level category named "Store"</li>
                <li>Create Category X as a child of "Store"</li>
                <li>Create Category Y as a child of Category X</li>
            </ol>
            <p><strong>Required structure:</strong> Store → Category X → Category Y</p>
        </div>';
    }
    
    $category_x_terms = store_mm_get_category_x_options();
    
    if (empty($category_x_terms)) {
        return '<div class="store-mm-error-box">
            <h3>No Categories Found</h3>
            <p>Please create categories under the "Store" category:</p>
            <ol>
                <li>Go to Products → Categories</li>
                <li>Create at least one category under "Store" (Category X)</li>
                <li>Create sub-categories under Category X (Category Y)</li>
            </ol>
        </div>';
    }
    
    $nda_text = store_mm_get_nda_text();
    
    $user = wp_get_current_user();
    $user_name = $user->display_name;
    $user_email = $user->user_email;
    
    wp_enqueue_style(
        'store-mm-frontend',
        STORE_MM_PLUGIN_URL . 'assets/css/frontend.css',
        [],
        STORE_MM_VERSION
    );
    
    wp_enqueue_script(
        'store-mm-frontend',
        STORE_MM_PLUGIN_URL . 'assets/js/frontend.js',
        ['jquery'],
        STORE_MM_VERSION,
        true
    );
    
    if (function_exists('wp_enqueue_media')) {
        wp_enqueue_media();
    }
    
    wp_localize_script('store-mm-frontend', 'store_mm_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('store_mm_ajax_nonce'),
        'user' => [
            'id' => $user->ID,
            'name' => $user_name,
            'email' => $user_email,
        ],
        'messages' => [
            'submitting' => __('Submitting...', 'store-mm'),
            'success' => __('Design submitted successfully!', 'store-mm'),
            'error' => __('An error occurred. Please try again.', 'store-mm'),
        ]
    ]);
    
    ob_start();
    ?>
    <div class="store-mm-submission-wrapper" id="store-mm-submission">
        <div class="store-mm-form-container">
            <div class="store-mm-progress">
                <div class="store-mm-step active" data-step="1">
                    <span class="store-mm-step-number">1</span>
                    <span class="store-mm-step-label">Design Details</span>
                </div>
                <div class="store-mm-step" data-step="2">
                    <span class="store-mm-step-number">2</span>
                    <span class="store-mm-step-label">Upload Files</span>
                </div>
                <div class="store-mm-step" data-step="3">
                    <span class="store-mm-step-number">3</span>
                    <span class="store-mm-step-label">Pricing & Submit</span>
                </div>
            </div>
            
            <form id="store-mm-submission-form" class="store-mm-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="store_mm_submit_design">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('store_mm_ajax_nonce'); ?>">
                
                <div class="store-mm-tab active" id="tab-1">
                    <div class="store-mm-tab-header">
                        <h2><span class="store-mm-icon">📝</span> Design Information</h2>
                        <p class="store-mm-tab-description">Enter basic information about your design</p>
                    </div>
                    
                    <div class="store-mm-form-group">
                        <label for="design-title" class="store-mm-label">
                            <span class="store-mm-label-text">Design Title *</span>
                            <span class="store-mm-required">Required</span>
                        </label>
                        <input type="text" id="design-title" name="design_title" class="store-mm-input" required 
                               placeholder="Enter a descriptive title for your product">
                        <div class="store-mm-hint">Make it clear and appealing to customers</div>
                    </div>
                    
                    <div class="store-mm-form-group">
                        <label for="design-description" class="store-mm-label">
                            <span class="store-mm-label-text">Design Description *</span>
                            <span class="store-mm-required">Required</span>
                        </label>
                        <textarea id="design-description" name="design_description" class="store-mm-textarea" required 
                                  rows="5" placeholder="Describe your design in detail. What makes it special? How can it be used?"></textarea>
                        <div class="store-mm-hint">Be specific about features, materials, and intended use</div>
                    </div>
                    
                    <div class="store-mm-form-row">
                        <div class="store-mm-form-group">
                            <label for="category-x" class="store-mm-label">
                                <span class="store-mm-label-text">Main Category *</span>
                                <span class="store-mm-required">Required</span>
                            </label>
                            <select id="category-x" name="category_x" class="store-mm-select" required>
                                <option value="">Select a category</option>
                                <?php foreach ($category_x_terms as $cat_x): ?>
                                    <option value="<?php echo esc_attr($cat_x->term_id); ?>">
                                        <?php echo esc_html($cat_x->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="store-mm-hint">Select the main category for your design</div>
                        </div>
                        
                        <div class="store-mm-form-group">
                            <label for="category-y" name="category_y" class="store-mm-label">
                                <span class="store-mm-label-text">Sub-Category *</span>
                                <span class="store-mm-required">Required</span>
                            </label>
                            <select id="category-y" name="category_y" class="store-mm-select" required disabled>
                                <option value="">Select main category first</option>
                            </select>
                            <div class="store-mm-hint">Sub-category of your selected main category</div>
                        </div>
                    </div>
                    
                    <div class="store-mm-nda-section">
                        <div class="store-mm-nda-header">
                            <h3><span class="store-mm-icon">📄</span> Design Submission Agreement</h3>
                        </div>
                        <div class="store-mm-nda-text">
                            <?php echo nl2br(esc_html($nda_text)); ?>
                        </div>
                        <div class="store-mm-form-group">
                            <label class="store-mm-checkbox-container">
                                <input type="checkbox" id="agree-nda" name="agree_nda" value="1" required>
                                <span class="store-mm-checkbox-checkmark"></span>
                                <span class="store-mm-checkbox-label">
                                    I have read, understood, and agree to the Design Submission & Preliminary Disclosure Agreement above. *
                                </span>
                            </label>
                            <div class="store-mm-error-message" id="nda-error" style="display: none;">
                                You must agree to the terms to proceed.
                            </div>
                        </div>
                    </div>
                    
                    <div class="store-mm-tab-actions">
                        <button type="button" class="store-mm-button store-mm-button-secondary store-mm-button-cancel">
                            Cancel
                        </button>
                        <button type="button" class="store-mm-button store-mm-button-primary store-mm-next-tab" data-next="2">
                            Next: Upload Files →
                        </button>
                    </div>
                </div>
                
                <div class="store-mm-tab" id="tab-2">
                    <div class="store-mm-tab-header">
                        <h2><span class="store-mm-icon">🖼️</span> Design Files & Images</h2>
                        <p class="store-mm-tab-description">Upload visual references and manufacturing files</p>
                    </div>
                    
                    <div class="store-mm-upload-section">
                        <div class="store-mm-upload-header">
                            <h3>Product Pictures *</h3>
                            <p class="store-mm-upload-description">Upload high-quality images of your product (min. 1, max. 5)</p>
                        </div>
                        <div class="store-mm-upload-zone" id="image-upload-zone">
                            <div class="store-mm-upload-placeholder">
                                <span class="store-mm-upload-icon">📷</span>
                                <p>Drag & drop product images here</p>
                                <p class="store-mm-upload-subtext">or click to browse (JPG, PNG, max 5MB each)</p>
                            </div>
                        </div>
                        <input type="file" id="product-images" name="product_images[]" multiple accept="image/*" style="display: none;">
                        <div class="store-mm-upload-preview" id="image-preview"></div>
                        <div class="store-mm-upload-info">
                            <p><strong>Requirements:</strong></p>
                            <ul>
                                <li>Minimum 1 image, maximum 5 images</li>
                                <li>Recommended: 1200×1200 pixels or larger</li>
                                <li>Show your product from different angles</li>
                                <li>Clear background preferred</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="store-mm-upload-section">
                        <div class="store-mm-upload-header">
                            <h3>Manufacturing & Design Files (Optional)</h3>
                            <p class="store-mm-upload-description">Upload 3D models, CAD files, or technical drawings</p>
                        </div>
                        <div class="store-mm-upload-zone" id="file-upload-zone">
                            <div class="store-mm-upload-placeholder">
                                <span class="store-mm-upload-icon">📎</span>
                                <p>Drag & drop design files here</p>
                                <p class="store-mm-upload-subtext">or click to browse (STL, OBJ, STEP, 3MF, max 50MB each)</p>
                            </div>
                        </div>
                        <input type="file" id="design-files" name="design_files[]" multiple style="display: none;">
                        <div class="store-mm-upload-preview" id="file-preview"></div>
                        <div class="store-mm-upload-info">
                            <p><strong>Accepted formats:</strong></p>
                            <ul>
                                <li>3D Models: STL, OBJ, 3MF, STEP, IGES</li>
                                <li>CAD Files: SLDPRT, F3D, SKP, DWG</li>
                                <li>Documents: PDF, DOC, TXT (for instructions)</li>
                                <li>Maximum 5 files, 50MB each</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="store-mm-tab-actions">
                        <button type="button" class="store-mm-button store-mm-button-secondary store-mm-prev-tab" data-prev="1">
                            ← Back to Design Details
                        </button>
                        <button type="button" class="store-mm-button store-mm-button-primary store-mm-next-tab" data-next="3">
                            Next: Pricing & Submit →
                        </button>
                    </div>
                </div>
                
                <div class="store-mm-tab" id="tab-3">
                    <div class="store-mm-tab-header">
                        <h2><span class="store-mm-icon">💰</span> Pricing & Royalty</h2>
                        <p class="store-mm-tab-description">Set your estimated price and royalty percentage</p>
                    </div>
                    
                    <div class="store-mm-royalty-section">
                        <div class="store-mm-royalty-header">
                            <h3>Royalty Agreement</h3>
                            <div class="store-mm-royalty-badge">Standard Rate: 10%</div>
                        </div>
                        
                        <div class="store-mm-royalty-option">
                            <div class="store-mm-form-group">
                                <label class="store-mm-radio-container">
                                    <input type="radio" name="royalty_option" value="standard" checked>
                                    <span class="store-mm-radio-checkmark"></span>
                                    <span class="store-mm-radio-label">
                                        <strong>Accept Standard 10% Royalty</strong>
                                        <span class="store-mm-radio-description">
                                            Receive 10% of each sale. Fastest approval process.
                                        </span>
                                    </span>
                                </label>
                            </div>
                            
                            <div class="store-mm-form-group">
                                <label class="store-mm-radio-container">
                                    <input type="radio" name="royalty_option" value="custom">
                                    <span class="store-mm-radio-checkmark"></span>
                                    <span class="store-mm-radio-label">
                                        <strong>Request Custom Royalty Rate</strong>
                                        <span class="store-mm-radio-description">
                                            Request a different percentage (requires manual review)
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="store-mm-custom-royalty" id="custom-royalty-field" style="display: none;">
                            <div class="store-mm-form-group">
                                <label for="custom-royalty" class="store-mm-label">
                                    <span class="store-mm-label-text">Requested Royalty Percentage *</span>
                                </label>
                                <div class="store-mm-input-with-suffix">
                                    <input type="number" id="custom-royalty" name="custom_royalty" 
                                           class="store-mm-input" min="1" max="50" step="0.1" 
                                           placeholder="Enter percentage">
                                    <span class="store-mm-input-suffix">%</span>
                                </div>
                                <div class="store-mm-hint">Enter a value between 1% and 50%. Higher rates require justification.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="store-mm-price-section">
                        <div class="store-mm-form-group">
                            <label for="estimated-price" class="store-mm-label">
                                <span class="store-mm-label-text">Estimated Selling Price *</span>
                                <span class="store-mm-required">Required</span>
                            </label>
                            <div class="store-mm-input-with-prefix">
                                <span class="store-mm-input-prefix">$</span>
                                <input type="number" id="estimated-price" name="estimated_price" 
                                       class="store-mm-input" required min="0.01" step="0.01" 
                                       placeholder="0.00">
                            </div>
                            <div class="store-mm-hint">
                                Estimate the final selling price to customers. Consider manufacturing costs, platform fees, and your royalty.
                            </div>
                        </div>
                        
                        <div class="store-mm-price-preview">
                            <div class="store-mm-price-row">
                                <span>Your royalty (10%):</span>
                                <span class="store-mm-price-value" id="royalty-preview">$0.00</span>
                            </div>
                            <div class="store-mm-price-row">
                                <span>Manufacturing & platform costs:</span>
                                <span class="store-mm-price-value" id="costs-preview">$0.00</span>
                            </div>
                            <div class="store-mm-price-row store-mm-price-total">
                                <span>Customer pays:</span>
                                <span class="store-mm-price-value" id="total-price">$0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="store-mm-terms-section">
                        <div class="store-mm-form-group">
                            <label class="store-mm-checkbox-container">
                                <input type="checkbox" id="confirm-terms" name="confirm_terms" value="1" required>
                                <span class="store-mm-checkbox-checkmark"></span>
                                <span class="store-mm-checkbox-label">
                                    I confirm that all information provided is accurate and I own the rights to this design.
                                </span>
                            </label>
                        </div>
                        
                        <div class="store-mm-form-group">
                            <label class="store-mm-checkbox-container">
                                <input type="checkbox" id="confirm-review" name="confirm_review" value="1" required>
                                <span class="store-mm-checkbox-checkmark"></span>
                                <span class="store-mm-checkbox-label">
                                    I understand that my design will be reviewed and may require changes before approval.
                                </span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="store-mm-tab-actions">
                        <button type="button" class="store-mm-button store-mm-button-secondary store-mm-prev-tab" data-prev="2">
                            ← Back to Upload Files
                        </button>
                        <button type="submit" class="store-mm-button store-mm-button-submit" id="submit-design">
                            <span class="store-mm-submit-icon">🚀</span>
                            Submit Design for Review
                        </button>
                    </div>
                    
                    <div class="store-mm-submission-notice">
                        <p><strong>What happens next?</strong></p>
                        <ol>
                            <li>Your design will be reviewed by our team (2-3 business days)</li>
                            <li>If approved, it moves to prototyping phase</li>
                            <li>Once prototyping is complete, your product will be published in the store</li>
                            <li>You'll receive email notifications at each stage</li>
                        </ol>
                    </div>
                </div>
            </form>
            
            <div class="store-mm-modal" id="success-modal" style="display: none;">
                <div class="store-mm-modal-content">
                    <div class="store-mm-modal-icon">🎉</div>
                    <h3 class="store-mm-modal-title">Design Submitted Successfully!</h3>
                    <p class="store-mm-modal-message" id="success-message"></p>
                    <div class="store-mm-modal-actions">
                        <a href="#" class="store-mm-button store-mm-button-primary" id="submit-another-btn">Submit Another Design</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    
    return ob_get_clean();
}