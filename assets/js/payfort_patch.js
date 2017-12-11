/**
 * Created by user on 11/9/17.
 */
 function save_token_MP2(merchantPage2FormId) {
    var data = jQuery(merchantPage2FormId).serialize();
    jQuery.ajax({
        'url': woocommerce_params.wc_ajax_url.toString().replace("%%endpoint%%","wc_gateway_payfort_patch_save_token"),
        'type': 'POST',
        'dataType': 'json',
        'data': data,
        'async': false
    }).done(function (response) {
       console.log(response);
    });
}



jQuery(function ($) {
   $(document).on('submit', '#frm_payfort_fort_payment', function () {
       save_token_MP2(this);
   } );
});

jQuery(function ($) {
    $(document).on('change', '#change_card', function () {
        if( jQuery('#payfort_fort_cc_integration_type').val() == 'merchantPage2'){
            changeCardMerchant2(this);
        }else {
            changeCardMerchant(this);
        }
    });
});

function changeCardMerchant(cart) {
    var form = $('form#frm_payfort_fort_payment');
    form.append('<input type="hidden" name="token_name" value="'+$(cart).val()+'">');
    var formData = form.serialize();
    jQuery.post(
        woocommerce_params.wc_ajax_url.toString().replace("%%endpoint%%","wc_gateway_payfort_patch_get_signature"),
        {
            'action': 'get_signature',
            'data': formData
        },
        function(response) {
            console.log(response);
            form.find('input[name=signature]').val(response);
            form.submit();
        }
    );
}

function changeCardMerchant2(cart) {
    var token_id = jQuery(cart).find(':selected').data('id');
    jQuery.post(
        woocommerce_params.wc_ajax_url.toString().replace("%%endpoint%%","wc_gateway_payfort_patch_get_card_data"),
        {
            'action': 'get_card_data',
            'data': token_id
        },
        function(response) {
            if (response.success){
                var data = response.data;
                jQuery('#payfort_fort_card_number').val(data.card_number);
                jQuery('#payfort_fort_card_holder_name').val(data.card_holder_name);
                jQuery('#payfort_fort_expiry_month').val(data.expiry_month).change();
                jQuery('#payfort_fort_expiry_year').val(data.expiry_year).change();
            }
        }
    );
}