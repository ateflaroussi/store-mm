// Store MM Frontend Locking
jQuery(function($) {
    // Check if we're on a product edit page
    if ($('#store-mm-submission-form').length > 0) {
        // This is the submission form - we don't need locking here
        return;
    }
    
    // Check if we're viewing a product
    if ($('body').hasClass('single-product')) {
        const productId = $('input[name="add-to-cart"]').val() || $('input[name="product_id"]').val();
        if (productId) {
            checkProductLockStatus(productId);
        }
    }
    
    function checkProductLockStatus(productId) {
        $.ajax({
            url: store_mm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'store_mm_check_product_lock',
                nonce: store_mm_ajax.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success && response.data.locked) {
                    showLockNotice(response.data);
                }
            }
        });
    }
    
    function showLockNotice(data) {
        let noticeHtml = `
            <div class="store-mm-lock-notice">
                <h3><span class="store-mm-lock-icon">🔒</span> Design ${data.state_label}</h3>
                <div class="store-mm-state-indicator store-mm-state-${data.state}">
                    ${data.state_label}
                </div>
                <p>${data.message}</p>
        `;
        
        if (data.actions && data.actions.length > 0) {
            noticeHtml += `<div class="store-mm-lock-actions" style="margin-top: 15px;">`;
            data.actions.forEach(action => {
                noticeHtml += `<a href="${action.url}" class="button ${action.class}">${action.text}</a> `;
            });
            noticeHtml += `</div>`;
        }
        
        noticeHtml += `</div>`;
        
        // Insert notice at appropriate location
        if ($('.product_title').length > 0) {
            $(noticeHtml).insertAfter('.product_title');
        } else if ($('.woocommerce-product-details__short-description').length > 0) {
            $(noticeHtml).insertBefore('.woocommerce-product-details__short-description');
        } else {
            $(noticeHtml).prependTo('.product-summary');
        }
        
        // Lock add to cart button if not approved
        if (data.state !== 'approved') {
            $('.single_add_to_cart_button').prop('disabled', true)
                .css('opacity', '0.5')
                .css('cursor', 'not-allowed')
                .after('<div style="margin-top: 10px; color: #666; font-size: 14px;">This product is not yet available for purchase.</div>');
        }
    }
});

// Admin side locking
jQuery(function($) {
    if ($('body').hasClass('post-type-product') && window.location.search.includes('action=edit')) {
        const postId = $('#post_ID').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'store_mm_get_product_lock_status',
                nonce: store_mm_admin_ajax.nonce,
                product_id: postId
            },
            success: function(response) {
                if (response.success && response.data.locked) {
                    lockProductFields(response.data);
                }
            }
        });
    }
    
    function lockProductFields(data) {
        // Lock specific fields based on user role
        const userCanEdit = store_mm_user_can_edit_product(data.user_id, data.product_id);
        
        if (!userCanEdit) {
            // Lock title
            $('#title').prop('readonly', true)
                .addClass('store-mm-field-locked')
                .after('<div class="description" style="color: #666; margin-top: 5px;">This field is locked in the current workflow state.</div>');
            
            // Lock content editor
            $('#content').prop('readonly', true)
                .addClass('store-mm-field-locked');
            
            // Lock excerpt
            $('#excerpt').prop('readonly', true)
                .addClass('store-mm-field-locked');
            
            // Lock product gallery
            $('#product_images_container .add_product_images').hide();
            $('#product_images_container .remove_image').hide();
            
            // Show lock message
            const lockMessage = `
                <div class="notice notice-warning inline" style="margin: 15px 0;">
                    <p><strong>🔒 Product Locked:</strong> This design is in <strong>${data.state_label}</strong> state and cannot be edited.</p>
                    <p>Only administrators and moderators can make changes in this state.</p>
                </div>
            `;
            
            $('#titlediv').after(lockMessage);
        }
    }
});