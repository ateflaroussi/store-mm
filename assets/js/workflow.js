// Store MM Frontend Workflow Management - Optimized
jQuery(function($){
    // Initialize based on page
    $('#store-mm-dev-products').length && initDevProducts();
    $('#store-mm-live-products').length && initLiveProducts();
    
    // Common handlers
    initModalHandlers();
    initFileUploadHandlers();
    
    function initDevProducts(){
        let currentPage=1, perPage=10, filters={state:'all',designer:'all',search:''};
        
        function loadDevProducts(){
            const $body=$('#store-mm-dev-products-body');
            const showDesigner=store_mm_workflow.user.is_admin||store_mm_workflow.user.is_moderator;
            $body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><div class="store-mm-spinner"></div><span>${store_mm_workflow.strings.loading}</span></td></tr>`);
            
            $.ajax({
                url:store_mm_workflow.ajax_url, type:'POST',
                data:{action:'store_mm_get_dev_products',nonce:store_mm_workflow.nonce,page:currentPage,per_page:perPage,state:filters.state,designer:filters.designer,search:filters.search},
                success:r=>r.success?renderDevProducts(r.data.products,r.data.pagination):$body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span style="color:#dc2626;">${store_mm_workflow.strings.error}</span></td></tr>`),
                error:()=>$body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span style="color:#dc2626;">Network error</span></td></tr>`)
            });
        }
        
        function renderDevProducts(products,p){
            const $body=$('#store-mm-dev-products-body');
            const showDesigner=store_mm_workflow.user.is_admin||store_mm_workflow.user.is_moderator;
            
            if(!products.length){
                $body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span>${store_mm_workflow.strings.no_products}</span></td></tr>`);
                return;
            }
            
            let html='';
            products.forEach(p=>{
                const stateClass=`state-${p.workflow_state.replace(/-/g,'_')}`;
                const stateLabel=p.workflow_state.replace(/_/g,' ');
                
                // Price display
                let priceDisplay=`${p.price_display}`;
                if(p.price_type==='proposed'){
                    priceDisplay=`<span style="color:#666; font-style:italic;">${p.price_display} (proposed)</span>`;
                }else if(p.price_type==='final'){
                    priceDisplay=`<strong>${p.price_display}</strong><br><small style="color:#666; font-size:11px;">Proposed: ${store_mm_workflow.currency} ${parseFloat(p.proposed_price||0).toFixed(store_mm_workflow.decimals)}</small>`;
                }
                
                // State badge with proposal indicator
                let stateBadge=`<span class="store-mm-state-badge ${stateClass}">${stateLabel}`;
                if(p.has_proposed_changes && p.workflow_state==='changes_requested'){
                    stateBadge+=`<br><small style="font-size:10px; color:#f59e0b;">Price proposal pending</small>`;
                }
                stateBadge+=`</span>`;
                
                // Actions
                let actionsHtml='';
                if(p.can_submit_changes) {
                    const btnText = p.has_proposed_changes ? 'Review & Confirm' : 'Submit Changes';
                    actionsHtml+=`<button class="store-mm-action-button action-submit submit-changes" data-id="${p.id}">${btnText}</button>`;
                }
                
                if(p.can_request_changes) actionsHtml+=`<button class="store-mm-action-button action-changes request-changes" data-id="${p.id}">Request Changes</button>`;
                if(p.can_move_to_prototyping) actionsHtml+=`<button class="store-mm-action-button action-prototyping move-to-prototyping" data-id="${p.id}">Move to Prototyping</button>`;
                if(p.can_approve) actionsHtml+=`<button class="store-mm-action-button action-approve approve-product" data-id="${p.id}">${p.workflow_state==='rejected'?'Republish':'Approve'}</button>`;
                if(p.can_reject) actionsHtml+=`<button class="store-mm-action-button action-reject reject-product" data-id="${p.id}">${p.workflow_state==='approved'?'Unpublish':'Reject'}</button>`;
                if(p.can_view_files) actionsHtml+=`<button class="store-mm-action-button action-view view-files" data-id="${p.id}">View/Download Files</button>`;
                if(p.can_set_price) actionsHtml+=`<button class="store-mm-action-button action-set-price set-price" data-id="${p.id}">Set Price</button>`;
                if(p.can_add_note) actionsHtml+=`<button class="store-mm-action-button action-note add-note" data-id="${p.id}">Add Note</button>`;
                if(p.can_archive) actionsHtml+=`<button class="store-mm-action-button action-archive archive-product" data-id="${p.id}">Archive</button>`;
                if(p.can_delete) actionsHtml+=`<button class="store-mm-action-button action-delete delete-product" data-id="${p.id}">Delete</button>`;
                
                if(!actionsHtml) actionsHtml='<span class="store-mm-no-actions">No actions available</span>';
                
                html+=`<tr>${showDesigner?`<td>${p.designer_name}</td>`:''}
                    <td><strong>${p.title}</strong></td><td>${p.categories||''}</td><td>${priceDisplay}</td>
                    <td>${p.royalty||0}%</td><td>${p.submission_date?new Date(p.submission_date).toLocaleDateString():'N/A'}</td>
                    <td>${stateBadge}</td>
                    <td><div class="store-mm-action-buttons">${actionsHtml}</div></td></tr>`;
            });
            
            $body.html(html);
            updateDevPagination(p);
            bindDevActionHandlers();
        }
        
        function updateDevPagination(p){
            $('#store-mm-dev-pagination-info').text(`Showing ${((currentPage-1)*perPage)+1} - ${Math.min(currentPage*perPage,p.total_items)} of ${p.total_items}`);
            $('#store-mm-dev-prev-page').prop('disabled',currentPage<=1);
            $('#store-mm-dev-next-page').prop('disabled',currentPage>=p.total_pages);
        }
        
        function bindDevActionHandlers(){
            $('.submit-changes').on('click',function(){showSubmitChangesModal($(this).data('id'));});
            $('.request-changes').on('click',function(){showActionModal('request_changes',$(this).data('id'));});
            $('.move-to-prototyping').on('click',function(){showActionModal('move_to_prototyping',$(this).data('id'));});
            $('.approve-product').on('click',function(){showActionModal('approve',$(this).data('id'));});
            $('.reject-product').on('click',function(){showActionModal('reject',$(this).data('id'));});
            $('.view-files').on('click',function(){showFilesModal($(this).data('id'));});
            $('.set-price').on('click',function(){showSetPriceModal($(this).data('id'));});
            $('.add-note').on('click',function(){showAddNoteModal($(this).data('id'));});
            $('.archive-product').on('click',function(){showArchiveModal($(this).data('id'));});
            $('.delete-product').on('click',function(){showDeleteModal($(this).data('id'));});
        }
        
        // Event listeners
        $('#store-mm-dev-apply-filters').on('click',()=>{
            filters.state=$('#store-mm-dev-state-filter').val();
            filters.designer=$('#store-mm-dev-designer-filter').val();
            filters.search=$('#store-mm-dev-search').val();
            currentPage=1; loadDevProducts();
        });
        
        $('#store-mm-dev-reset-filters').on('click',()=>{
            $('#store-mm-dev-state-filter, #store-mm-dev-designer-filter').val('all');
            $('#store-mm-dev-search').val('');
            filters={state:'all',designer:'all',search:''};
            currentPage=1; loadDevProducts();
        });
        
        $('#store-mm-dev-prev-page').on('click',()=>{if(currentPage>1){currentPage--; loadDevProducts();}});
        $('#store-mm-dev-next-page').on('click',()=>{currentPage++; loadDevProducts();});
        
        loadDevProducts();
    }
    
    function initLiveProducts(){
        let currentPage=1, perPage=10, filters={category:'all',designer:'all',search:''};
        
        function loadLiveProducts(){
            const $body=$('#store-mm-live-products-body');
            const showDesigner=store_mm_workflow.user.is_admin||store_mm_workflow.user.is_moderator;
            $body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><div class="store-mm-spinner"></div><span>${store_mm_workflow.strings.loading}</span></td></tr>`);
            
            $.ajax({
                url:store_mm_workflow.ajax_url, type:'POST',
                data:{action:'store_mm_get_live_products',nonce:store_mm_workflow.nonce,page:currentPage,per_page:perPage,category:filters.category,designer:filters.designer,search:filters.search},
                success:r=>r.success?renderLiveProducts(r.data.products,r.data.pagination):$body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span style="color:#dc2626;">${store_mm_workflow.strings.error}</span></td></tr>`),
                error:()=>$body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span style="color:#dc2626;">Network error</span></td></tr>`)
            });
        }
        
        function renderLiveProducts(products,p){
            const $body=$('#store-mm-live-products-body');
            const showDesigner=store_mm_workflow.user.is_admin||store_mm_workflow.user.is_moderator;
            
            if(!products.length){
                $body.html(`<tr><td colspan="${showDesigner?8:7}" class="store-mm-loading-row"><span>${store_mm_workflow.strings.no_products}</span></td></tr>`);
                return;
            }
            
            let html='';
            products.forEach(p=>{
                let priceDisplay=`${p.price_display}`;
                if(p.price_type==='proposed') priceDisplay=`<span style="color:#666; font-style:italic;">${p.price_display} (proposed)</span>`;
                else if(p.price_type==='final') priceDisplay=`<strong>${p.price_display}</strong>`;
                
                let actionsHtml=`<a href="${p.view_url}" class="store-mm-action-button action-view" target="_blank">View in Store</a>`;
                if(p.can_reject && store_mm_workflow.user.is_admin) actionsHtml+=`<button class="store-mm-action-button action-reject reject-live-product" data-id="${p.id}">Remove</button>`;
                if(p.can_view_files) actionsHtml+=`<button class="store-mm-action-button action-view view-files" data-id="${p.id}">View Files</button>`;
                
                html+=`<tr>${showDesigner?`<td>${p.designer_name}</td>`:''}
                    <td><strong>${p.title}</strong></td><td>${p.categories||''}</td><td>${priceDisplay}</td>
                    <td>${p.royalty||0}%</td><td>${p.published_date?new Date(p.published_date).toLocaleDateString():'N/A'}</td>
                    <td>${p.sales_count||0} sales</td>
                    <td><div class="store-mm-action-buttons">${actionsHtml}</div></td></tr>`;
            });
            
            $body.html(html);
            updateLivePagination(p);
            bindLiveActionHandlers();
        }
        
        function updateLivePagination(p){
            $('#store-mm-live-pagination-info').text(`Showing ${((currentPage-1)*perPage)+1} - ${Math.min(currentPage*perPage,p.total_items)} of ${p.total_items}`);
            $('#store-mm-live-prev-page').prop('disabled',currentPage<=1);
            $('#store-mm-live-next-page').prop('disabled',currentPage>=p.total_pages);
        }
        
        function bindLiveActionHandlers(){
            $('.reject-live-product').on('click',function(){
                if(confirm('Are you sure you want to remove this product from the store? This will move it to Rejected state.')){
                    changeState('reject',$(this).data('id'));
                }
            });
            $('.view-files').on('click',function(){showFilesModal($(this).data('id'));});
        }
        
        // Event listeners
        $('#store-mm-live-apply-filters').on('click',()=>{
            filters.category=$('#store-mm-live-category-filter').val();
            filters.designer=$('#store-mm-live-designer-filter').val();
            filters.search=$('#store-mm-live-search').val();
            currentPage=1; loadLiveProducts();
        });
        
        $('#store-mm-live-reset-filters').on('click',()=>{
            $('#store-mm-live-category-filter, #store-mm-live-designer-filter').val('all');
            $('#store-mm-live-search').val('');
            filters={category:'all',designer:'all',search:''};
            currentPage=1; loadLiveProducts();
        });
        
        $('#store-mm-live-prev-page').on('click',()=>{if(currentPage>1){currentPage--; loadLiveProducts();}});
        $('#store-mm-live-next-page').on('click',()=>{currentPage++; loadLiveProducts();});
        
        loadLiveProducts();
    }
    
    function initModalHandlers(){
        $('.store-mm-modal-close, .store-mm-modal-cancel').on('click',closeAllModals);
        $('#store-mm-confirm-action').on('click',confirmAction);
        $('#store-mm-confirm-note').on('click',confirmAddNote);
        $('#store-mm-confirm-submit-changes').on('click',confirmSubmitChanges);
        $('#store-mm-reason-select').on('change',function(){
            $(this).val()==='other'?$('#store-mm-other-reason').slideDown():$('#store-mm-other-reason').slideUp();
        });
    }
    
    function initFileUploadHandlers(){
        // Submit changes modal
        $('#submit-changes-file-upload-zone').on('click',()=>$('#submit-changes-design-files').click());
        $('#submit-changes-design-files').on('change',e=>handleFileSelection(e.target.files,'submit-changes'));
        
        // Drag and drop for submit changes modal
        $('#submit-changes-file-upload-zone').on('dragover dragleave drop',e=>{
            e.preventDefault();
            if(e.type==='dragover') $('#submit-changes-file-upload-zone').css({'border-color':'#000','background':'#fff'});
            else if(e.type==='dragleave') $('#submit-changes-file-upload-zone').css({'border-color':'#bdbdbd','background':''});
            else if(e.type==='drop'){
                const files=e.originalEvent.dataTransfer.files;
                updateFileInput(files,'#submit-changes-design-files');
                handleFileSelection(files,'submit-changes');
            }
        });
    }
    
    function handleFileSelection(files,type){
        const previewId=type==='submit-changes'?'#submit-changes-file-preview':'';
        const placeholderId=type==='submit-changes'?'#submit-changes-file-upload-zone':'';
        const maxFiles=10;
        
        if(!previewId) return;
        
        $(previewId).empty();
        const fileCount=Math.min(files.length,maxFiles);
        if(!fileCount){
            $(placeholderId).find('.store-mm-upload-placeholder').html(`<span class="store-mm-upload-icon">📎</span>
                <p>Upload additional design files</p>
                <p class="store-mm-upload-subtext">Click or drag & drop (optional)</p>`);
            return;
        }
        
        for(let i=0;i<fileCount;i++){
            const file=files[i], fileName=file.name.length>25?file.name.substring(0,22)+'...':file.name;
            $(previewId).append(`<div class="store-mm-upload-preview-item">
                <button class="store-mm-upload-preview-remove" data-index="${i}" data-type="${type}" title="Remove">×</button>
                <span class="store-mm-upload-icon">📎</span>
                <div class="store-mm-upload-preview-name">${fileName}</div></div>`);
        }
        
        $(placeholderId).find('.store-mm-upload-placeholder').html(`<span class="store-mm-upload-icon">✅</span>
            <p>${fileCount} file${fileCount>1?'s':''} selected</p>
            <p class="store-mm-upload-subtext">Click to change</p>`);
            
        if(files.length>maxFiles) $(previewId).append('<div class="store-mm-upload-limit-warning">Max 10 files allowed</div>');
    }
    
    $(document).on('click','.store-mm-upload-preview-remove',function(){
        const index=$(this).data('index'), type=$(this).data('type');
        const inputId=type==='submit-changes'?'#submit-changes-design-files':'';
        const placeholderId=type==='submit-changes'?'#submit-changes-file-upload-zone':'';
        
        if(!inputId) return;
        
        $(this).closest('.store-mm-upload-preview-item').remove();
        const input=$(inputId)[0], files=Array.from(input.files);
        
        if(index<files.length){
            files.splice(index,1);
            const dt=new DataTransfer();
            files.forEach(f=>dt.items.add(f));
            input.files=dt.files;
        }
        
        if(!files.length){
            $(placeholderId).find('.store-mm-upload-placeholder').html(`<span class="store-mm-upload-icon">📎</span>
                <p>Upload additional design files</p>
                <p class="store-mm-upload-subtext">Click or drag & drop (optional)</p>`);
        }
    });
    
    function updateFileInput(files,inputId){
        const input=$(inputId)[0], dt=new DataTransfer();
        for(let i=0;i<Math.min(files.length,10);i++) dt.items.add(files[i]);
        input.files=dt.files;
    }
    
    function showActionModal(action, productId){
        const titles={
            'request_changes':'Request Changes',
            'move_to_prototyping':'Move to Prototyping',
            'approve':'Approve Product',
            'reject':'Reject Product',
            'submit_changes':'Submit Changes'
        };
        const messages={
            'request_changes':store_mm_workflow.strings.confirm_request_changes,
            'move_to_prototyping':store_mm_workflow.strings.confirm_move_to_prototyping,
            'approve':store_mm_workflow.strings.confirm_approve,
            'reject':store_mm_workflow.strings.confirm_reject,
            'submit_changes':'Submit your changes for review?'
        };
        
        $('#store-mm-modal-title').text(titles[action]);
        $('#store-mm-modal-message').html(`<p>${messages[action]}</p>`);
        $('#store-mm-action-reason').toggle(action==='reject');
        $('#store-mm-action-notes').show();
        $('#store-mm-set-price').hide();
        $('#store-mm-confirm-action').data('action',action).data('product-id',productId).text(action==='reject'?'Confirm Rejection':'Confirm');
        $('#store-mm-action-modal').fadeIn();
    }
    
    function showSubmitChangesModal(productId){
        $('#store-mm-submit-changes-title').text('Submit Changes');
        $('#store-mm-submit-changes-message').html('<p>Update your product details and submit for review.</p>');
        
        resetSubmitChangesModal();
        
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST',
            data:{action:'store_mm_get_product_details',nonce:store_mm_workflow.nonce,product_id:productId},
            success:r=>{
                if(r.success){
                    $('#store-mm-submit-changes-price').val(r.data.current_price);
                    $('#store-mm-submit-changes-royalty').val(r.data.current_royalty);
                    $('#store-mm-confirm-submit-changes').data('product-id',productId);
                    
                    // Check for proposed changes from admin
                    if(r.data.has_proposed_changes && r.data.proposed_price){
                        $('#proposal-notice').show();
                        const proposalDetails = `Proposed Price: ${store_mm_workflow.currency} ${parseFloat(r.data.proposed_price).toFixed(store_mm_workflow.decimals)}<br>`;
                        const royaltyText = r.data.proposed_royalty ? `Proposed Royalty: ${r.data.proposed_royalty}%<br>` : '';
                        const notesText = r.data.proposed_notes ? `Notes: ${r.data.proposed_notes}` : '';
                        $('#proposal-details').html(proposalDetails + royaltyText + notesText);
                        
                        // Pre-fill with admin's proposal
                        $('#store-mm-submit-changes-price').val(r.data.proposed_price);
                        if(r.data.proposed_royalty) {
                            $('#store-mm-submit-changes-royalty').val(r.data.proposed_royalty);
                        }
                    } else {
                        $('#proposal-notice').hide();
                    }
                }
            }
        });
        
        $('#store-mm-submit-changes-modal').fadeIn();
    }
    
    function showSetPriceModal(productId){
        $('#store-mm-modal-title').text('Set Price & Royalty');
        $('#store-mm-modal-message').html('<p>Set the proposed price and royalty for this product (before prototyping).</p>');
        $('#store-mm-action-notes').show();
        $('#store-mm-action-reason').hide();
        $('#store-mm-set-price').show();
        $('#store-mm-confirm-action').data('action','set_price').data('product-id',productId).text('Propose Price');
        $('#store-mm-action-modal').fadeIn();
    }
    
    function showAddNoteModal(productId){
        $('#store-mm-note-modal-title').text('Add Internal Note');
        $('#store-mm-note-message').html('<p>Add an internal note about this product (visible to staff only).</p>');
        $('#store-mm-note-textarea').val('');
        $('#store-mm-confirm-note').data('product-id',productId);
        $('#store-mm-note-modal').fadeIn();
    }
    
    function showArchiveModal(productId){
        if(confirm('Archive this product? This will mark it as archived but keep it in the system.')){
            $.ajax({
                url:store_mm_workflow.ajax_url, type:'POST',
                data:{action:'store_mm_archive_product',nonce:store_mm_workflow.nonce,product_id:productId},
                success:r=>{
                    if(r.success){
                        alert('Product archived successfully!');
                        $('#store-mm-dev-products').length && initDevProducts();
                    }else alert('Error: '+r.data);
                },
                error:()=>alert('Network error. Please try again.')
            });
        }
    }
    
    function showDeleteModal(productId){
        if(confirm('WARNING: This will permanently delete this product and all associated files. This action cannot be undone!\n\nAre you sure?')){
            $.ajax({
                url:store_mm_workflow.ajax_url, type:'POST',
                data:{action:'store_mm_delete_product',nonce:store_mm_workflow.nonce,product_id:productId},
                success:r=>{
                    if(r.success){
                        alert('Product deleted successfully!');
                        $('#store-mm-dev-products').length && initDevProducts();
                    }else alert('Error: '+r.data);
                },
                error:()=>alert('Network error. Please try again.')
            });
        }
    }
    
    function showFilesModal(productId){
        $('#store-mm-files-modal-title').text('Loading Files...');
        $('#store-mm-files-loading').show();
        $('#store-mm-files-content').hide();
        
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST',
            data:{action:'store_mm_get_product_files',nonce:store_mm_workflow.nonce,product_id:productId},
            success:r=>{
                if(r.success){
                    $('#store-mm-files-modal-title').text('Files: '+r.data.product_title);
                    let content='';
                    
                    if(r.data.images&&r.data.images.length){
                        content+='<h4>Product Images</h4><div class="store-mm-files-grid">';
                        r.data.images.forEach(img=>{
                            content+=`<div class="store-mm-file-item">
                                <div class="store-mm-file-thumb"><img src="${img.thumb}" alt="${img.name}"></div>
                                <div class="store-mm-file-name">${img.name}</div>
                                <div class="store-mm-file-actions">
                                    <a href="${img.url}" target="_blank" class="store-mm-button store-mm-button-small store-mm-button-primary" style="margin-bottom:5px;">View</a>
                                    <a href="${img.url}" download class="store-mm-button store-mm-button-small store-mm-button-secondary">Download</a>
                                </div></div>`;
                        });
                        content+='</div>';
                    }
                    
                    if(r.data.design_files&&r.data.design_files.length){
                        content+='<h4 style="margin-top:20px;">Design Files</h4><div class="store-mm-files-grid">';
                        r.data.design_files.forEach(file=>{
                            content+=`<div class="store-mm-file-item">
                                <div class="store-mm-file-icon">${file.icon}</div>
                                <div class="store-mm-file-info">
                                    <div class="store-mm-file-name">${file.name}</div>
                                    <div class="store-mm-file-meta">${file.extension.toUpperCase()} • ${file.size}</div>
                                </div>
                                <div class="store-mm-file-actions">
                                    <a href="${file.url}" target="_blank" class="store-mm-button store-mm-button-small store-mm-button-primary" style="margin-bottom:5px;">View</a>
                                    <a href="${file.url}" download class="store-mm-button store-mm-button-small store-mm-button-secondary">Download</a>
                                </div></div>`;
                        });
                        content+='</div>';
                    }else content+='<div class="store-mm-no-files" style="padding:20px; background:#f8f9fa; border-radius:8px; text-align:center; color:#666;">No design files uploaded yet.</div>';
                    
                    if(r.data.notes){
                        content+=`<div class="store-mm-notes-box" style="margin-top:20px; padding:15px; background:#f8f9fa; border-radius:8px;">
                            <h4>Latest Notes</h4><p style="white-space: pre-wrap;">${r.data.notes}</p></div>`;
                    }
                    
                    $('#store-mm-files-content').html(content);
                    $('#store-mm-files-loading').hide();
                    $('#store-mm-files-content').show();
                }
            }
        });
        
        $('#store-mm-files-modal').fadeIn();
    }
    
    function confirmSubmitChanges(){
        const productId=$('#store-mm-confirm-submit-changes').data('product-id');
        const price=$('#store-mm-submit-changes-price').val();
        const royalty=$('#store-mm-submit-changes-royalty').val();
        const notes=$('#store-mm-submit-changes-notes').val();
        
        if(!price||parseFloat(price)<=0) return alert('Please enter a valid price.');
        if(!royalty||parseFloat(royalty)<1||parseFloat(royalty)>50) return alert('Please enter a valid royalty percentage (1-50%).');
        
        $('#store-mm-confirm-submit-changes').prop('disabled',true).text('Submitting...');
        const formData=new FormData();
        formData.append('action','store_mm_update_product');
        formData.append('nonce',store_mm_workflow.nonce);
        formData.append('product_id',productId);
        formData.append('price',price);
        formData.append('royalty',royalty);
        formData.append('notes',notes);
        
        const fileInput=$('#submit-changes-design-files')[0];
        for(let i=0;i<fileInput.files.length;i++) formData.append('edit_design_files[]',fileInput.files[i]);
        
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST', data:formData, processData:false, contentType:false,
            success:r=>{
                if(r.success){
                    // If accepting proposal, submit changes automatically
                    $.ajax({
                        url:store_mm_workflow.ajax_url, type:'POST',
                        data:{action:'store_mm_change_state',nonce:store_mm_workflow.nonce,product_id:productId,action_type:'submit_changes',notes:'Updated price and files.'},
                        success:r2=>{
                            if(r2.success){
                                alert(r.data.accepted_proposal ? 'Price proposal accepted and submitted for review!' : 'Product updated and submitted for review successfully!');
                                $('#store-mm-submit-changes-modal').fadeOut();
                                resetSubmitChangesModal();
                                $('#store-mm-dev-products').length && initDevProducts();
                            }else alert('Error submitting changes: '+r2.data);
                            $('#store-mm-confirm-submit-changes').prop('disabled',false).text('Submit Changes for Review');
                        },
                        error:()=>{
                            alert('Network error when submitting changes.');
                            $('#store-mm-confirm-submit-changes').prop('disabled',false).text('Submit Changes for Review');
                        }
                    });
                }else{
                    alert('Error updating product: '+r.data);
                    $('#store-mm-confirm-submit-changes').prop('disabled',false).text('Submit Changes for Review');
                }
            },
            error:()=>{
                alert('Network error when updating product.');
                $('#store-mm-confirm-submit-changes').prop('disabled',false).text('Submit Changes for Review');
            }
        });
    }
    
    function confirmAddNote(){
        const productId=$('#store-mm-confirm-note').data('product-id');
        const notes=$('#store-mm-note-textarea').val();
        
        if(!notes.trim()) return alert('Please enter a note.');
        
        $('#store-mm-confirm-note').prop('disabled',true).text('Adding...');
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST',
            data:{action:'store_mm_add_note',nonce:store_mm_workflow.nonce,product_id:productId,notes:notes},
            success:r=>{
                if(r.success){
                    alert('Note added successfully!');
                    $('#store-mm-note-modal').fadeOut();
                    $('#store-mm-dev-products').length && initDevProducts();
                }else alert('Error: '+r.data);
                $('#store-mm-confirm-note').prop('disabled',false).text('Add Note');
            },
            error:()=>{
                alert('Network error. Please try again.');
                $('#store-mm-confirm-note').prop('disabled',false).text('Add Note');
            }
        });
    }
    
    function confirmAction(){
        const action=$('#store-mm-confirm-action').data('action');
        const productId=$('#store-mm-confirm-action').data('product-id');
        const notes=$('#store-mm-notes-textarea').val();
        
        if(action==='set_price'){
            const finalPrice=$('#store-mm-final-price').val();
            const finalRoyalty=$('#store-mm-final-royalty').val();
            
            if(!finalPrice||parseFloat(finalPrice)<=0) return alert('Please enter a valid price.');
            if(!finalRoyalty||parseFloat(finalRoyalty)<1||parseFloat(finalRoyalty)>50) return alert('Please enter a valid royalty percentage (1-50%).');
            
            const confirmMsg=`Propose this price to designer?\n\nPrice: ${store_mm_workflow.currency} ${parseFloat(finalPrice).toFixed(store_mm_workflow.decimals)}\nRoyalty: ${finalRoyalty}%\n\nDesigner must confirm before moving to prototyping.`;
            if(!confirm(confirmMsg)) return;
            
            $('#store-mm-confirm-action').prop('disabled',true).text(store_mm_workflow.strings.setting_price);
            $.ajax({
                url:store_mm_workflow.ajax_url, type:'POST',
                data:{action:'store_mm_set_final_price',nonce:store_mm_workflow.nonce,product_id:productId,final_price:finalPrice,final_royalty:finalRoyalty,notes:notes},
                success:r=>{
                    if(r.success){
                        alert(store_mm_workflow.strings.price_set);
                        $('#store-mm-action-modal').fadeOut();
                        resetModal();
                        $('#store-mm-dev-products').length && initDevProducts();
                    }else alert('Error: '+r.data);
                    $('#store-mm-confirm-action').prop('disabled',false).text('Propose Price');
                },
                error:()=>{
                    alert('Network error. Please try again.');
                    $('#store-mm-confirm-action').prop('disabled',false).text('Propose Price');
                }
            });
            return;
        }
        
        const reasonSelect=$('#store-mm-reason-select').val();
        let reason=reasonSelect;
        if(reasonSelect==='other'){
            reason=$('#store-mm-custom-reason').val();
            if(!reason) return alert('Please specify the reason for rejection.');
        }
        
        $('#store-mm-confirm-action').prop('disabled',true).text('Processing...');
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST',
            data:{action:'store_mm_change_state',nonce:store_mm_workflow.nonce,product_id:productId,action_type:action,notes:notes,reason:reason},
            success:r=>{
                if(r.success){
                    alert('Action completed successfully!');
                    $('#store-mm-action-modal').fadeOut();
                    resetModal();
                    $('#store-mm-dev-products').length && initDevProducts();
                    $('#store-mm-live-products').length && initLiveProducts();
                }else alert('Error: '+r.data);
                $('#store-mm-confirm-action').prop('disabled',false);
            },
            error:()=>{
                alert('Network error. Please try again.');
                $('#store-mm-confirm-action').prop('disabled',false);
            }
        });
    }
    
    function changeState(action, productId){
        $.ajax({
            url:store_mm_workflow.ajax_url, type:'POST',
            data:{action:'store_mm_change_state',nonce:store_mm_workflow.nonce,product_id:productId,action_type:action},
            success:r=>{
                if(r.success){
                    alert('Product rejected successfully!');
                    $('#store-mm-dev-products').length && initDevProducts();
                    $('#store-mm-live-products').length && initLiveProducts();
                }else alert('Error: '+r.data);
            },
            error:()=>alert('Network error. Please try again.')
        });
    }
    
    function closeAllModals(){
        $('#store-mm-action-modal, #store-mm-files-modal, #store-mm-submit-changes-modal, #store-mm-note-modal').fadeOut();
        resetModal(); resetSubmitChangesModal();
    }
    
    function resetModal(){
        $('#store-mm-notes-textarea, #store-mm-reason-select, #store-mm-custom-reason, #store-mm-final-price, #store-mm-final-royalty').val('');
        $('#store-mm-other-reason').hide();
    }
    
    function resetSubmitChangesModal(){
        $('#submit-changes-file-preview').empty();
        $('#submit-changes-design-files, #store-mm-submit-changes-notes, #store-mm-submit-changes-price, #store-mm-submit-changes-royalty').val('');
        $('#proposal-notice').hide();
        $('#submit-changes-file-upload-zone').find('.store-mm-upload-placeholder').html('<span class="store-mm-upload-icon">📎</span><p>Upload additional design files</p><p class="store-mm-upload-subtext">Click or drag & drop (optional)</p>');
    }
});