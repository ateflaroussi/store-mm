
<?php
/**
 * Store MM - Store Grid Shortcode
 * Shortcode: [store_mm_store_grid]
 * Displays approved products in the Store category
 */

if (!defined('ABSPATH')) exit;

// Register shortcode
add_shortcode('store_mm_store_grid', 'store_mm_render_store_grid');

// AJAX handler for pagination/filtering
add_action('wp_ajax_store_mm_get_store_products', 'store_mm_ajax_get_store_products');
add_action('wp_ajax_nopriv_store_mm_get_store_products', 'store_mm_ajax_get_store_products');

/**
 * Render store grid shortcode
 */
function store_mm_render_store_grid($atts) {
    // Shortcode attributes
    $atts = shortcode_atts([
        'category' => 'all',
        'designer' => 'all',
        'per_page' => 12,
        'columns' => 3,
        'show_filter' => 'yes'
    ], $atts, 'store_mm_store_grid');
    
    // Enqueue styles and scripts
    store_mm_enqueue_store_grid_assets();
    
    // Get store product page URL if exists
    $store_product_page = get_page_by_path('store-product');
    $store_product_url = $store_product_page ? get_permalink($store_product_page->ID) : false;
    
    ob_start();
    ?>
    <div class="store-mm-store-grid-container" id="store-mm-store-grid">
        <div class="store-mm-store-header">
            <h1 class="store-mm-store-title"><?php _e('Manufacturing Marketplace', 'store-mm'); ?></h1>
            <p class="store-mm-store-description"><?php _e('Browse unique designs available for manufacturing', 'store-mm'); ?></p>
        </div>
        
        <?php if ($atts['show_filter'] === 'yes'): ?>
        <div class="store-mm-store-filters">
            <div class="store-mm-filter-group">
                <label for="store-mm-grid-category-filter"><?php _e('Category:', 'store-mm'); ?></label>
                <select id="store-mm-grid-category-filter" class="store-mm-filter-select">
                    <option value="all"><?php _e('All Categories', 'store-mm'); ?></option>
                    <!-- Will be populated via AJAX -->
                </select>
            </div>
            
            <div class="store-mm-filter-group">
                <label for="store-mm-grid-sort"><?php _e('Sort by:', 'store-mm'); ?></label>
                <select id="store-mm-grid-sort" class="store-mm-filter-select">
                    <option value="newest"><?php _e('Newest First', 'store-mm'); ?></option>
                    <option value="oldest"><?php _e('Oldest First', 'store-mm'); ?></option>
                    <option value="price_low"><?php _e('Price: Low to High', 'store-mm'); ?></option>
                    <option value="price_high"><?php _e('Price: High to Low', 'store-mm'); ?></option>
                    <option value="popular"><?php _e('Most Popular', 'store-mm'); ?></option>
                </select>
            </div>
            
            <div class="store-mm-filter-group">
                <input type="text" id="store-mm-grid-search" class="store-mm-search-input" placeholder="<?php _e('Search products...', 'store-mm'); ?>">
                <button id="store-mm-grid-apply-filters" class="store-mm-button store-mm-button-primary"><?php _e('Search', 'store-mm'); ?></button>
                <button id="store-mm-grid-reset-filters" class="store-mm-button store-mm-button-secondary"><?php _e('Reset', 'store-mm'); ?></button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Products Grid -->
        <div class="store-mm-products-grid" id="store-mm-products-grid" data-columns="<?php echo esc_attr($atts['columns']); ?>">
            <div class="store-mm-grid-loading">
                <div class="store-mm-spinner"></div>
                <span><?php _e('Loading products...', 'store-mm'); ?></span>
            </div>
        </div>
        
        <!-- Pagination -->
        <div class="store-mm-store-pagination">
            <span class="store-mm-pagination-info" id="store-mm-grid-pagination-info"><?php _e('Loading...', 'store-mm'); ?></span>
            <div class="store-mm-pagination-buttons">
                <button id="store-mm-grid-prev-page" class="store-mm-button store-mm-button-secondary" disabled><?php _e('Previous', 'store-mm'); ?></button>
                <button id="store-mm-grid-next-page" class="store-mm-button store-mm-button-secondary" disabled><?php _e('Next', 'store-mm'); ?></button>
            </div>
        </div>
        
        <div class="store-mm-store-notice">
            <div class="store-mm-notice-icon">⚠️</div>
            <div class="store-mm-notice-content">
                <h4><?php _e('Important Information', 'store-mm'); ?></h4>
                <p><?php _e('All products shown here are available for purchase. After ordering, our manufacturing team will produce and ship the physical product to you. No digital files are delivered to customers.', 'store-mm'); ?></p>
            </div>
        </div>
    </div>
    <?php
    
    // Localize script
    wp_localize_script('store-mm-store-grid', 'store_mm_store_grid', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('store_mm_store_grid_nonce'),
        'currency' => STORE_MM_CURRENCY_SYMBOL,
        'decimals' => STORE_MM_PRICE_DECIMALS,
        'per_page' => intval($atts['per_page']),
        'store_page_url' => $store_product_url, // Add store page URL
        'strings' => [
            'loading' => __('Loading products...', 'store-mm'),
            'no_products' => __('No products found.', 'store-mm'),
            'error' => __('An error occurred. Please try again.', 'store-mm'),
            'view_product' => __('View Product', 'store-mm'),
        ]
    ]);
    
    return ob_get_clean();
}

/**
 * AJAX handler for store products
 */
function store_mm_ajax_get_store_products() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_store_grid_nonce')) {
        wp_send_json_error('Security check failed.');
    }
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 12;
    $category = isset($_POST['category']) && $_POST['category'] !== 'all' ? intval($_POST['category']) : 0;
    $sort = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'newest';
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    // Get Store category
    $store_category = store_mm_get_store_category();
    if (!$store_category) {
        wp_send_json_error('Store category not found.');
    }
    
    // Build query args
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_store_mm_workflow_state',
                'value' => STORE_MM_STATE_APPROVED,
                'compare' => '=',
            ]
        ],
        'tax_query' => [
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $store_category->term_id,
                'include_children' => true,
            ]
        ],
    ];
    
    // Add category filter if specified
    if ($category) {
        $args['tax_query'][] = [
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => $category,
            'operator' => 'IN',
        ];
    }
    
    // Add search filter
    if ($search) {
        $args['s'] = $search;
    }
    
    // Apply sorting
    switch ($sort) {
        case 'oldest':
            $args['orderby'] = 'date';
            $args['order'] = 'ASC';
            break;
        case 'price_low':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price';
            $args['order'] = 'ASC';
            break;
        case 'price_high':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price';
            $args['order'] = 'DESC';
            break;
        case 'popular':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_store_mm_sales_count';
            $args['order'] = 'DESC';
            break;
        case 'newest':
        default:
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
    }
    
    // Execute query
    $query = new WP_Query($args);
    
    // Prepare products data
    $products = [];
    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        
        // Skip if not a valid product
        if (!$product) continue;
        
        // Get categories (skip "store" category in display)
        $categories = wp_get_post_terms($post->ID, 'product_cat');
        $category_names = [];
        $category_x = '';
        $category_y = '';
        
        foreach ($categories as $cat) {
            if ($cat->slug === 'store') continue;
            
            if ($cat->parent == $store_category->term_id) {
                $category_x = $cat->name;
            } elseif ($cat->parent && term_exists($cat->parent, 'product_cat')) {
                $parent = get_term($cat->parent, 'product_cat');
                if ($parent->parent == $store_category->term_id) {
                    $category_x = $parent->name;
                    $category_y = $cat->name;
                }
            }
        }
        
        // Get product image
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src();
        $image_alt = $image_id ? get_post_meta($image_id, '_wp_attachment_image_alt', true) : $product->get_title();
        
        // Get price
        $price = $product->get_price();
        $formatted_price = store_mm_format_price($price);
        
        // Get product URL
        $product_url = get_permalink($post->ID);
        
        $products[] = [
            'id' => $post->ID,
            'title' => $product->get_title(),
            'excerpt' => wp_trim_words($product->get_short_description() ?: $product->get_description(), 20),
            'image_url' => $image_url,
            'image_alt' => $image_alt,
            'price' => $price,
            'price_display' => $formatted_price,
            'category_x' => $category_x,
            'category_y' => $category_y,
            'url' => $product_url,
            'sales_count' => get_post_meta($post->ID, '_store_mm_sales_count', true) ?: 0,
            'date' => get_the_date('F j, Y', $post->ID),
        ];
    }
    
    // Get categories for filter (Category X only)
    $filter_categories = [];
    $category_x_terms = store_mm_get_category_x_options();
    
    foreach ($category_x_terms as $cat) {
        $filter_categories[] = [
            'id' => $cat->term_id,
            'name' => $cat->name,
            'count' => $cat->count,
        ];
    }
    
    wp_send_json_success([
        'products' => $products,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $query->max_num_pages,
            'total_items' => $query->found_posts,
        ],
        'categories' => $filter_categories,
    ]);
}

/**
 * Enqueue store grid assets
 */
function store_mm_enqueue_store_grid_assets() {
    wp_enqueue_style(
        'store-mm-store-grid',
        STORE_MM_PLUGIN_URL . 'assets/css/store-grid.css',
        [],
        STORE_MM_VERSION
    );
    
    wp_enqueue_script(
        'store-mm-store-grid',
        STORE_MM_PLUGIN_URL . 'assets/js/store-grid.js',
        ['jquery'],
        STORE_MM_VERSION,
        true
    );
}

/**
 * Get categories for filter dropdown (AJAX)
 */
add_action('wp_ajax_store_mm_get_grid_categories', 'store_mm_ajax_get_grid_categories');
add_action('wp_ajax_nopriv_store_mm_get_grid_categories', 'store_mm_ajax_get_grid_categories');

function store_mm_ajax_get_grid_categories() {
    if (!wp_verify_nonce($_POST['nonce'], 'store_mm_store_grid_nonce')) {
        wp_send_json_error('Security check failed.');
    }
    
    $store_category = store_mm_get_store_category();
    $categories = [];
    
    if ($store_category) {
        $category_x_terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
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