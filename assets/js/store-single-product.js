// Store MM Store Single Product JavaScript - Completely Fixed
jQuery(function($){
    $(document).ready(function() {
        // ========== FIXED: Gallery Functionality ==========
        initGallery();
        
        function initGallery() {
            const $slides = $('.store-mm-gallery-slide');
            const $thumbs = $('.store-mm-gallery-thumb');
            
            if ($slides.length === 0) return;
            
            // Initialize gallery
            $slides.hide().removeClass('active');
            $slides.first().show().addClass('active');
            
            // Thumbnail click handler
            $thumbs.on('click', function() {
                const index = $(this).data('index');
                showSlide(index);
            });
            
            // Navigation arrow handlers
            $('.store-mm-nav-prev').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const currentIndex = parseInt($('.store-mm-gallery-slide.active').data('index') || 0);
                const prevIndex = currentIndex > 0 ? currentIndex - 1 : $slides.length - 1;
                showSlide(prevIndex);
            });
            
            $('.store-mm-nav-next').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const currentIndex = parseInt($('.store-mm-gallery-slide.active').data('index') || 0);
                const nextIndex = currentIndex < $slides.length - 1 ? currentIndex + 1 : 0;
                showSlide(nextIndex);
            });
            
            // Thumbnail scroll buttons - Improved smooth scrolling
            $('.store-mm-thumbs-scroll-left').on('click', function() {
                const $container = $('#store-mm-gallery-thumbs');
                const scrollAmount = 220; // Scroll by approximately 2 thumbnails
                $container.animate({ 
                    scrollLeft: $container.scrollLeft() - scrollAmount 
                }, 400, 'swing');
            });
            
            $('.store-mm-thumbs-scroll-right').on('click', function() {
                const $container = $('#store-mm-gallery-thumbs');
                const scrollAmount = 220; // Scroll by approximately 2 thumbnails
                $container.animate({ 
                    scrollLeft: $container.scrollLeft() + scrollAmount 
                }, 400, 'swing');
            });
            
            // Update scroll button visibility
            function updateScrollButtons() {
                const $container = $('#store-mm-gallery-thumbs');
                const $leftBtn = $('.store-mm-thumbs-scroll-left');
                const $rightBtn = $('.store-mm-thumbs-scroll-right');
                
                if ($container.length) {
                    const scrollLeft = $container.scrollLeft();
                    const maxScroll = $container[0].scrollWidth - $container[0].clientWidth;
                    
                    // Hide/show left button
                    if (scrollLeft <= 5) {
                        $leftBtn.css('opacity', '0.3').css('pointer-events', 'none');
                    } else {
                        $leftBtn.css('opacity', '1').css('pointer-events', 'auto');
                    }
                    
                    // Hide/show right button
                    if (scrollLeft >= maxScroll - 5) {
                        $rightBtn.css('opacity', '0.3').css('pointer-events', 'none');
                    } else {
                        $rightBtn.css('opacity', '1').css('pointer-events', 'auto');
                    }
                }
            }
            
            // Update button visibility on scroll
            $('#store-mm-gallery-thumbs').on('scroll', updateScrollButtons);
            
            // Initial update
            setTimeout(updateScrollButtons, 100);
            
            // Keyboard navigation
            $(document).on('keydown', function(e) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    const currentIndex = parseInt($('.store-mm-gallery-slide.active').data('index') || 0);
                    const totalSlides = $slides.length;
                    
                    if (e.key === 'ArrowLeft') {
                        const prevIndex = currentIndex > 0 ? currentIndex - 1 : totalSlides - 1;
                        showSlide(prevIndex);
                    } else if (e.key === 'ArrowRight') {
                        const nextIndex = currentIndex < totalSlides - 1 ? currentIndex + 1 : 0;
                        showSlide(nextIndex);
                    }
                }
            });
            
            // Show more thumbnails button
            if ($thumbs.length > 8) {
                const $thumbsContainer = $('.store-mm-gallery-thumbs');
                $thumbsContainer.after(
                    '<button type="button" class="store-mm-show-more-thumbs">' + 
                    'Show all photos (' + $thumbs.length + ')' +
                    '</button>'
                );
                
                $('.store-mm-show-more-thumbs').on('click', function() {
                    const $this = $(this);
                    const $thumbsContainer = $('.store-mm-gallery-thumbs');
                    
                    if ($thumbsContainer.hasClass('expanded')) {
                        $thumbsContainer.removeClass('expanded');
                        $this.text('Show all photos (' + $thumbs.length + ')');
                    } else {
                        $thumbsContainer.addClass('expanded');
                        $this.text('Show less');
                    }
                });
            }
        }
        
        function showSlide(index) {
            const $slides = $('.store-mm-gallery-slide');
            const $thumbs = $('.store-mm-gallery-thumb');
            
            if (index < 0 || index >= $slides.length) return;
            
            // Hide all slides and remove active class
            $slides.removeClass('active').fadeOut(200);
            
            // Remove active class from all thumbs
            $thumbs.removeClass('active');
            
            // Show selected slide with fade effect
            $slides.eq(index).addClass('active').fadeIn(300);
            
            // Add active class to selected thumb
            $thumbs.eq(index).addClass('active');
            
            // Scroll to active thumb
            const $activeThumb = $thumbs.eq(index);
            const $thumbsContainer = $('#store-mm-gallery-thumbs');
            if ($thumbsContainer.length && $thumbsContainer[0].scrollWidth > $thumbsContainer[0].clientWidth) {
                const thumbOffset = $activeThumb.position().left;
                const containerWidth = $thumbsContainer.width();
                const thumbWidth = $activeThumb.outerWidth();
                
                $thumbsContainer.animate({
                    scrollLeft: $thumbsContainer.scrollLeft() + thumbOffset - (containerWidth / 2) + (thumbWidth / 2)
                }, 300);
            }
        }
        
        // ========== FIXED: Quantity Controls ==========
        function initQuantityControls() {
            // Update button states based on current value
            function updateButtonStates($input) {
                const currentVal = parseInt($input.val()) || 1;
                const min = parseInt($input.attr('min')) || 1;
                const max = parseInt($input.attr('max')) || 9999;
                const $controls = $input.closest('.store-mm-quantity-controls');
                const $minusBtn = $controls.find('.store-mm-quantity-minus');
                const $plusBtn = $controls.find('.store-mm-quantity-plus');
                
                // Disable minus button at minimum
                if (currentVal <= min) {
                    $minusBtn.prop('disabled', true);
                } else {
                    $minusBtn.prop('disabled', false);
                }
                
                // Disable plus button at maximum
                if (currentVal >= max) {
                    $plusBtn.prop('disabled', true);
                } else {
                    $plusBtn.prop('disabled', false);
                }
            }
            
            // Quantity minus button
            $(document).on('click', '.store-mm-quantity-minus', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const $input = $(this).closest('.store-mm-quantity-controls').find('.store-mm-quantity-input');
                let currentVal = parseInt($input.val()) || 1;
                const min = parseInt($input.attr('min')) || 1;
                const step = parseInt($input.attr('step')) || 1;
                
                // Calculate new value
                const newVal = currentVal - step;
                if (newVal >= min) {
                    $input.val(newVal).trigger('change');
                    updateButtonStates($input);
                }
            });
            
            // Quantity plus button
            $(document).on('click', '.store-mm-quantity-plus', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const $input = $(this).closest('.store-mm-quantity-controls').find('.store-mm-quantity-input');
                let currentVal = parseInt($input.val()) || 1;
                const max = parseInt($input.attr('max')) || 9999;
                const step = parseInt($input.attr('step')) || 1;
                
                // Calculate new value
                const newVal = currentVal + step;
                if (newVal <= max) {
                    $input.val(newVal).trigger('change');
                    updateButtonStates($input);
                }
            });
            
            // Direct input validation
            $(document).on('change input', '.store-mm-quantity-input', function() {
                const $input = $(this);
                let currentVal = parseInt($input.val());
                const min = parseInt($input.attr('min')) || 1;
                const max = parseInt($input.attr('max')) || 9999;
                
                // Validate input
                if (isNaN(currentVal) || currentVal < min) {
                    $input.val(min);
                    currentVal = min;
                } else if (currentVal > max) {
                    $input.val(max);
                    currentVal = max;
                }
                
                // Update button states
                updateButtonStates($input);
                
                // Update WooCommerce hidden input if it exists
                const $form = $input.closest('form');
                if ($form.length) {
                    const $wooInput = $form.find('input[name="quantity"]');
                    if ($wooInput.length && $wooInput[0] !== $input[0]) {
                        $wooInput.val($input.val());
                    }
                }
            });
            
            // Initialize with correct value and button states
            $('.store-mm-quantity-input').each(function() {
                const $input = $(this);
                const value = parseInt($input.val()) || 1;
                $input.val(value);
                updateButtonStates($input);
            });
        }
        
        // Initialize quantity controls
        initQuantityControls();
        
        // ========== FIXED: Add to Cart Form Submission ==========
        $(document).on('submit', '.store-mm-cart-form', function(e) {
            const $form = $(this);
            const $button = $form.find('.store-mm-add-to-cart-button');
            const $quantityInput = $form.find('.store-mm-quantity-input');
            
            // Validate quantity
            const quantity = parseInt($quantityInput.val()) || 1;
            const min = parseInt($quantityInput.attr('min')) || 1;
            const max = parseInt($quantityInput.attr('max')) || 9999;
            
            if (quantity < min || quantity > max) {
                e.preventDefault();
                alert('Please enter a valid quantity between ' + min + ' and ' + max);
                return false;
            }
            
            // Ensure WooCommerce quantity is updated
            $form.find('input[name="quantity"]').val(quantity);
            
            // Add loading state
            $button.addClass('store-mm-loading').prop('disabled', true).html(
                '<span class="store-mm-button-spinner"></span> Adding to cart...'
            );
            
            // Submit via AJAX for better UX
            e.preventDefault();
            
            const formData = $form.serialize();
            
            $.ajax({
                url: wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart'),
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.error) {
                        alert(response.error);
                        $button.removeClass('store-mm-loading').prop('disabled', false).html(
                            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                            '<circle cx="9" cy="21" r="1"></circle>' +
                            '<circle cx="20" cy="21" r="1"></circle>' +
                            '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>' +
                            '</svg>' +
                            'Order Now'
                        );
                    } else {
                        // Success - redirect to cart or show success message
                        if (wc_add_to_cart_params.cart_redirect_after_add === 'yes') {
                            window.location.href = wc_add_to_cart_params.cart_url;
                        } else {
                            // Trigger cart update event
                            $(document.body).trigger('wc_fragment_refresh');
                            
                            // Show success message
                            $button.html('✓ Added to Cart!');
                            setTimeout(function() {
                                $button.removeClass('store-mm-loading').prop('disabled', false).html(
                                    '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                                    '<circle cx="9" cy="21" r="1"></circle>' +
                                    '<circle cx="20" cy="21" r="1"></circle>' +
                                    '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>' +
                                    '</svg>' +
                                    'Order Now'
                                );
                            }, 1500);
                        }
                    }
                },
                error: function() {
                    alert('Error adding to cart. Please try again.');
                    $button.removeClass('store-mm-loading').prop('disabled', false).html(
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                        '<circle cx="9" cy="21" r="1"></circle>' +
                        '<circle cx="20" cy="21" r="1"></circle>' +
                        '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>' +
                        '</svg>' +
                        'Order Now'
                    );
                }
            });
            
            return false;
        });
        
        // ========== FIXED: Share Functionality ==========
        $('.store-mm-share-button').on('click', function() {
            const type = $(this).data('type');
            const url = window.location.href;
            const title = document.title;
            
            let shareUrl = '';
            
            switch(type) {
                case 'instagram':
                    // Instagram doesn't support direct sharing
                    window.open('https://www.instagram.com/', '_blank');
                    break;
                    
                case 'pinterest':
                    const image = $('.store-mm-gallery-slide.active img').attr('src') || '';
                    shareUrl = 'https://pinterest.com/pin/create/button/' +
                              '?url=' + encodeURIComponent(url) +
                              '&media=' + encodeURIComponent(image) +
                              '&description=' + encodeURIComponent(title);
                    window.open(shareUrl, '_blank', 'width=750,height=650');
                    break;
                    
                case 'copy':
                    navigator.clipboard.writeText(url).then(function() {
                        const $button = $(this);
                        const originalHTML = $button.html();
                        
                        $button.html('<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>');
                        $button.css({
                            'background': '#10B981',
                            'border-color': '#10B981',
                            'color': '#fff'
                        });
                        
                        setTimeout(function() {
                            $button.html(originalHTML);
                            $button.css({
                                'background': '',
                                'border-color': '',
                                'color': ''
                            });
                        }, 2000);
                    }.bind(this));
                    break;
            }
        });
        
        // ========== Image Lightbox ==========
        $('.store-mm-gallery-image').on('click', function() {
            const src = $(this).attr('src');
            const alt = $(this).attr('alt') || '';
            
            const lightbox = $(
                '<div class="store-mm-lightbox">' +
                '<div class="store-mm-lightbox-overlay"></div>' +
                '<div class="store-mm-lightbox-content">' +
                '<button class="store-mm-lightbox-close">&times;</button>' +
                '<img src="' + src + '" alt="' + alt + '">' +
                '</div>' +
                '</div>'
            );
            
            $('body').append(lightbox);
            
            lightbox.on('click', '.store-mm-lightbox-overlay, .store-mm-lightbox-close', function() {
                lightbox.remove();
                $(document).off('keydown.lightbox');
            });
            
            $(document).on('keydown.lightbox', function(e) {
                if (e.key === 'Escape') {
                    lightbox.remove();
                    $(document).off('keydown.lightbox');
                }
            });
        });
        
        // ========== Responsive Adjustments ==========
        function adjustLayout() {
            const $thumbs = $('.store-mm-gallery-thumb');
            const $thumbsContainer = $('.store-mm-gallery-thumbs');
            
            if ($thumbs.length > 5 && window.innerWidth < 768) {
                $thumbsContainer.css('display', 'flex');
                $thumbs.css({
                    'flex': '0 0 auto',
                    'width': '80px',
                    'height': '80px',
                    'margin-right': '10px'
                });
            } else {
                $thumbsContainer.css('display', 'grid');
                $thumbs.css({
                    'flex': '',
                    'width': '',
                    'height': '',
                    'margin-right': ''
                });
            }
        }
        
        $(window).on('resize', adjustLayout);
        adjustLayout();
    });
});