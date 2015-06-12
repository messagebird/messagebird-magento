<?php
require_once Mage::getBaseDir('lib').'/MessageBird/Client.php';

define('MBACCESSKEY', Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/accesskey',Mage::app()->getStore()));
define('MBORIGINATOR', Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/originator',Mage::app()->getStore()));
define('MBSELLERSPHONES', serialize(explode(",",Mage::getStoreConfig('smsconnectorconfig/messagebirdconfgroup/sellernumber',Mage::app()->getStore()))));

define('ISSENDONORDERPLACED', Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/enabled',Mage::app()->getStore()));
define('SENDORDERPLACEDTO', Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/sendtobuyerowner',Mage::app()->getStore()));
define('CUSTOMERMESSAGE', Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/messagecustomer',Mage::app()->getStore()));
define('SELLERMESSAGE', Mage::getStoreConfig('smsconnectorconfig/sendoncheckoutgroup/messageseller',Mage::app()->getStore()));

define('ISSENDONORDERSTATUSCHANGES', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/enabled',Mage::app()->getStore()));
define('STATUSESSELECTED', serialize(explode(',', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/orderstatuses',Mage::app()->getStore()))));
define('STATUSCHANGEDMESSAGE', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/statuschangedmessage',Mage::app()->getStore()));
define('STATESNONDEFAULTMESSAGES', serialize(array(
        'processing'=>Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/statustoshippedmessage',Mage::app()->getStore())
        )));

class MessageBird_SmsConnector_Model_Observer
{
    public function orderPlaced(Varien_Event_Observer $observer)
    {
        if(ISSENDONORDERPLACED) {

            $order = $observer->getEvent()->getOrder();

            $customerPhones = $this->_getCustomerPhones($order);

            $customerBodyMessage = $this->_filterMessageVariables($order, CUSTOMERMESSAGE);
            $sellerBodyMessage = $this->_filterMessageVariables($order, SELLERMESSAGE);
            $mbSellersPhones = unserialize(MBSELLERSPHONES);

            //Adds appropiate recipients according to configuration
            switch(SENDORDERPLACEDTO) {
                case "customer": //Customer
                    $this->_sendSms(MBORIGINATOR, $customerPhones, $customerBodyMessage);
                    break;
                case "seller": //Seller
                    $this->_sendSms(MBORIGINATOR, $mbSellersPhones, $sellerBodyMessage);
                    break;
                case "customerseller": //Customer, Seller
                    $this->_sendSms(MBORIGINATOR, $customerPhones, $customerBodyMessage);
                    $this->_sendSms(MBORIGINATOR, $mbSellersPhones, $sellerBodyMessage);
                    break;
                default:
                    $this->_sendSms(MBORIGINATOR, $customerPhones, $customerBodyMessage);
                    $this->_sendSms(MBORIGINATOR, $mbSellersPhones , $sellerBodyMessage);
                    break;
            }

        }
    }

    public function orderStatusChanged(Varien_Event_Observer $observer)
    {
        if(ISSENDONORDERSTATUSCHANGES) {
            $order = $observer->getEvent()->getOrder();
            $currentStatus = $order->getStatus();
            $currentState = $order->getState();
            $originalStatus = $order->getOrigData('status');
            $statusesSelected = unserialize(STATUSESSELECTED);

            //Status changed
            if($currentStatus != $originalStatus) {
                //Only send sms when the status changes to one of the selected ones.
                if(in_array($currentStatus, $statusesSelected)) {
                    $customerPhones = $this->_getCustomerPhones($order);

                    $bodyMessage = $this->_filterMessageVariables($order, $this->_getStatusChangedMessage($currentState));

                    $this->_sendSms(MBORIGINATOR, $customerPhones, $bodyMessage);
                }
            }
        }
    }

    private function _sendSms($originator, $recipients, $bodyMessage)
    {
        $client = new \MessageBird\Client(MBACCESSKEY);
        $Message = new \MessageBird\Objects\Message();
        $Message->originator = $originator;
        $Message->recipients = $recipients;
        $Message->body = $bodyMessage;

        try {
            $response = $client->messages->create($Message);
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
        $bodyMessage = STATUSCHANGEDMESSAGE;
        $statesNonDefaultMessages = unserialize(STATESNONDEFAULTMESSAGES);

        //If the new state needs a non-default message
        if(isset($statesNonDefaultMessages[$currentState])) {
            $bodyMessage = $statesNonDefaultMessages[$currentState];
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