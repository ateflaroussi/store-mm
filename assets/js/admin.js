// Store MM Admin - Read-Only Dashboard
jQuery(function($){
    let currentPage=1,perPage=20,filters={state:'all',category:'all',search:''};
    
    function loadSubmissions(){
        const $body=$('#store-mm-submissions-body');
        $body.html('<tr><td colspan="9" class="store-mm-loading-row"><div class="store-mm-spinner"></div><span>Loading...</span></td></tr>');
        
        $.ajax({
            url:store_mm_admin_ajax.ajax_url,
            type:'POST',
            data:{
                action:'store_mm_get_submissions',
                nonce:store_mm_admin_ajax.nonce,
                page:currentPage,
                per_page:perPage,
                state:filters.state,
                category:filters.category,
                search:filters.search
            },
            success:r=>{
                if(r.success){
                    renderSubmissions(r.data.submissions);
                    updatePagination(r.data.pagination);
                    updateStats(r.data.stats);
                }else{
                    $body.html('<tr><td colspan="9" class="store-mm-loading-row"><span style="color:#dc2626;">Error loading data</span></td></tr>');
                }
            },
            error:()=>{
                $body.html('<tr><td colspan="9" class="store-mm-loading-row"><span style="color:#dc2626;">Network error</span></td></tr>');
            }
        });
    }
    
    function renderSubmissions(submissions){
        const $body=$('#store-mm-submissions-body');
        if(submissions.length===0){
            $body.html('<tr><td colspan="9" class="store-mm-loading-row"><span>No designs found.</span></td></tr>');
            return;
        }
        
        let html='';
        submissions.forEach(s=>{
            const stateClass=`state-${s.workflow_state.replace(/-/g,'_')}`;
            const stateLabel=s.workflow_state.replace(/_/g,' ');
            
            html+=`<tr>
                <td>${s.id}</td>
                <td><strong>${s.title}</strong></td>
                <td>${s.designer_name}</td>
                <td>${s.categories||''}</td>
                <td>${s.price_display || (store_mm_admin_ajax.currency + ' ' + parseFloat(s.price||0).toFixed(store_mm_admin_ajax.decimals))}</td>
                <td>${s.royalty||0}%</td>
                <td>${s.submission_date?new Date(s.submission_date).toLocaleDateString():'N/A'}</td>
                <td><span class="store-mm-state-badge ${stateClass}">${stateLabel}</span></td>
                <td><a href="${s.edit_url}" class="button button-small" target="_blank">View</a></td>
            </tr>`;
        });
        
        $body.html(html);
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
    
    // Event listeners
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
    
    $('#store-mm-prev-page').on('click',()=>{
        if(currentPage>1){
            currentPage--;
            loadSubmissions();
        }
    });
    
    $('#store-mm-next-page').on('click',()=>{
        currentPage++;
        loadSubmissions();
    });
    
    // Load initial data
    loadSubmissions();
});