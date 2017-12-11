<?php
require_once PATCH_PAYFORT_FORT_DIR . 'lib/payfortFort/init.php';

class Patch_WC_Gateway_Payfort extends WC_Gateway_Payfort
{
    public $pfPaymentPatch;

    public function __construct()
    {
        parent::__construct();
        $this->pfPaymentPatch           = Patch_Payfort_Fort_Payment::getInstancePatch();
        remove_all_actions('woocommerce_wc_gateway_payfort_fort_responseOnline');
        remove_all_actions('woocommerce_wc_gateway_payfort_fort_merchantPageResponse');
        add_action('woocommerce_wc_gateway_payfort_fort_merchantPageResponse', array(&$this, 'patchMerchantPageResponse'));
        add_action('woocommerce_wc_gateway_payfort_fort_responseOnline', array(&$this, 'patchResponseOnline'));

        add_action('wp_enqueue_scripts', array($this, 'payment_scripts_patch'));

        add_action('patch_woocommerce_wc_gateway_payfort_fort_save_token',  array($this, 'patch_save_token'));
    }

    public function patch_save_token(){
        $this->add_token_MP2(false);
        die();
    }

    function payment_scripts_patch()
    {
        global $woocommerce;
        if (!is_checkout()) {
            return;
        }
        wp_enqueue_script('patch-fortjs', PATCH_PAYFORT_FORT_URL . 'assets/js/payfort_patch.js', array(), '0.1', true);
    }
    public function patchResponseOnline()
    {
        $this->patchSaveCard();
        $this->_patchHandleResponse('online');
    }

    public function patchMerchantPageResponse()
    {
        $this->_patchHandleResponse('online', $this->pfConfig->getCcIntegrationType());
    }

    private function _patchHandleResponse($response_mode = 'online', $integration_type = PAYFORT_FORT_INTEGRATION_TYPE_REDIRECTION)
    {
        global $woocommerce;
        $response_params = array_merge($_GET, $_POST); //never use $_REQUEST, it might include PUT .. etc
        if (isset($response_params['merchant_reference'])) {
            $success = $this->pfPaymentPatch->patchHandleFortResponse($response_params, $response_mode, $integration_type);
            if ($success) {
                $order = new WC_Order($response_params['merchant_reference']);
                WC()->session->set('refresh_totals', true);
                $redirectUrl = $this->get_return_url($order);
                do_action('woocommerce_after_payfort_payment', $order);
            }
            else {
                $redirectUrl = esc_url($woocommerce->cart->get_checkout_url());
            }
            echo '<script>window.top.location.href = "' . $redirectUrl . '"</script>';
            exit;
        }
    }

    public function patchSaveCard(){
        $response_params = array_merge($_GET, $_POST);
        if (isset($response_params['status'])
            && $response_params['status'] == '14'
            && $_COOKIE['savecard'] && $_COOKIE['savecard'] == 1
        ){
            $this->_add_token($response_params);
            unset($_COOKIE['savecard']);
        }
    }

    public function _add_token($response_params){
        if (class_exists( 'WC_Payment_Token_CC' )
            && get_current_user_id()) {
            $token_id = $this->_check_token_exist(substr($response_params['card_number'], -4),$response_params['payment_option'], $response_params['token_name']);
            if ($token_id){
                $token = WC_Payment_Tokens::get($token_id);
            }else{
                $token = new WC_Payment_Token_CC();
            }
            $order = wc_get_order($response_params['merchant_reference']);
            $address = $order?$order->get_formatted_billing_address():'';
            $token->set_token($response_params['token_name']);
            $token->set_gateway_id('payfort');
            $token->set_last4(substr($response_params['card_number'], -4));
            $token->set_expiry_year(substr(date('Y'), 0, 2) . substr($response_params['expiry_date'], 0, 2));
            $token->set_expiry_month(substr($response_params['expiry_date'], -2));
            $token->set_card_type($response_params['payment_option']);
            $token->set_user_id(get_current_user_id());
            $token->add_meta_data('token_address' , $address, true);
            $token->add_meta_data('card_holder_name' , $response_params['card_holder_name'], true);
            $token->save();
        }
    }


    public function _check_token_exist($last4, $type, $token){
        $tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(),'payfort');
        if (!empty($tokens)){
            foreach ($tokens as $key => $value){
                if (($value->get_last4() == $last4 && $value->get_card_type() == $type) || $value->get_token() == $token){
                    return $key;
                }
            }
        }
        return false;
    }

    public function add_token_MP2($response_params){
        if ($this->pfConfig->getCcIntegrationType() == 'merchantPage2'){
            if (!$response_params){
                $response_params = array_merge($_GET, $_POST);
            }
            if ($_COOKIE['savecard'] && $_COOKIE['savecard'] == 1
            ){
                if (class_exists( 'WC_Payment_Token_CC' )
                    && get_current_user_id()) {
                    $payment_option = $this->validatecard($response_params['card_number']);
                    $token_id = $this->_check_token_exist(substr($response_params['card_number'], -4),$payment_option, null);
                    if ($token_id){
                        $token = WC_Payment_Tokens::get($token_id);
                    }else{
                        $token = new WC_Payment_Token_CC();
                        $token->set_token($response_params['merchant_reference']);
                    }
                    $order = wc_get_order($response_params['merchant_reference']);
                    $address = $order?$order->get_formatted_billing_address():'';

                    $token->set_gateway_id('payfort');
                    $token->set_last4(substr($response_params['card_number'], -4));
                    $token->set_expiry_year(substr(date('Y'), 0, 2) . substr($response_params['expiry_date'], 0, 2));
                    $token->set_expiry_month(substr($response_params['expiry_date'], -2));
                    $token->set_card_type($payment_option);
                    $token->set_user_id(get_current_user_id());
                    $token->add_meta_data('token_address' , $address, true);
                    $token->add_meta_data('card_holder_name' , $response_params['card_holder_name'], true);
                    $token->add_meta_data('card_number' , $response_params['card_number'], true);
                    $token->save();
                }
                unset($_COOKIE['savecard']);
            }
        }
    }

    function validatecard($number)
    {
        global $type;

        $cardtype = array(
            "visa"       => "/^4[0-9]{12}(?:[0-9]{3})?$/",
            "mastercard" => "/^5[1-5][0-9]{14}$/",
            "amex"       => "/^3[47][0-9]{13}$/",
            "discover"   => "/^6(?:011|5[0-9]{2})[0-9]{12}$/",
        );

        if (preg_match($cardtype['visa'],$number))
        {
            $type= "visa";
            return 'VISA';

        }
        else if (preg_match($cardtype['mastercard'],$number))
        {
            $type= "mastercard";
            return 'MASTERCARD';
        }
        else if (preg_match($cardtype['amex'],$number))
        {
            $type= "amex";
            return 'AMEX';

        }
        else if (preg_match($cardtype['discover'],$number))
        {
            $type= "discover";
            return 'DISCOVER';
        }
        else
        {
            return false;
        }
    }
}
