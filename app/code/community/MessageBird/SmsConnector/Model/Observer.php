<?php
require_once(Mage::getBaseDir('lib').'/MessageBird/Client.php');

class MessageBird_SmsConnector_Model_Observer
{
    private $mbAccesskey;
    private $mbOriginator;
    private $mbSellersPhones;

    private $sendOnOrderPlaced;
    private $sendPlacedOrderTo;
    private $customerMessage;
    private $sellerMessage;

    private $sendOnOrderStatusChanges;
    private $statusesSelected;
    private $statusChangedMessage;
    private $statesNonDefaultMessages;

    private $client;

    public function __construct()
    {
        $this->mbAccesskey = Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/accesskey',Mage::app()->getStore());
        $this->mbOriginator = Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/originator',Mage::app()->getStore());
        $this->mbSellersPhones = explode(",",Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/sellernumber',Mage::app()->getStore()));

        $this->sendOnOrderPlaced = Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/enabled',Mage::app()->getStore());
        $this->sendPlacedOrderTo = Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/sendtobuyerowner',Mage::app()->getStore());
        $this->customerMessage = Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/messagecustomer',Mage::app()->getStore());
        $this->sellerMessage = Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/messageseller',Mage::app()->getStore());

        $this->sendOnOrderStatusChanges = Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/enabled',Mage::app()->getStore());
        $this->statusesSelected = explode(',', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/orderstatuses',Mage::app()->getStore()));
        $this->statusChangedMessage =  Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/statuschangedmessage',Mage::app()->getStore());
        $this->statesNonDefaultMessages = array(
            'processing'=>Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/statustoshippedmessage',Mage::app()->getStore())
        );

        $this->client = new \MessageBird\Client($this->mbAccesskey);
    }

    public function orderPlaced(Varien_Event_Observer $observer)
    {
        if($this->sendOnOrderPlaced) {

            $order = $observer->getEvent()->getOrder();

            $customerPhones = $this->_getCustomerPhones($order);

            $customerBodyMessage = $this->_filterMessageVariables($order, $this->customerMessage);
            $sellerBodyMessage = $this->_filterMessageVariables($order, $this->sellerMessage);

            //Adds appropiate recipients according to configuration
            switch($this->sendPlacedOrderTo) {
                case "customer": //Customer
                    $this->_sendSms($this->mbOriginator, $customerPhones, $customerBodyMessage);
                    break;
                case "seller": //Seller
                    $this->_sendSms($this->mbOriginator, $this->mbSellersPhones, $sellerBodyMessage);
                    break;
                case "customerseller": //Customer, Seller
                    $this->_sendSms($this->mbOriginator, $customerPhones, $customerBodyMessage);
                    $this->_sendSms($this->mbOriginator, $this->mbSellersPhones, $sellerBodyMessage);
                    break;
                default:
                    break;
            }

        }
    }

    public function orderStatusChanged(Varien_Event_Observer $observer)
    {
        if($this->sendOnOrderStatusChanges) {
            $order = $observer->getEvent()->getOrder();
            $currentStatus = $order->getStatus();
            $currentState = $order->getState();
            $originalStatus = $order->getOrigData('status');

            //Status changed
            if($currentStatus != $originalStatus) {
                //Only send sms when the status changes to one of the selected ones.
                if(in_array($currentStatus, $this->statusesSelected)) {
                    $customerPhones = $this->_getCustomerPhones($order);

                    $bodyMessage = $this->_filterMessageVariables($order, $this->_getStatusChangedMessage($currentState));

                    $this->_sendSms($this->mbOriginator, $customerPhones, $bodyMessage);
                }
            }
        }
    }

    private function _sendSms($originator, $recipients, $bodyMessage)
    {
        $Message = new \MessageBird\Objects\Message();
        $Message->originator = $originator;
        $Message->recipients = $recipients;
        $Message->body = $bodyMessage;

        try {
            $response = $this->client->messages->create($Message);
            return $response;

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
            return $error;
        }
    }

    private function _filterMessageVariables($order, $message)
    {
        $customerFirstName = $order->getShippingAddress()->getFirstname();
        $customerLastName = $order->getShippingAddress()->getLastname();
        $orderId = $order->getIncrementId();
        $currentOrderStatus = $order->getStatusLabel();

        $message = str_replace(":firstname:", $customerFirstName, $message);
        $message = str_replace(":lastname:", $customerLastName, $message);
        $message = str_replace(":orderid:", $orderId, $message);
        $message = str_replace(":orderstatus:", $currentOrderStatus, $message);

        return $message;
    }

    private function _getStatusChangedMessage($currentState)
    {
        $bodyMessage = $this->statusChangedMessage;

        //If the new state needs a non-default message
        if(isset($this->statesNonDefaultMessages[$currentState])) {
            $bodyMessage = $this->statesNonDefaultMessages[$currentState];
        }

        return $bodyMessage;
    }

    private function _getCustomerPhones($order)
    {
        $sAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();


        $customerPhones = array($sAddress->getTelephone());
        if(!in_array($billingAddress->getTelephone(),$customerPhones)) {
            $customerPhones[] = $billingAddress->getTelephone();
        }

        return $customerPhones;
    }
}