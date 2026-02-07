// Store MM Frontend - Compressed
jQuery(function($){
    let currentTab=1,totalTabs=3;
    function updateProgress(){
        $('.store-mm-step').removeClass('active completed');
        for(let i=1;i<=totalTabs;i++){
            if(i<currentTab) $(`.store-mm-step[data-step="${i}"]`).addClass('completed');
            else if(i===currentTab) $(`.store-mm-step[data-step="${i}"]`).addClass('active');
        }
    }
    function switchTab(tab){
        if(tab<1||tab>totalTabs)return;
        if(!validateCurrentTab())return;
        $('.store-mm-tab').removeClass('active');
        $(`#tab-${tab}`).addClass('active');
        currentTab=tab;
        updateProgress();
        $('html,body').animate({scrollTop:$('.store-mm-form-container').offset().top-100},300);
    }
    function validateCurrentTab(){
        const tabId=`#tab-${currentTab}`;
        let isValid=true;
        $(`${tabId} .store-mm-error-message`).removeClass('show').hide();
        if(currentTab===1){
            if(!$('#design-title').val()){showError($('#design-title'),'Design title required');isValid=false;}
            if(!$('#design-description').val()){showError($('#design-description'),'Design description required');isValid=false;}
            if(!$('#category-x').val()){showError($('#category-x'),'Main category required');isValid=false;}
            if(!$('#category-y').val()){showError($('#category-y'),'Sub-category required');isValid=false;}
            if(!$('#agree-nda').is(':checked')){showError($('#agree-nda'),'NDA agreement required');isValid=false;}
        }
        if(currentTab===2){
            const images=$('#product-images')[0].files;
            if(!images||images.length===0){showError($('#image-upload-zone'),'At least one image required');isValid=false;}
        }
        return isValid;
    }
    function showError($el,msg){
        const $error=$el.closest('.store-mm-form-group').find('.store-mm-error-message');
        $error.text(msg).addClass('show').show();
        $el.addClass('error');
        $('html,body').animate({scrollTop:$error.offset().top-100},300);
    }
    function initializeFileUpload(){
        $('#image-upload-zone,#file-upload-zone').on('click',function(){$(this).find('+input[type="file"]').click();});
        $('#product-images,#design-files').on('change',function(e){
            const files=e.target.files,isImage=$(this).attr('id')==='product-images';
            handleFileSelection(files,isImage);
        });
        ['#image-upload-zone','#file-upload-zone'].forEach(zone=>{
            $(zone).on('dragover',e=>{e.preventDefault();$(this).css({'border-color':'#000','background':'#fff'});});
            $(zone).on('dragleave',e=>{e.preventDefault();$(this).css({'border-color':'#bdbdbd','background':''});});
            $(zone).on('drop',e=>{
                e.preventDefault();
                const files=e.originalEvent.dataTransfer.files;
                const inputId=zone==='#image-upload-zone'?'#product-images':'#design-files';
                updateFileInput(files,inputId);
                handleFileSelection(files,zone==='#image-upload-zone');
            });
        });
    }
    function handleFileSelection(files,isImages){
        const previewId=isImages?'#image-preview':'#file-preview';
        const placeholderId=isImages?'#image-upload-zone':'#file-upload-zone';
        const maxFiles=5;
        $(previewId).empty();
        const fileCount=Math.min(files.length,maxFiles);
        if(fileCount===0){
            $(placeholderId).find('.store-mm-upload-placeholder').html(`<span class="store-mm-upload-icon">${isImages?'📷':'📎'}</span><p>Drag & drop ${isImages?'images':'files'} here</p><p class="store-mm-upload-subtext">or click to browse</p>`);
            return;
        }
        for(let i=0;i<fileCount;i++){
            const file=files[i],fileName=file.name.length>15?file.name.substring(0,12)+'...':file.name;
            let preview=isImages&&file.type.startsWith('image/')?`<img id="preview-img-${i}" src="">`:`<span class="store-mm-upload-icon">📎</span>`;
            $(previewId).append(`<div class="store-mm-upload-preview-item"><button class="store-mm-upload-preview-remove" data-index="${i}" data-is-image="${isImages}" title="Remove">×</button>${preview}<div class="store-mm-upload-preview-name">${fileName}</div></div>`);
            if(isImages&&file.type.startsWith('image/')){
                const reader=new FileReader();
                reader.onload=e=>{$(previewId).find(`#preview-img-${i}`).attr('src',e.target.result);};
                reader.readAsDataURL(file);
            }
        }
        $(placeholderId).find('.store-mm-upload-placeholder').html(`<span class="store-mm-upload-icon">✅</span><p>${fileCount} file${fileCount>1?'s':''} selected</p><p class="store-mm-upload-subtext">Click to change</p>`);
        if(files.length>maxFiles) $(previewId).append('<div class="store-mm-upload-limit-warning">Max 5 files allowed</div>');
    }
    function updateFileInput(files,inputId){
        const input=$(inputId)[0],dataTransfer=new DataTransfer();
        for(let i=0;i<Math.min(files.length,5);i++) dataTransfer.items.add(files[i]);
        input.files=dataTransfer.files;
    }
    function initializeCategoryFilter(){
        $('#category-x').on('change',function(){
            const parentId=$(this).val(),$sub=$('#category-y');
            if(!parentId){$sub.prop('disabled',true).html('<option value="">Select main category first</option>');return;}
            $sub.prop('disabled',true).html('<option value="">Loading...</option>');
            $.ajax({
                url:store_mm_ajax.ajax_url,type:'POST',
                data:{action:'store_mm_get_subcategories',nonce:store_mm_ajax.nonce,parent_id:parentId},
                success:r=>{if(r.success)$sub.prop('disabled',false).html(r.data);else $sub.html('<option value="">Error</option>');},
                error:()=>{$sub.html('<option value="">Error</option>');}
            });
        });
    }
    function initializePriceCalculator(){
        function calculate(){
            const price=parseFloat($('#estimated-price').val())||0,royaltyOpt=$('input[name="royalty_option"]:checked').val();
            let royalty=royaltyOpt==='custom'?parseFloat($('#custom-royalty').val())||10:10;
            const royaltyAmount=(price*royalty)/100,costs=price-royaltyAmount;
            $('#royalty-preview').text(store_mm_ajax.currency+' '+royaltyAmount.toFixed(store_mm_ajax.decimals));
            $('#costs-preview').text(store_mm_ajax.currency+' '+costs.toFixed(store_mm_ajax.decimals));
            $('#total-price').text(store_mm_ajax.currency+' '+price.toFixed(store_mm_ajax.decimals));
        }
        $('#estimated-price,#custom-royalty').on('input change',calculate);
        $('input[name="royalty_option"]').on('change',function(){
            if($(this).val()==='custom') $('#custom-royalty-field').slideDown(300);
            else $('#custom-royalty-field').slideUp(300);
            calculate();
        });
        calculate();
    }
    function initializeEventListeners(){
        $('.store-mm-next-tab').on('click',function(){switchTab($(this).data('next'));});
        $('.store-mm-prev-tab').on('click',function(){switchTab($(this).data('prev'));});
        $('.store-mm-step').on('click',function(){const step=$(this).data('step');if(step<currentTab)switchTab(step);});
        $('.store-mm-button-cancel').on('click',function(){if(confirm('Cancel? All data will be lost.'))window.location.href='/';});
        $('#store-mm-submission-form').on('submit',function(e){
            e.preventDefault();
            if(!validateCurrentTab())return;
            const $btn=$('#submit-design');
            $btn.prop('disabled',true).html('<span class="store-mm-submit-icon">⏳</span>'+store_mm_ajax.messages.submitting);
            const formData=new FormData(this);
            formData.append('nonce',store_mm_ajax.nonce);
            formData.append('action','store_mm_submit_design');
            $.ajax({
                url:store_mm_ajax.ajax_url,type:'POST',data:formData,processData:false,contentType:false,
                success:r=>{if(r.success)showSuccess(r.data);else alert('Error: '+r.data);$btn.html('🚀 Submit Design').prop('disabled',false);},
                error:()=>{alert(store_mm_ajax.messages.error);$btn.html('🚀 Submit Design').prop('disabled',false);}
            });
        });
        $(document).on('click','.store-mm-upload-preview-remove',function(){
            const index=$(this).data('index'),isImage=$(this).data('is-image');
            const inputId=isImage?'#product-images':'#design-files';
            $(this).closest('.store-mm-upload-preview-item').remove();
            const input=$(inputId)[0],files=Array.from(input.files);
            if(index<files.length){
                files.splice(index,1);
                const dt=new DataTransfer();
                files.forEach(f=>dt.items.add(f));
                input.files=dt.files;
            }
        });
    }
    function showSuccess(data){
        $('#success-message').html(`<p><strong>"${data.product_title}"</strong> submitted for review.</p><p><strong>ID:</strong> #${data.product_id}</p><p>Our team will review within 2-3 days.</p>`);
        $('#submit-another-btn').on('click',e=>{e.preventDefault();location.reload();});
        $('#store-mm-submission-form,.store-mm-progress').hide();
        $('#success-modal').fadeIn();
        $('html,body').animate({scrollTop:0},300);
    }
    // Initialize
    updateProgress();
    initializeFileUpload();
    initializeCategoryFilter();
    initializePriceCalculator();
    initializeEventListeners();
});