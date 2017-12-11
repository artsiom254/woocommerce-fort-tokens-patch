<?php

/* Plugin Name: Patch for Payfort (FORT)
 * Description: Patch add functionality for using saved tokens
 * Version:     0.1
 */
$active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
if (in_array('woocommerce/woocommerce.php', $active_plugins) && in_array('woocommerce-fort/woocommerce-fort.php', $active_plugins)) {

    if (!defined('PATCH_PAYFORT_FORT_DIR')) {
        define('PATCH_PAYFORT_FORT_DIR', plugin_dir_path(__FILE__));
    }

    if (!defined('PATCH_PAYFORT_FORT_URL')) {
        define('PATCH_PAYFORT_FORT_URL', plugin_dir_url(__FILE__));
    }

    add_action('plugins_loaded', 'patch_init_payfort_fort_payment_gateway', 100);

    function patch_init_payfort_fort_payment_gateway()
    {
        require 'classes/class-woocommerce-fort-tokens-patch.php';
    }


    add_filter('woocommerce_payment_gateways', 'patch_add_payfort_fort_gateway');

    function patch_add_payfort_fort_gateway($gateways)
    {
        $key = array_search('WC_Gateway_Payfort',$gateways);
        if ($key){
            unset($gateways[$key]);
        }
        $gateways[] = 'Patch_WC_Gateway_Payfort';

        return $gateways;
    }

    function patch_woocommerce_payfort_fort_actions()
    {
        if (isset($_GET['wc-ajax']) && !empty($_GET['wc-ajax'])) {
            WC()->payment_gateways();
            switch ($_GET['wc-ajax'])
            {
                case 'wc_gateway_payfort_patch_save_token':
                    do_action('patch_woocommerce_wc_gateway_payfort_fort_save_token');
                    break;
                case 'wc_gateway_payfort_patch_get_signature':
                    do_action('patch_woocommerce_wc_gateway_payfort_fort_get_signature');
                    break;
                case 'wc_gateway_payfort_patch_get_card_data':
                    do_action('patch_woocommerce_wc_gateway_payfort_fort_get_card_data');
                    break;
            }
        }
    }

    add_action('init', 'patch_woocommerce_payfort_fort_actions', 500);

    add_action('patch_woocommerce_wc_gateway_payfort_fort_get_signature', 'get_signature');

    function get_signature(){
        parse_str($_POST['data'], $arrData);
        unset($arrData['signature']);
        $helper = Payfort_Fort_Helper::getInstance();
        echo $helper->calculateSignature($arrData);
        die();
    }

    add_action('patch_woocommerce_wc_gateway_payfort_fort_get_card_data', 'get_card_data');

    function get_card_data(){
        $response_params = array_merge($_GET, $_POST);
        $token_id = $response_params['data'];
        $token = WC_Payment_Tokens::get( $token_id );

        wp_send_json_success( array(
            'card_number' => $token->get_meta('card_number',true),
            'card_holder_name' => $token->get_meta('card_holder_name', true),
            'expiry_year' => substr($token->get_expiry_year(), -2) ,
            'expiry_month' => $token->get_expiry_month()
        ));
        wp_die();
    }
}
