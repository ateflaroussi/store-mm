<?php
/**
 * Store MM - Store Single Product Display Shortcode
 * Shortcode: [store_mm_single_product id=""]
 * Displays a single approved product from the Store category with custom layout
 */

if (!defined('ABSPATH')) exit;

// Register shortcode - Hook to init to avoid early loading
add_action('init', 'store_mm_register_shortcodes');
function store_mm_register_shortcodes() {
    add_shortcode('store_mm_single_product', 'store_mm_render_store_single_product');
}

// Register custom query var for product ID
add_filter('query_vars', 'store_mm_add_query_vars');
function store_mm_add_query_vars($vars) {
    $vars[] = 'store_mm_product_id';
    $vars[] = 'product_id'; // Add product_id for GET parameter support
    return $vars;
}

/**
 * Render store single product shortcode
 */
function store_mm_render_store_single_product($atts) {
    // Shortcode attributes
    $atts = shortcode_atts([
        'id' => 0,
    ], $atts, 'store_mm_single_product');
    
    // Try to get product ID in order of priority:
    $product_id = 0;
    
    // 1. Shortcode attribute (highest priority)
    if (!empty($atts['id'])) {
        $product_id = intval($atts['id']);
    }
    
    // 2. GET parameter (for direct links: /store-product/?product_id=123)
    if (!$product_id && isset($_GET['product_id'])) {
        $product_id = intval($_GET['product_id']);
    }
    
    // 3. Query var (for rewrite rule: /store/product/123/)
    if (!$product_id && get_query_var('store_mm_product_id')) {
        $product_id = intval(get_query_var('store_mm_product_id'));
    }
    
    // 4. Current product page
    if (!$product_id && is_singular('product')) {
        global $post;
        $product_id = $post->ID;
    }
    
    // If still no ID, show error
    if (!$product_id) {
        return '<div class="store-mm-error-box"><p>' . __('Please specify a product.', 'store-mm') . '</p></div>';
    }
    
    // Sanitize and validate product ID
    $product_id = absint($product_id);
    
    // Check if product exists
    $product = wc_get_product($product_id);
    if (!$product) {
        return '<div class="store-mm-error-box"><p>' . __('Product not found.', 'store-mm') . '</p></div>';
    }
    
    // Check if product is approved and in Store category
    $store_category = store_mm_get_store_category();
    $is_in_store_category = false;
    
    if ($store_category) {
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (in_array($store_category->term_id, $terms)) {
            $is_in_store_category = true;
        }
    }
    
    $workflow_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    // If product is not approved or not in Store category
    if ($workflow_state !== STORE_MM_STATE_APPROVED || !$is_in_store_category) {
        return '<div class="store-mm-error-box">
            <h3>' . __('Product Not Available', 'store-mm') . '</h3>
            <p>' . __('This product is not currently available for purchase.', 'store-mm') . '</p>
            <p><a href="' . home_url('/store') . '" class="store-mm-button store-mm-button-primary">' . __('Browse Available Products', 'store-mm') . '</a></p>
        </div>';
    }
    
    // Enqueue assets
    store_mm_enqueue_store_single_product_assets();
    
    ob_start();
    ?>
    <div class="store-mm-store-single-product-container" id="store-mm-product-<?php echo esc_attr($product_id); ?>">
        <?php store_mm_render_store_product_layout($product, $product_id); ?>
    </div>
    <?php
    
    return ob_get_clean();
}

/**
 * Render store product layout
 */
function store_mm_render_store_product_layout($product, $product_id) {
    // Get product data
    $title = $product->get_title();
    $description = $product->get_description();
    $short_description = $product->get_short_description();
    $price = $product->get_price();
    $formatted_price = store_mm_format_price($price);
    
    // Get categories
    $store_category = store_mm_get_store_category();
    $categories = wp_get_post_terms($product_id, 'product_cat');
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
    
    // Get gallery images
    $gallery_ids = $product->get_gallery_image_ids();
    $featured_id = $product->get_image_id();
    $all_image_ids = $featured_id ? array_merge([$featured_id], $gallery_ids) : $gallery_ids;
    
    // Get designer info
    $designer_id = get_post_meta($product_id, '_store_mm_designer_id', true);
    $designer = $designer_id ? get_user_by('id', $designer_id) : null;
    $designer_name = $designer ? $designer->display_name : __('Designer', 'store-mm');
    $designer_username = $designer ? $designer->user_login : '';
    
    // Set designer profile URL - FIXED: Use correct URL format
    $designer_profile_url = home_url('/designer-profile/' . $designer_username . '/');
    
    // Get designer avatar - FIXED: Use WordPress default avatar if UM not available
    $designer_avatar_url = get_avatar_url($designer_id, ['size' => 70]);
    $designer_role = '';
    $designer_verified = false;
    
    // Try to get Ultimate Member data safely
    if ($designer_id && function_exists('um_fetch_user')) {
        um_fetch_user($designer_id);
        
        // Get role if available
        if (function_exists('um_user')) {
            $designer_role = um_user('role');
        }
        
        // Get verified status
        $designer_verified = get_user_meta($designer_id, 'um_verified', true);
        um_reset_user();
    }
    
    // Get sales count
    $sales_count = get_post_meta($product_id, '_store_mm_sales_count', true) ?: 0;
    
    // Get store URL (where the grid is)
    $store_url = home_url('/store');
    
    // Get designer stats
    $designer_products = 0;
    $designer_approved = 0;
    
    if ($designer_id) {
        // Count all designer products
        $designer_products_args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'author' => $designer_id,
            'posts_per_page' => -1,
        ];
        $designer_products_query = new WP_Query($designer_products_args);
        $designer_products = $designer_products_query->found_posts;
        
        // Count approved designer products
        $designer_approved_args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'author' => $designer_id,
            'meta_query' => [
                [
                    'key' => '_store_mm_workflow_state',
                    'value' => STORE_MM_STATE_APPROVED,
                ]
            ],
            'posts_per_page' => -1,
        ];
        $designer_approved_query = new WP_Query($designer_approved_args);
        $designer_approved = $designer_approved_query->found_posts;
    }
    ?>
    
    <!-- Breadcrumb -->
    <div class="store-mm-breadcrumb">
        <a href="<?php echo esc_url($store_url); ?>"><?php _e('Store', 'store-mm'); ?></a>
        <?php if ($category_x): ?>
            <span class="store-mm-breadcrumb-separator">/</span>
            <a href="<?php echo esc_url(add_query_arg('category', urlencode($category_x), $store_url)); ?>"><?php echo esc_html($category_x); ?></a>
        <?php endif; ?>
        <?php if ($category_y): ?>
            <span class="store-mm-breadcrumb-separator">/</span>
            <span><?php echo esc_html($category_y); ?></span>
        <?php endif; ?>
    </div>
    
    <!-- Product Grid -->
    <div class="store-mm-product-grid">
        <!-- Gallery with Navigation -->
        <div class="store-mm-product-gallery">
            <?php if (!empty($all_image_ids)): ?>
                <div class="store-mm-gallery-main">
                    <?php foreach ($all_image_ids as $index => $image_id): ?>
                        <div class="store-mm-gallery-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo esc_attr($index); ?>">
                            <?php echo wp_get_attachment_image($image_id, 'full', false, [
                                'class' => 'store-mm-gallery-image',
                                'data-index' => $index
                            ]); ?>
                            
                            <!-- Navigation Arrows - FIXED: Larger buttons -->
                            <div class="store-mm-gallery-nav">
                                <button type="button" class="store-mm-nav-prev" aria-label="<?php esc_attr_e('Previous image', 'store-mm'); ?>">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3">
                                        <path d="M15 18l-6-6 6-6"/>
                                    </svg>
                                </button>
                                <button type="button" class="store-mm-nav-next" aria-label="<?php esc_attr_e('Next image', 'store-mm'); ?>">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3">
                                        <path d="M9 18l6-6-6-6"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Thumbnails - FIXED: Better layout -->
                <div class="store-mm-gallery-thumbs-container">
                    <div class="store-mm-gallery-thumbs" id="store-mm-gallery-thumbs">
                        <?php foreach ($all_image_ids as $index => $image_id): ?>
                            <div class="store-mm-gallery-thumb <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo esc_attr($index); ?>">
                                <?php echo wp_get_attachment_image($image_id, 'thumbnail', false, [
                                    'class' => 'store-mm-thumb-image'
                                ]); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($all_image_ids) > 4): ?>
                        <button type="button" class="store-mm-thumbs-scroll store-mm-thumbs-scroll-left" aria-label="<?php esc_attr_e('Scroll thumbnails left', 'store-mm'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M15 18l-6-6 6-6"/>
                            </svg>
                        </button>
                        <button type="button" class="store-mm-thumbs-scroll store-mm-thumbs-scroll-right" aria-label="<?php esc_attr_e('Scroll thumbnails right', 'store-mm'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="store-mm-gallery-placeholder">
                    <span class="store-mm-placeholder-icon">🖼️</span>
                    <p><?php _e('No images available', 'store-mm'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Product Info -->
        <div class="store-mm-product-info">
            <!-- Enhanced Designer Info -->
            <?php if ($designer_id): ?>
                <div class="store-mm-designer-enhanced">
                    <div class="store-mm-designer-avatar">
                        <img src="<?php echo esc_url($designer_avatar_url); ?>" 
                             alt="<?php echo esc_attr($designer_name); ?>" 
                             width="70" height="70">
                    </div>
                    
                    <div class="store-mm-designer-info">
                        <div class="store-mm-designer-name">
                            <?php echo esc_html($designer_name); ?>
                            <?php if ($designer_verified): ?>
                                <span class="store-mm-designer-verified" title="Verified Designer">✓</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($designer_role): ?>
                            <div class="store-mm-designer-role">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $designer_role))); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="store-mm-designer-stats">
                            <div class="store-mm-designer-stat">
                                <span class="store-mm-stat-value"><?php echo intval($designer_products); ?></span>
                                <span class="store-mm-stat-label"><?php _e('Designs', 'store-mm'); ?></span>
                            </div>
                            <div class="store-mm-designer-stat">
                                <span class="store-mm-stat-value"><?php echo intval($designer_approved); ?></span>
                                <span class="store-mm-stat-label"><?php _e('Published', 'store-mm'); ?></span>
                            </div>
                            <div class="store-mm-designer-stat">
                                <span class="store-mm-stat-value"><?php echo intval($sales_count); ?></span>
                                <span class="store-mm-stat-label"><?php _e('Orders', 'store-mm'); ?></span>
                            </div>
                        </div>
                        
                        <a href="<?php echo esc_url($designer_profile_url); ?>" 
                           class="store-mm-view-profile" target="_blank">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="8.5" cy="7" r="4"/>
                                <line x1="20" y1="8" x2="20" y2="14"/>
                                <line x1="23" y1="11" x2="17" y2="11"/>
                            </svg>
                            <?php _e('View Profile', 'store-mm'); ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Modern Product Header -->
            <div class="store-mm-product-header">
                <h1 class="store-mm-product-title"><?php echo esc_html($title); ?></h1>
                <div class="store-mm-product-meta">
                    <?php if ($category_x): ?>
                        <span class="store-mm-product-category">
                            <span class="store-mm-meta-label"><?php _e('Category:', 'store-mm'); ?></span>
                            <span class="store-mm-meta-value"><?php echo esc_html($category_x); ?></span>
                            <?php if ($category_y): ?>
                                <span class="store-mm-category-separator">→</span>
                                <span class="store-mm-meta-value"><?php echo esc_html($category_y); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    
                    <span class="store-mm-product-sales">
                        <span class="store-mm-meta-label"><?php _e('Orders:', 'store-mm'); ?></span>
                        <span class="store-mm-meta-value"><?php echo intval($sales_count); ?></span>
                    </span>
                </div>
            </div>
            
            <!-- Modern Price Display - FIXED: Less transparent -->
            <div class="store-mm-price-section">
                <div class="store-mm-price-display">
                    <span class="store-mm-price-currency"><?php echo STORE_MM_CURRENCY_SYMBOL; ?></span>
                    <span class="store-mm-price-value"><?php echo number_format(floatval($price), STORE_MM_PRICE_DECIMALS, '.', ''); ?></span>
                </div>
                <div class="store-mm-price-note">
                    <?php _e('Including all taxes • Free shipping on orders over 90 TND', 'store-mm'); ?>
                </div>
            </div>
            
            <!-- Modern Add to Cart Form - FIXED: Working quantity -->
            <div class="store-mm-add-to-cart-section">
                <?php if ($product->is_in_stock()): ?>
                    <?php if ($product->is_type('simple')): ?>
                        <form class="cart store-mm-cart-form" method="post" enctype='multipart/form-data'>
                            <?php do_action('woocommerce_before_add_to_cart_button'); ?>
                            
                            <!-- Hidden WooCommerce quantity input -->
                            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>">
                            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
                            
                            <div class="store-mm-quantity-container">
                                <label class="store-mm-quantity-label"><?php _e('Quantity:', 'store-mm'); ?></label>
                                <div class="store-mm-quantity-controls">
                                    <button type="button" class="store-mm-quantity-minus" aria-label="<?php esc_attr_e('Decrease quantity', 'store-mm'); ?>">−</button>
                                    <input type="number" 
                                           class="store-mm-quantity-input" 
                                           name="quantity" 
                                           value="1" 
                                           min="1" 
                                           max="<?php 
                                               $max_qty = $product->get_max_purchase_quantity();
                                               // Convert -1 (unlimited) to 9999 to avoid validation issues
                                               echo esc_attr($max_qty !== -1 ? $max_qty : 9999);
                                           ?>"
                                           step="1"
                                           aria-label="<?php esc_attr_e('Product quantity', 'store-mm'); ?>">
                                    <button type="button" class="store-mm-quantity-plus" aria-label="<?php esc_attr_e('Increase quantity', 'store-mm'); ?>">+</button>
                                </div>
                            </div>
                            
                            <button type="submit" class="store-mm-add-to-cart-button single_add_to_cart_button button alt">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="9" cy="21" r="1"></circle>
                                    <circle cx="20" cy="21" r="1"></circle>
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                                </svg>
                                <?php echo esc_html($product->single_add_to_cart_text()); ?>
                            </button>
                            
                            <?php do_action('woocommerce_after_add_to_cart_button'); ?>
                        </form>
                    <?php else: ?>
                        <p class="store-mm-notice"><?php _e('Please use the product page to configure and purchase this item.', 'store-mm'); ?></p>
                        <a href="<?php echo get_permalink($product_id); ?>" class="store-mm-button store-mm-button-primary"><?php _e('View Product Page', 'store-mm'); ?></a>
                    <?php endif; ?>
                    
                    <div class="store-mm-stock-info">
                        <?php if ($product->get_stock_quantity()): ?>
                            <div class="store-mm-stock-indicator">
                                <div class="store-mm-stock-bar" style="width: <?php echo min(100, ($product->get_stock_quantity() / 10) * 100); ?>%"></div>
                            </div>
                            <span class="store-mm-stock-text">
                                <?php echo sprintf(__('Only %s left in stock', 'store-mm'), '<strong>' . $product->get_stock_quantity() . '</strong>'); ?>
                            </span>
                        <?php elseif ($product->is_in_stock()): ?>
                            <span class="store-mm-stock-text"><?php _e('In stock', 'store-mm'); ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="store-mm-out-of-stock">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                        <?php _e('Temporarily out of stock', 'store-mm'); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($short_description): ?>
            <div class="store-mm-product-excerpt">
                <h3 class="store-mm-section-title"><?php _e('Product Overview', 'store-mm'); ?></h3>
                <div class="store-mm-excerpt-content">
                    <?php echo wpautop(esc_html($short_description)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($description): ?>
            <div class="store-mm-product-description">
                <h3 class="store-mm-section-title"><?php _e('Product Details', 'store-mm'); ?></h3>
                <div class="store-mm-description-content">
                    <?php echo wpautop(wp_kses_post($description)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Share Buttons -->
            <div class="store-mm-share-section">
                <h3 class="store-mm-section-title"><?php _e('Share this product', 'store-mm'); ?></h3>
                <div class="store-mm-share-buttons">
                    <button type="button" class="store-mm-share-button store-mm-share-instagram" data-type="instagram" title="<?php esc_attr_e('Share on Instagram', 'store-mm'); ?>">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                            <circle cx="12" cy="12" r="5"></circle>
                            <circle cx="18" cy="6" r="1"></circle>
                        </svg>
                    </button>
                    <button type="button" class="store-mm-share-button store-mm-share-pinterest" data-type="pinterest" title="<?php esc_attr_e('Share on Pinterest', 'store-mm'); ?>">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2c5.523 0 10 4.477 10 10 0 4.237-2.636 7.855-6.356 9.312-.097-.475-.184-1.205.039-1.723.2-.482.287-1.185.287-1.185s-.073-.058-.073-.141c0-1.335.773-2.332 1.734-2.332.818 0 1.213.615 1.213 1.352 0 .822-.524 2.048-.795 3.187-.226.96.478 1.743 1.422 1.743 1.706 0 3.019-1.799 3.019-4.395 0-2.298-1.652-3.906-4.013-3.906-2.732 0-4.337 2.049-4.337 4.165 0 .821.316 1.701.711 2.179.079.096.091.179.067.277-.073.303-.235.958-.266 1.093-.041.179-.132.217-.306.13-1.143-.533-1.858-2.2-1.858-3.542 0-2.884 2.093-5.534 6.038-5.534 3.169 0 5.632 2.258 5.632 5.279 0 3.148-1.985 5.684-4.739 5.684-.925 0-1.796-.481-2.094-1.049l-.569 2.168c-.206.786-.761 1.768-1.133 2.369-.85 1.433-2.049 2.608-3.256 3.065C6.462 21.879 4.343 22 2.188 21.998 2.12 21.998 2.06 21.994 2 21.985c1.168-.781 1.992-1.857 2.492-3.078.17-.413.288-.843.353-1.282.135-.91.402-2.84.402-2.84s-1.003.445-1.003 2.08c0 1.545.899 2.705 2.003 2.705.944 0 1.67-.796 1.67-1.945 0-1.072-.768-1.828-1.365-1.828-.455 0-.731.35-.731.748 0 .502.316 1.254.481 1.95.136.565.278 1.145.278 1.545 0 .57-.298 1.238-.765 1.238-.27 0-.527-.142-.675-.395C4.182 18.082 3.5 16.108 3.5 14.011c0-3.759 2.73-7.165 7.703-7.165 4.037 0 7.174 2.875 7.174 6.715 0 4.006-2.524 7.233-6.025 7.233-1.177 0-2.283-.614-2.66-1.339l-.723 2.759c-.257.987-.947 2.225-1.409 2.225-.111 0-.556-.268-.556-1.038 0-.971.52-2.086.52-2.086l.133-.566c.072-.323.041-.442-.226-.716-.63-.664-1.035-1.524-1.035-2.617 0-2.002 1.452-3.842 4.182-3.842 2.308 0 3.83 1.642 3.83 3.835 0 2.291-1.445 4.134-3.45 4.134-.673 0-1.306-.35-1.522-.778 0 0-.334 1.279-.414 1.584-.15.578-.555 1.216-.834 1.627 1.26.387 2.594.596 3.959.596 5.523 0 10-4.477 10-10S17.523 2 12 2z"></path>
                        </svg>
                    </button>
                    <button type="button" class="store-mm-share-button store-mm-share-copy" data-type="copy" title="<?php esc_attr_e('Copy link', 'store-mm'); ?>">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Important Notice -->
            <div class="store-mm-product-notice">
                <div class="store-mm-notice-icon">📦</div>
                <div class="store-mm-notice-content">
                    <h4><?php _e('Delivery Information', 'store-mm'); ?></h4>
                    <ul>
                        <li><?php _e('Ready-to-use product delivered to your door', 'store-mm'); ?></li>
                        <li><?php _e('Production time: 3-5 business days', 'store-mm'); ?></li>
                        <li><?php _e('Free shipping on orders over 90 TND', 'store-mm'); ?></li>
                        <li><?php _e('Secure payment & 30-day satisfaction guarantee', 'store-mm'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Enqueue store single product assets
 */
function store_mm_enqueue_store_single_product_assets() {
    wp_enqueue_style(
        'store-mm-store-single-product',
        STORE_MM_PLUGIN_URL . 'assets/css/store-single-product.css',
        [],
        STORE_MM_VERSION
    );
    
    wp_enqueue_script(
        'store-mm-store-single-product',
        STORE_MM_PLUGIN_URL . 'assets/js/store-single-product.js',
        ['jquery'],
        STORE_MM_VERSION,
        true
    );
    
    // Add custom CSS for all fixes
    wp_add_inline_style('store-mm-store-single-product', '
        /* ========== FIXED: Designer Profile Section ========== */
        .store-mm-designer-enhanced {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            border: 1px solid rgba(0, 0, 0, 0.08);
        }
        
        .store-mm-designer-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }
        
        .store-mm-designer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .store-mm-designer-info {
            flex: 1;
        }
        
        .store-mm-designer-name {
            font-size: 18px;
            font-weight: 700;
            color: #000;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .store-mm-designer-verified {
            color: #3B82F6;
            font-size: 16px;
        }
        
        .store-mm-designer-role {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .store-mm-designer-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .store-mm-designer-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        
        .store-mm-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #000;
        }
        
        .store-mm-stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .store-mm-view-profile {
            padding: 8px 20px;
            background: #000;
            color: white;
            border: none;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
        }
        
        .store-mm-view-profile:hover {
            background: #333;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        /* ========== FIXED: Price Section - Less Transparent ========== */
        .store-mm-price-section {
            padding: 30px;
            background: #000000;
            border-radius: 16px;
            color: white;
            margin: 30px 0;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .store-mm-price-display {
            display: flex;
            align-items: baseline;
            margin-bottom: 8px;
            gap: 8px;
        }
        
        .store-mm-price-currency {
            font-size: 24px;
            font-weight: 600;
            color: #fff;
            opacity: 0.9;
        }
        
        .store-mm-price-value {
            font-size: 48px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -1.5px;
            line-height: 1;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }
        
        .store-mm-price-note {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* ========== FIXED: Quantity Controls ========== */
        .store-mm-quantity-controls {
            display: flex;
            align-items: center;
            gap: 0;
            max-width: 220px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e5e5e5;
            transition: border-color 0.3s ease;
            margin-bottom: 20px;
        }
        
        .store-mm-quantity-controls:focus-within {
            border-color: #000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .store-mm-quantity-minus,
        .store-mm-quantity-plus {
            background: #f8f9fa;
            border: none;
            width: 60px;
            height: 60px;
            font-size: 28px;
            color: #000;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-weight: 300;
            user-select: none;
        }
        
        .store-mm-quantity-minus:hover,
        .store-mm-quantity-plus:hover {
            background: #000;
            color: white;
        }
        
        .store-mm-quantity-minus {
            border-right: 2px solid #e5e5e5;
        }
        
        .store-mm-quantity-plus {
            border-left: 2px solid #e5e5e5;
        }
        
        .store-mm-quantity-input {
            width: 100px;
            height: 60px;
            border: none;
            font-size: 20px;
            text-align: center;
            color: #000;
            background: white;
            font-weight: 600;
            -moz-appearance: textfield;
            padding: 0;
            margin: 0;
            line-height: 60px;
        }
        
        .store-mm-quantity-input::-webkit-outer-spin-button,
        .store-mm-quantity-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        /* ========== FIXED: Add to Cart Button ========== */
        .store-mm-add-to-cart-button {
            width: 100%;
            padding: 22px;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
            margin-top: 0;
        }
        
        .store-mm-add-to-cart-button:before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .store-mm-add-to-cart-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.4);
        }
        
        .store-mm-add-to-cart-button:hover:before {
            left: 100%;
        }
        
        .store-mm-add-to-cart-button:disabled,
        .store-mm-add-to-cart-button.store-mm-loading {
            background: #666;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .store-mm-button-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: store-mm-spin 1s linear infinite;
            margin-right: 10px;
        }
        
        /* ========== FIXED: Thumbnail Gallery ========== */
        .store-mm-gallery-thumbs-container {
            position: relative;
            margin-top: 20px;
        }
        
        .store-mm-gallery-thumbs {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            padding: 5px 0;
            overflow: hidden;
            max-height: 100px;
            transition: max-height 0.3s ease;
        }
        
        .store-mm-gallery-thumbs.expanded {
            max-height: 400px;
            overflow-y: auto;
            grid-template-columns: repeat(5, 1fr);
        }
        
        @media (max-width: 768px) {
            .store-mm-gallery-thumbs {
                grid-template-columns: repeat(4, 1fr);
            }
            .store-mm-gallery-thumbs.expanded {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .store-mm-gallery-thumbs {
                grid-template-columns: repeat(3, 1fr);
            }
            .store-mm-gallery-thumbs.expanded {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        .store-mm-gallery-thumb {
            width: 100%;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0.7;
            aspect-ratio: 1;
        }
        
        .store-mm-gallery-thumb:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .store-mm-gallery-thumb.active {
            border-color: #000;
            opacity: 1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .store-mm-thumb-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .store-mm-thumbs-scroll {
            display: none;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 10;
            align-items: center;
            justify-content: center;
        }
        
        .store-mm-thumbs-scroll-left {
            left: -15px;
        }
        
        .store-mm-thumbs-scroll-right {
            right: -15px;
        }
        
        /* Show scroll buttons on mobile when needed */
        @media (max-width: 768px) {
            .store-mm-gallery-thumbs {
                overflow-x: auto;
                display: flex;
                grid-template-columns: none;
                padding-bottom: 10px;
            }
            
            .store-mm-gallery-thumb {
                flex: 0 0 auto;
                width: 80px;
                height: 80px;
                margin-right: 10px;
            }
            
            .store-mm-thumbs-scroll {
                display: flex;
            }
        }
        
        /* Show more thumbnails button */
        .store-mm-show-more-thumbs {
            display: block;
            width: 100%;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            transition: all 0.3s ease;
        }
        
        .store-mm-show-more-thumbs:hover {
            background: #e9ecef;
            color: #000;
        }
        
        /* ========== FIXED: Gallery Navigation ========== */
        .store-mm-gallery-nav {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            padding: 0 25px;
            transform: translateY(-50%);
            z-index: 100;
        }
        
        .store-mm-nav-prev,
        .store-mm-nav-next {
            background: rgba(0, 0, 0, 0.9);
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .store-mm-nav-prev:hover,
        .store-mm-nav-next:hover {
            background: #000;
            transform: scale(1.1);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
            border-color: rgba(255, 255, 255, 0.3);
        }
        
        .store-mm-nav-prev svg,
        .store-mm-nav-next svg {
            width: 32px;
            height: 32px;
            stroke-width: 3;
        }
        
        /* ========== FIXED: Stock Info ========== */
        .store-mm-stock-info {
            margin-top: 15px;
            text-align: center;
        }
        
        .store-mm-stock-indicator {
            height: 6px;
            background: #e5e5e5;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .store-mm-stock-bar {
            height: 100%;
            background: linear-gradient(90deg, #10B981, #3B82F6);
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .store-mm-stock-text {
            font-size: 14px;
            color: #666;
        }
        
        .store-mm-stock-text strong {
            color: #000;
            font-weight: 700;
        }
        
        .store-mm-out-of-stock {
            background: rgba(227, 27, 35, 0.1);
            border: 1px solid #E31B23;
            color: #C0171D;
            padding: 20px;
            border-radius: 12px;
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .store-mm-notice {
            background: rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e5e5;
            color: #666;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        /* ========== FIXED: Modern Product Header ========== */
        .store-mm-product-header {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .store-mm-product-title {
            font-size: 42px;
            color: #000;
            margin: 0 0 20px 0;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -1px;
        }
        
        .store-mm-product-meta {
            display: flex;
            gap: 30px;
            font-size: 15px;
            color: #666;
            align-items: center;
            flex-wrap: wrap;
        }
        
        /* ========== Responsive Design ========== */
        @media (max-width: 1200px) {
            .store-mm-product-title {
                font-size: 36px;
            }
            
            .store-mm-price-value {
                font-size: 42px;
            }
        }
        
        @media (max-width: 992px) {
            .store-mm-product-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }
            
            .store-mm-product-title {
                font-size: 32px;
            }
            
            .store-mm-price-value {
                font-size: 36px;
            }
            
            .store-mm-nav-prev,
            .store-mm-nav-next {
                width: 50px;
                height: 50px;
            }
            
            .store-mm-designer-enhanced {
                flex-direction: column;
                text-align: center;
            }
            
            .store-mm-designer-stats {
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .store-mm-product-title {
                font-size: 28px;
            }
            
            .store-mm-price-value {
                font-size: 32px;
            }
            
            .store-mm-price-section {
                padding: 25px;
            }
            
            .store-mm-quantity-controls {
                max-width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .store-mm-product-title {
                font-size: 24px;
            }
            
            .store-mm-price-value {
                font-size: 28px;
            }
            
            .store-mm-price-currency {
                font-size: 20px;
            }
            
            .store-mm-add-to-cart-button {
                padding: 20px;
                font-size: 16px;
            }
            
            .store-mm-nav-prev,
            .store-mm-nav-next {
                width: 45px;
                height: 45px;
            }
            
            .store-mm-nav-prev svg,
            .store-mm-nav-next svg {
                width: 24px;
                height: 24px;
            }
        }
        
        @keyframes store-mm-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    ');
}

/**
 * Filter WooCommerce single product display for Store MM products
 */
add_filter('woocommerce_is_purchasable', 'store_mm_filter_product_purchasable', 10, 2);
function store_mm_filter_product_purchasable($purchasable, $product) {
    $product_id = $product->get_id();
    $workflow_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    // Only allow purchasing if product is approved
    if ($workflow_state && $workflow_state !== STORE_MM_STATE_APPROVED) {
        return false;
    }
    
    return $purchasable;
}

/**
 * Hide add to cart for non-approved products
 */
add_action('woocommerce_before_add_to_cart_form', 'store_mm_hide_add_to_cart_for_non_approved');
function store_mm_hide_add_to_cart_for_non_approved() {
    global $product;
    
    if (!$product) return;
    
    $product_id = $product->get_id();
    $workflow_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    if ($workflow_state && $workflow_state !== STORE_MM_STATE_APPROVED) {
        echo '<div class="store-mm-error-box" style="margin-bottom: 20px;">';
        echo '<h3>' . __('Product Not Available', 'store-mm') . '</h3>';
        echo '<p>' . __('This product is not currently available for purchase.', 'store-mm') . '</p>';
        echo '</div>';
        
        // Remove add to cart button
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }
}

/**
 * Add Store MM info to WooCommerce product pages
 */
add_action('woocommerce_product_meta_start', 'store_mm_add_product_meta_info');
function store_mm_add_product_meta_info() {
    global $product;
    
    if (!$product) return;
    
    $product_id = $product->get_id();
    $workflow_state = get_post_meta($product_id, '_store_mm_workflow_state', true);
    
    // Only show for Store MM products
    if (!$workflow_state) return;
    
    echo '<span class="store-mm-badge">' . __('Premium Product', 'store-mm') . '</span>';
}

/**
 * Add rewrite rules for store product pages
 */
add_action('init', 'store_mm_add_rewrite_rules');
function store_mm_add_rewrite_rules() {
    // Add rewrite rule for store product pages
    add_rewrite_rule(
        '^store/product/([0-9]+)/?$',
        'index.php?store_mm_product_id=$matches[1]',
        'top'
    );
    
    // Flush rewrite rules on plugin activation (handled elsewhere)
}

/**
 * Template redirect for custom store product URLs
 */
add_action('template_redirect', 'store_mm_template_redirect');
function store_mm_template_redirect() {
    $product_id = get_query_var('store_mm_product_id');
    
    if ($product_id) {
        // Get the store single product page
        $store_page = get_page_by_path('store-product');
        
        if ($store_page) {
            global $post;
            $post = $store_page;
            setup_postdata($post);
            
            // Include our custom template
            include STORE_MM_PLUGIN_DIR . 'templates/store-single-product-template.php';
            exit;
        }
    }
}

/**
 * Generate store product URL
 */
function store_mm_get_store_product_url($product_id) {
    // Check if store product page exists
    $store_page = get_page_by_path('store-product');
    
    if ($store_page) {
        // Use query parameter approach for now
        return add_query_arg('product_id', $product_id, get_permalink($store_page->ID));
    }
    
    // Fallback to standard product URL
    return get_permalink($product_id);
}