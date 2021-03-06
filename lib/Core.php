<?php

namespace Worldpaycorp;

class Core
{

    const TEST_ENV_URL = 'https://secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    const PROD_ENV_URL = 'https://secure.worldpay.com/jsp/merchant/xml/paymentService.jsp';
    private $envConfig = ['version' => '1.4'];
    private $request;
    public $response;
    public $lastError;
    public $exponent = 2;

    public function __construct()
    {

    }

    public function init($env, $merchantCode, $installationId, $curlPassword)
    {
        $this->envConfig['url'] = $env;
        $this->envConfig['installationId'] = $installationId;
        $this->envConfig['merchantCode'] = $merchantCode;
        $this->envConfig['curlPassword'] = $curlPassword;
    }

    public function generateXml($orderCode, $data = null)
    {
        $implementation = new \DOMImplementation();

        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->appendChild($implementation->createDocumentType('paymentService PUBLIC "-//WorldPay/DTD WorldPay PaymentService v1//EN"
  "http://dtd.worldpay.com/paymentService_v1.dtd"'));

        $paymentService = $domtree->createElement('paymentService');
        $payAtr = $domtree->createAttribute('version');
        $payAtr->value = $this->envConfig['version'];
        $paymentService->appendChild($payAtr);
        $payAtr = $domtree->createAttribute('merchantCode');
        $payAtr->value = $this->envConfig['merchantCode'];
        $paymentService->appendChild($payAtr);
        $paymentService = $domtree->appendChild($paymentService);

        if (!$data) {

            $inquiry = $domtree->createElement('inquiry');
            $orderInquiry = $domtree->createElement('orderInquiry');
            $inquiry->appendChild($orderInquiry);
            $payAtr = $domtree->createAttribute('orderCode');
            $payAtr->value = $orderCode;
            $orderInquiry->appendChild($payAtr);
            $paymentService->appendChild($inquiry);

        } elseif ($data == 'cancelOrRefund' || $data == 'cancel') {
            $modify = $domtree->createElement('modify');
            $orderModification = $domtree->createElement('orderModification');
            $modify->appendChild($orderModification);
            $payAtr = $domtree->createAttribute('orderCode');
            $payAtr->value = $orderCode;
            $orderModification->appendChild($payAtr);
            $orderModification->appendChild($domtree->createElement($data));
            $paymentService->appendChild($modify);
        } else {

            $submit = $domtree->createElement('submit');
            $submit = $paymentService->appendChild($submit);

            $order = $domtree->createElement('order');
            $order = $submit->appendChild($order);
            $payAtr = $domtree->createAttribute('orderCode');
            $payAtr->value = $orderCode;
            $order->appendChild($payAtr);
            $payAtr = $domtree->createAttribute('installationId');
            $payAtr->value = $this->envConfig['installationId'];
            $order->appendChild($payAtr);

            $order->appendChild($domtree->createElement('description', '### ' . $data['description']));

            $amount = $domtree->createElement('amount');
            $payAtr = $domtree->createAttribute('currencyCode');
            $payAtr->value = $data['currencyCode'];
            $amount->appendChild($payAtr);
            $payAtr = $domtree->createAttribute('exponent');
            $payAtr->value = $this->exponent;
            $amount->appendChild($payAtr);
            $payAtr = $domtree->createAttribute('value');
            $payAtr->value = $data['amount'];
            $amount->appendChild($payAtr);
            $order->appendChild($amount);

            $orderContent = $domtree->createElement('orderContent');
            $CDATA = $domtree->createCDATASection('');
            $orderContent->appendChild($CDATA);
            $order->appendChild($orderContent);

            $paymentMethodMask = $domtree->createElement('paymentMethodMask');
            $include = $domtree->createElement('include');
            $payAtr = $domtree->createAttribute('code');
            $payAtr->value = 'ALL';
            $include->appendChild($payAtr);
            $paymentMethodMask->appendChild($include);
            $order->appendChild($paymentMethodMask);


            $shopper = $domtree->createElement('shopper');
            $shopperEmailAddress = $domtree->createElement('shopperEmailAddress', $data['email']);
            $shopper->appendChild($shopperEmailAddress);
            $order->appendChild($shopper);

            $billingAddress = $domtree->createElement('shippingAddress');
            $address = $domtree->createElement('address');
            $billingAddress->appendChild($address);
            $address->appendChild($domtree->createElement('firstName', $data['firstName']));
            $address->appendChild($domtree->createElement('lastName', $data['lastName']));
            $address->appendChild($domtree->createElement('street', htmlentities(mb_substr($data['address1'], 0, 60))));
            $address->appendChild($domtree->createElement('postalCode', $data['postalCode']));
            $address->appendChild($domtree->createElement('city', $data['city']));
            $address->appendChild($domtree->createElement('countryCode', $data['countryCode']));
            $order->appendChild($billingAddress);
        }

        return $this->request = $domtree->saveXML();
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }

    public function query()
    {
        $soap = curl_init($this->envConfig['url']);
        curl_setopt($soap, CURLOPT_POST, 1);
        curl_setopt($soap, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($soap, CURLOPT_USERPWD, $this->envConfig['merchantCode'] . ":" . $this->envConfig['curlPassword']);
        curl_setopt($soap, CURLOPT_HTTPHEADER,
            ['Content-Type: text/xml; charset=utf-8',
                'Content-Length: ' . strlen($this->request)]);
        curl_setopt($soap, CURLOPT_POSTFIELDS, $this->request);
        $response = curl_exec($soap);
        curl_close($soap);
        return $this->parseXml($response);
    }


    public function parseXml($xml)
    {
        $xmlData = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        return json_decode(json_encode($xmlData), true);
    }

    public function validateIpn($ipn)
    {
        if (!is_array($ipn))
            return false;
        return $this->checkOrder($ipn['OrderCode'], $ipn['PaymentStatus']);
    }

    public function createOrder($orderId, $description, $amount, $buyerEmail, $billAdress, $billPostalCode, $billCity, $currencyCode = 'GBP', $billCountryCode = 'GB', $additionalData = null)
    {
        $data['description'] = $description;
        $data['currencyCode'] = $currencyCode;
        $data['amount'] = $amount;
        $data['address1'] = $billAdress;
        $data['postalCode'] = $billPostalCode;
        $data['city'] = $billCity;
        $data['countryCode'] = $billCountryCode;
        $data['email'] = $buyerEmail;
        $data['firstName'] = isset($additionalData['firstName']) ? $additionalData['firstName'] : 'NoName';
        $data['lastName'] = isset($additionalData['lastName']) ? $additionalData['lastName'] : 'NoName';

        $this->generateXml($orderId, $data);
        return $this->query();
    }

    public function checkOrder(string $orderId, $status = false)
    {
        $order = $this->orderStatus($orderId);
        $status = $status ? $status : 'CAPTURED';
        if ($order && isset($order['reply']['orderStatus']['payment']) && $order['reply']['orderStatus']['payment']['lastEvent'] == $status)
            return true;
        return false;
    }


    public function orderStatus(string $orderId)
    {
        $this->generateXml($orderId, null);
        return $this->query();
    }

    public function cancelOrder(string $orderId)
    {
        $this->generateXml($orderId, 'cancel');
        return $this->query();
    }
}