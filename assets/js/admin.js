// Store MM Admin - Compressed
jQuery(function($){
    let currentPage=1,perPage=20,selectedItems=[],filters={state:'all',category:'all',search:''};
    function loadSubmissions(){
        const $body=$('#store-mm-submissions-body');
        $body.html('<tr><td colspan="10" class="store-mm-loading-row"><div class="store-mm-spinner"></div><span>Loading...</span></td></tr>');
        $.ajax({
            url:store_mm_admin_ajax.ajax_url,type:'POST',dataType:'json',
            data:{action:'store_mm_get_submissions',nonce:store_mm_admin_ajax.nonce,page:currentPage,per_page:perPage,state:filters.state,category:filters.category,search:filters.search},
            success:r=>{
                if(r.success){
                    renderSubmissions(r.data.submissions);
                    updatePagination(r.data.pagination);
                    updateStats(r.data.stats);
                }else $body.html('<tr><td colspan="10" class="store-mm-loading-row"><span style="color:#dc2626;">Error</span></td></tr>');
            },
            error:()=>$body.html('<tr><td colspan="10" class="store-mm-loading-row"><span style="color:#dc2626;">Network error</span></td></tr>')
        });
    }
    function renderSubmissions(submissions){
        const $body=$('#store-mm-submissions-body');
        if(submissions.length===0){
            $body.html('<tr><td colspan="10" class="store-mm-loading-row"><span>No designs found.</span></td></tr>');
            return;
        }
        let html='';
        submissions.forEach(s=>{
            const stateClass=`state-${s.workflow_state.replace(/-/g,'_')}`,stateLabel=s.workflow_state.replace(/_/g,' ');
            let actions='';
            if(s.workflow_state==='submitted'||s.workflow_state==='changes_requested'){
                if(store_mm_admin_ajax.user_can.reject==='yes') actions+=`<button class="store-mm-action-button action-changes" data-action="request_changes" data-id="${s.id}">Request Changes</button>`;
                if(store_mm_admin_ajax.user_can.reject==='yes') actions+=`<button class="store-mm-action-button action-prototyping" data-action="move_to_prototyping" data-id="${s.id}">Prototyping</button>`;
                if(store_mm_admin_ajax.user_can.reject==='yes') actions+=`<button class="store-mm-action-button action-reject" data-action="reject" data-id="${s.id}">Reject</button>`;
            }else if(s.workflow_state==='prototyping'&&store_mm_admin_ajax.user_can.approve==='yes'){
                actions+=`<button class="store-mm-action-button action-approve" data-action="approve" data-id="${s.id}">Approve</button>`;
                actions+=`<button class="store-mm-action-button action-reject" data-action="reject" data-id="${s.id}">Reject</button>`;
            }
            html+=`<tr>
                <th><input type="checkbox" class="store-mm-row-select" value="${s.id}" data-state="${s.workflow_state}"></th>
                <td>${s.id}</td><td><strong>${s.title}</strong></td><td>${s.designer_name}</td>
                <td>${s.categories||''}</td><td>$${parseFloat(s.price||0).toFixed(2)}</td><td>${s.royalty||0}%</td>
                <td>${s.submission_date?new Date(s.submission_date).toLocaleDateString():'N/A'}</td>
                <td><span class="store-mm-state-badge ${stateClass}">${stateLabel}</span></td>
                <td>${actions}<a href="${s.edit_url}" class="button button-small" target="_blank">Edit</a></td>
            </tr>`;
        });
        $body.html(html);
        updateRowSelection();
    }
    function updatePagination(p){
        $('#store-mm-pagination-info').text(`Showing ${((currentPage-1)*perPage)+1} - ${Math.min(currentPage*perPage,p.total_items)} of ${p.total_items}`);
        $('#store-mm-prev-page').prop('disabled',currentPage<=1);
        $('#store-mm-next-page').prop('disabled',currentPage>=p.total_pages);
    }
    function updateStats(s){
        $('#stat-submitted').text(s.submitted||0);
        $('#stat-changes-requested').text(s.changes_requested||0);
        $('#stat-prototyping').text(s.prototyping||0);
        $('#stat-approved').text(s.approved||0);
        $('#stat-rejected').text(s.rejected||0);
    }
    function updateSelectedItems(){
        selectedItems=[];
        $('.store-mm-row-select:checked').each(function(){selectedItems.push({id:$(this).val(),state:$(this).data('state')});});
    }
    function updateBulkButton(){
        $('#store-mm-apply-bulk-action').prop('disabled',selectedItems.length===0||$('#store-mm-bulk-action').val()==='');
    }
    function showActionModal(action,items){
        const $modal=$('#store-mm-action-modal'),$title=$('#store-mm-modal-title'),$msg=$('#store-mm-modal-message');
        let title='',message='',confirmText='Confirm';
        switch(action){
            case 'request_changes':title='Request Changes';message=`Request changes for ${items.length} design(s)?`;confirmText='Request Changes';break;
            case 'move_to_prototyping':title='Move to Prototyping';message=`Move ${items.length} design(s) to prototyping?`;confirmText='Move to Prototyping';break;
            case 'approve':title='Approve Designs';message=`Approve ${items.length} design(s) for sale?`;confirmText='Approve';break;
            case 'reject':title='Reject Designs';message=`Reject ${items.length} design(s)?`;confirmText='Reject';break;
        }
        $title.text(title);$msg.text(message);
        $('#store-mm-confirm-action').text(confirmText).data({action:action,items:items});
        $modal.fadeIn();
    }
    function confirmAction(){
        const $btn=$('#store-mm-confirm-action'),action=$btn.data('action'),items=$btn.data('items');
        $btn.prop('disabled',true).text('Processing...');
        const processNext=index=>{
            if(index>=items.length){$('#store-mm-action-modal').fadeOut();loadSubmissions();return;}
            $.ajax({
                url:store_mm_admin_ajax.ajax_url,type:'POST',dataType:'json',
                data:{action:'store_mm_update_state',nonce:store_mm_admin_ajax.nonce,product_id:items[index].id,action_type:action},
                success:r=>{
                    if(r.success){
                        processNext(index+1);
                    }else{
                        alert('Error: '+(r.data||'Unknown error'));
                        $btn.prop('disabled',false).text('Confirm');
                    }
                },
                error:(xhr,status,error)=>{
                    console.error('AJAX Error:',status,error);
                    try{
                        const response=JSON.parse(xhr.responseText);
                        if(response.success){
                            processNext(index+1);
                        }else{
                            alert('Error: '+(response.data||'Unknown error'));
                            $btn.prop('disabled',false).text('Confirm');
                        }
                    }catch(e){
                        alert('Server error. Please check console for details.');
                        console.error('Raw response:',xhr.responseText);
                        $btn.prop('disabled',false).text('Confirm');
                    }
                }
            });
        };
        processNext(0);
    }
    function updateRowSelection(){
        $('.store-mm-row-select').on('change',function(){
            updateSelectedItems();
            updateBulkButton();
            const total=$('.store-mm-row-select').length,checked=$('.store-mm-row-select:checked').length;
            $('#store-mm-select-all').prop('checked',total>0&&total===checked);
        });
    }
    $('#store-mm-apply-filters').on('click',()=>{
        filters.state=$('#store-mm-filter-state').val();
        filters.search=$('#store-mm-search').val();
        currentPage=1;
        loadSubmissions();
    });
    $('#store-mm-reset-filters').on('click',()=>{
        $('#store-mm-filter-state').val('all');
        $('#store-mm-search').val('');
        filters={state:'all',category:'all',search:''};
        currentPage=1;
        loadSubmissions();
    });
    $('#store-mm-prev-page').on('click',()=>{if(currentPage>1){currentPage--;loadSubmissions();}});
    $('#store-mm-next-page').on('click',()=>{currentPage++;loadSubmissions();});
    $('#store-mm-select-all').on('change',function(){
        $('.store-mm-row-select').prop('checked',$(this).is(':checked'));
        updateSelectedItems();
        updateBulkButton();
    });
    $('#store-mm-bulk-action').on('change',updateBulkButton);
    $('#store-mm-apply-bulk-action').on('click',()=>{
        const action=$('#store-mm-bulk-action').val();
        if(!action||selectedItems.length===0)return;
        showActionModal(action,selectedItems);
    });
    $(document).on('click','.store-mm-action-button',function(){
        showActionModal($(this).data('action'),[$(this).data('id')]);
    });
    $('.store-mm-modal-close,.store-mm-modal-cancel').on('click',()=>{$('#store-mm-action-modal').fadeOut();});
    $('#store-mm-confirm-action').on('click',confirmAction);
    
    loadSubmissions();
    updateRowSelection();
});