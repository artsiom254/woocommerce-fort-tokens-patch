<?php
class Patch_Payfort_Fort_Payment extends Payfort_Fort_Payment
{

    private static $instance;
    private $pfHelper;
    private $pfConfig;
    private $pfOrder;

    public function __construct()
    {
        parent::__construct();
        $this->pfHelper   = Payfort_Fort_Helper::getInstance();
        $this->pfConfig   = Payfort_Fort_Config::getInstance();
        $this->pfOrder    = new Payfort_Fort_Order();
    }


    public static function getInstancePatch()
    {
        if (self::$instance === null) {
            self::$instance = new Patch_Payfort_Fort_Payment();
        }
        return self::$instance;
    }


    public function patchHandleFortResponse($fortParams = array(), $responseMode = 'online', $integrationType = 'redirection')
    {
        try {
            $responseParams  = $fortParams;
            $success         = false;
            $responseMessage = Payfort_Fort_Language::__('error_transaction_error_1');
            //$this->session->data['error'] = Payfort_Fort_Language::__('text_payment_failed').$params['response_message'];
            if (empty($responseParams)) {
                $this->pfHelper->log('Invalid fort response parameters (' . $responseMode . ')');
                throw new Exception($responseMessage);
            }

            if (!isset($responseParams['merchant_reference']) || empty($responseParams['merchant_reference'])) {
                $this->pfHelper->log("Invalid fort response parameters. merchant_reference not found ($responseMode) \n\n" . print_r($responseParams, 1));
                throw new Exception($responseMessage);
            }

            $orderId = $responseParams['merchant_reference'];
            $this->pfOrder->loadOrder($orderId);

            $paymentMethod = $this->pfOrder->getPaymentMethod();
            $this->pfHelper->log("Fort response parameters ($responseMode) for payment method ($paymentMethod) \n\n" . print_r($responseParams, 1));

            $notIncludedParams = array('signature', 'wc-ajax', 'wc-api',  'payfort_fort', 'integration_type', 'WordApp_launch', 'WordApp_mobile_site', 'WordApp_demo', 'WordApp_demo');

            $responseType          = $responseParams['response_message'];
            $signature             = $responseParams['signature'];
            $responseOrderId       = $responseParams['merchant_reference'];
            $responseStatus        = isset($responseParams['status']) ? $responseParams['status'] : '';
            $responseCode          = isset($responseParams['response_code']) ? $responseParams['response_code'] : '';
            $responseStatusMessage = $responseType;

            $responseGatewayParams = $responseParams;
            foreach ($responseGatewayParams as $k => $v) {
                if (in_array($k, $notIncludedParams)) {
                    unset($responseGatewayParams[$k]);
                }
            }
            $responseSignature = $this->pfHelper->calculateSignature($responseGatewayParams, 'response');
            // check the signature
            if (strtolower($responseSignature) !== strtolower($signature)) {
                $responseMessage = Payfort_Fort_Language::__('error_invalid_signature');
                $this->pfHelper->log(sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $signature, $responseSignature));
                // There is a problem in the response we got
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder('Invalid Signature.');
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                }
                else {
                    throw new Exception($responseMessage);
                }
            }
            if (empty($responseCode)) {
                //get order status
                $orderStaus = $this->pfOrder->getStatusId();
                if ($orderStaus == $this->pfConfig->getSuccessOrderStatusId()) {
                    $responseCode   = '00000';
                    $responseStatus = '02';
                }
                else {
                    $responseCode   = 'failed';
                    $responseStatus = '10';
                }
            }

            if ($integrationType == 'cc_merchant_page_h2h') {
                if ($responseCode == '20064' && isset($responseParams['3ds_url'])) {
                    if($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2) {
                        header('location:' . $responseParams['3ds_url']);
                    }
                    else{
                       echo '<script>window.top.location.href = "'.$responseParams['3ds_url'].'"</script>'; 
                    }
                    exit;
                }
            }
            if ($responseStatus == '01') {
                $responseMessage = Payfort_Fort_Language::__('text_payment_canceled');
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->cancelOrder();
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                }
                else {
                    throw new Exception($responseMessage);
                }
            }
            if (substr($responseCode, 2) != '000' && substr($responseCode, 2) != '062' && substr($responseCode, 2) != '063') {
                $responseMessage = sprintf(Payfort_Fort_Language::__('error_transaction_error_2'), $responseStatusMessage);
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder($responseStatusMessage);
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                }
                else {
                    throw new Exception($responseMessage);
                }
            }
            if (substr($responseCode, 2) == '000' || substr($responseCode, 2) == '062' || substr($responseCode, 2) == '063') {
                if ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_CC && ($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE || $integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2)) {
                    $host2HostParams = $this->patchMerchantPageNotifyFort($responseParams, $orderId);
                    return $this->patchHandleFortResponse($host2HostParams, 'online', 'cc_merchant_page_h2h');
                }
                else { //success order
                    $this->pfOrder->successOrder($responseParams, $responseMode);
                }
            }
            else {
                $responseMessage = sprintf(Payfort_Fort_Language::__('error_transaction_error_2'), Payfort_Fort_Language::__('error_response_unknown'));
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder('Unknown Response');
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                }
                else {
                    throw new Exception($responseMessage);
                }
            }
        } catch (Exception $e) {
            unset( WC()->session->order_awaiting_payment );
            $this->pfHelper->setFlashMsg($e->getMessage(), PAYFORT_FORT_FLASH_MSG_ERROR);
            return false;
        }
        return true;
    }

    private function patchMerchantPageNotifyFort($fortParams, $orderId)
    {
        //send host to host
        $this->pfOrder->loadOrder($orderId);

        $baseCurrency  = $this->pfHelper->getBaseCurrency();
        $orderCurrency = $this->pfOrder->getCurrencyCode();
        $currency      = $this->pfHelper->getFortCurrency($baseCurrency, $orderCurrency);
        $language      = $this->pfConfig->getLanguage();
        $postData      = array(
            'merchant_reference'  => $fortParams['merchant_reference'],
            'access_code'         => $this->pfConfig->getAccessCode(),
            'command'             => $this->pfConfig->getCommand(),
            'merchant_identifier' => $this->pfConfig->getMerchantIdentifier(),
            'customer_ip'         => $this->pfHelper->getCustomerIp(),
            'amount'              => $this->pfHelper->convertFortAmount($this->pfOrder->getTotal(), $this->pfOrder->getCurrencyValue(), $currency),
            'currency'            => strtoupper($currency),
            'customer_email'      => $this->pfOrder->getEmail(),
            'token_name'          => $fortParams['token_name'],
            'language'            => $language,
            'return_url'          => $this->pfHelper->getReturnUrl('responseOnline')
        );
        $customerName = $this->pfOrder->getCustomerName();
        if (!empty($customerName)) {
            $postData['customer_name'] = $this->pfOrder->getCustomerName();
        }
        //calculate request signature
        $signature             = $this->pfHelper->calculateSignature($postData, 'request');
        $postData['signature'] = $signature;

        $gatewayUrl = $this->pfHelper->getGatewayUrl('notificationApi');
        $this->pfHelper->log('Merchant Page Notify Api Request Params : ' . print_r($postData, 1));

        $response = $this->callApi($postData, $gatewayUrl);

        return $response;
    }

}

?>