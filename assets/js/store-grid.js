
// Store MM Store Grid JavaScript
jQuery(function($){
    let currentPage = 1;
    let filters = {
        category: 'all',
        sort: 'newest',
        search: ''
    };
    
    // Initialize
    loadStoreProducts();
    loadCategories();
    
    function loadStoreProducts() {
        const $grid = $('#store-mm-products-grid');
        const $loading = $grid.find('.store-mm-grid-loading');
        
        if (!$loading.length) {
            $grid.html('<div class="store-mm-grid-loading"><div class="store-mm-spinner"></div><span>' + store_mm_store_grid.strings.loading + '</span></div>');
        }
        
        $.ajax({
            url: store_mm_store_grid.ajax_url,
            type: 'POST',
            data: {
                action: 'store_mm_get_store_products',
                nonce: store_mm_store_grid.nonce,
                page: currentPage,
                per_page: store_mm_store_grid.per_page,
                category: filters.category,
                sort: filters.sort,
                search: filters.search
            },
            success: function(response) {
                if (response.success) {
                    renderProducts(response.data.products);
                    updatePagination(response.data.pagination);
                } else {
                    showError(store_mm_store_grid.strings.error);
                }
            },
            error: function() {
                showError(store_mm_store_grid.strings.error);
            }
        });
    }
    
    function loadCategories() {
        $.ajax({
            url: store_mm_store_grid.ajax_url,
            type: 'POST',
            data: {
                action: 'store_mm_get_grid_categories',
                nonce: store_mm_store_grid.nonce
            },
            success: function(response) {
                if (response.success) {
                    const $select = $('#store-mm-grid-category-filter');
                    $select.append('<option value="all">All Categories</option>');
                    
                    response.data.forEach(function(category) {
                        $select.append('<option value="' + category.id + '">' + category.name + ' (' + category.count + ')</option>');
                    });
                }
            }
        });
    }
    
    function renderProducts(products) {
        const $grid = $('#store-mm-products-grid');
        const columns = $grid.data('columns') || 3;
        
        if (!products.length) {
            $grid.html('<div class="store-mm-no-products">' +
                '<span class="store-mm-no-products-icon">📦</span>' +
                '<h3>' + store_mm_store_grid.strings.no_products + '</h3>' +
                '<p>No products found matching your criteria.</p>' +
                '<button class="store-mm-button store-mm-button-secondary" id="store-mm-clear-filters">Clear Filters</button>' +
                '</div>');
            return;
        }
        
        let html = '';
        products.forEach(function(product) {
            // Build category display
            let categoryDisplay = '';
            if (product.category_x) {
                categoryDisplay = product.category_x;
                if (product.category_y) {
                    categoryDisplay += ' → ' + product.category_y;
                }
            }
            
            // Get store product URL
            let productUrl = product.url; // Default to standard WooCommerce URL
            
            // Try to get store product page
            const storePage = window.store_mm_store_grid?.store_page_url || false;
            if (storePage) {
                // Use store product page with query parameter
                productUrl = storePage + '?product_id=' + product.id;
            }
            
            html += '<div class="store-mm-product-card">' +
                '<div class="store-mm-product-image">' +
                    '<img src="' + product.image_url + '" alt="' + product.image_alt + '" loading="lazy">' +
                    '<div class="store-mm-product-badge">3D Model</div>' + // CHANGED FROM "Manufactured"
                '</div>' +
                '<div class="store-mm-product-info">' +
                    '<h3 class="store-mm-product-title">' +
                        '<a href="' + productUrl + '">' + product.title + '</a>' +
                    '</h3>' +
                    '<div class="store-mm-product-category">' + categoryDisplay + '</div>' +
                    '<div class="store-mm-product-excerpt">' + product.excerpt + '</div>' +
                    '<div class="store-mm-product-price">' + product.price_display + '</div>' +
                    '<div class="store-mm-product-meta">' +
                        '<div class="store-mm-product-sales">' +
                            '<span class="store-mm-sales-icon">📊</span>' +
                            '<span>' + product.sales_count + ' orders</span>' +
                        '</div>' +
                        '<div class="store-mm-product-date">' + product.date + '</div>' +
                    '</div>' +
                    '<a href="' + productUrl + '" class="store-mm-button store-mm-button-primary store-mm-button-view">' +
                        store_mm_store_grid.strings.view_product +
                    '</a>' +
                '</div>' +
            '</div>';
        });
        
        $grid.html(html);
    }
    
    function updatePagination(pagination) {
        const $info = $('#store-mm-grid-pagination-info');
        const $prev = $('#store-mm-grid-prev-page');
        const $next = $('#store-mm-grid-next-page');
        
        const start = ((currentPage - 1) * store_mm_store_grid.per_page) + 1;
        const end = Math.min(currentPage * store_mm_store_grid.per_page, pagination.total_items);
        
        $info.text('Showing ' + start + ' - ' + end + ' of ' + pagination.total_items + ' products');
        $prev.prop('disabled', currentPage <= 1);
        $next.prop('disabled', currentPage >= pagination.total_pages);
    }
    
    function showError(message) {
        const $grid = $('#store-mm-products-grid');
        $grid.html('<div class="store-mm-no-products">' +
            '<span class="store-mm-no-products-icon">❌</span>' +
            '<h3>' + message + '</h3>' +
            '<p>Please try again or contact support if the problem persists.</p>' +
            '<button class="store-mm-button store-mm-button-primary" id="store-mm-retry-load">Retry</button>' +
            '</div>');
    }
    
    // Event Listeners (remain the same)
    $('#store-mm-grid-apply-filters').on('click', function() {
        filters.category = $('#store-mm-grid-category-filter').val();
        filters.sort = $('#store-mm-grid-sort').val();
        filters.search = $('#store-mm-grid-search').val();
        currentPage = 1;
        loadStoreProducts();
    });
    
    $('#store-mm-grid-reset-filters').on('click', function() {
        $('#store-mm-grid-category-filter').val('all');
        $('#store-mm-grid-sort').val('newest');
        $('#store-mm-grid-search').val('');
        filters = { category: 'all', sort: 'newest', search: '' };
        currentPage = 1;
        loadStoreProducts();
    });
    
    $('#store-mm-grid-prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadStoreProducts();
            $('html, body').animate({ scrollTop: $('#store-mm-store-grid').offset().top - 100 }, 300);
        }
    });
    
    $('#store-mm-grid-next-page').on('click', function() {
        currentPage++;
        loadStoreProducts();
        $('html, body').animate({ scrollTop: $('#store-mm-store-grid').offset().top - 100 }, 300);
    });
    
    // Handle Enter key in search
    $('#store-mm-grid-search').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            $('#store-mm-grid-apply-filters').click();
        }
    });
    
    // Handle dynamic events
    $(document).on('click', '#store-mm-clear-filters', function() {
        $('#store-mm-grid-reset-filters').click();
    });
    
    $(document).on('click', '#store-mm-retry-load', function() {
        loadStoreProducts();
    });
});