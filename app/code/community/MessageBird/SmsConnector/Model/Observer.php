<?php
require_once Mage::getBaseDir('lib').'/MessageBird/Client.php';

define('TIMEZONE', Mage::getStoreConfig('general/locale/timezone',Mage::app()->getStore()));

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
define('SCHEDULESHIPPEDMESSAGE', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/scheduleshippedmessage',Mage::app()->getStore()));
define('SCHEDULESHIPPEDMESSAGETIME', Mage::getStoreConfig('smsconnectorconfig/sendonorderstatuschangegroup/statustoshippedmessageschedule',Mage::app()->getStore()));
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
                    $messageScheduledTime = null;

                    if($currentState == 'processing') {
                        IF(SCHEDULESHIPPEDMESSAGE) {
                            $scheduledDateTime = $this->_getScheduleDateTime(SCHEDULESHIPPEDMESSAGETIME);
                            $messageScheduledTime = $scheduledDateTime->format('Y-m-d\TH:i:sP');
                        }
                    }

                    $this->_sendSms(MBORIGINATOR, $customerPhones, $bodyMessage, $messageScheduledTime);
                }
            }
        }
    }

    private function _sendSms($originator, $recipients, $bodyMessage, $messageScheduledTime = null)
    {
        $client = new \MessageBird\Client(MBACCESSKEY);
        $extensionVersion = Mage::getConfig()->getNode()->modules->MessageBird_SmsConnector->version;
        $magentoVersion = Mage::getVersion();

        $Message = new \MessageBird\Objects\Message();
        $Message->originator = $originator;
        $Message->recipients = $recipients;
        $Message->body = $bodyMessage;
        $Message->reference = 'MessageBird/Magento/' . $extensionVersion . ' Magento/' . $magentoVersion;

        if($messageScheduledTime) {
            $Message->scheduledDatetime = $messageScheduledTime;
        }

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
        $trackInfo = $this->_getTrackingInfo($order);

        $message = str_replace(":firstname:", $customerFirstName, $message);
        $message = str_replace(":lastname:", $customerLastName, $message);
        $message = str_replace(":orderid:", $orderId, $message);
        $message = str_replace(":orderstatus:", $currentOrderStatus, $message);
        $message = str_replace(":trackingnumber:", $trackInfo, $message);

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
        $countryCode = $billingAddress->getCountry();

        $customerPhones = array($this->_getValidPhoneNumber($sAddress->getTelephone(),$countryCode));
        $billingPhone = $this->_getValidPhoneNumber($billingAddress->getTelephone(),$countryCode);

        if(!in_array($billingPhone,$customerPhones)) {
            $customerPhones[] = $billingPhone;
        }

        return $customerPhones;
    }

    private function _getValidPhoneNumber($phone, $country)
    {
        $validPhone = preg_replace('/\D/', '',$phone);

        //Trim first 00 if number starts with 00 (e.g. 0031...)
        if(substr($validPhone,0,2) === "00") {
            $validPhone = ltrim($validPhone,'00');
        } else {
            $validPhone = ltrim($validPhone,'0');
        }

        $countryCallingCode = $this->_getCountryCallingCode($country);

        if($countryCallingCode != 0) {
            if ($this->_phoneHasCountryCallingCode($validPhone, $countryCallingCode)) {
                $validPhone = "+".$validPhone;
            } else {
                $validPhone = "+".$countryCallingCode.$validPhone;
            }
        }

        return $validPhone;
    }

    private function _phoneHasCountryCallingCode($phone, $countryCallingCode)
    {
        return substr($phone,0,strlen($countryCallingCode)) === $countryCallingCode;
    }

    private function _getCountryCallingCode($country)
    {
        $countries = array();
        $countries['AF'] = 93;
        $countries['AL'] = 355;
        $countries['DZ'] = 213;
        $countries['AS'] = 1;
        $countries['AD'] = 376;
        $countries['AO'] = 244;
        $countries['AI'] = 1;
        $countries['AG'] = 1;
        $countries['AR'] = 54;
        $countries['AM'] = 374;
        $countries['AW'] = 297;
        $countries['AU'] = 61;
        $countries['AT'] = 43;
        $countries['AZ'] = 994;
        $countries['BH'] = 973;
        $countries['BD'] = 880;
        $countries['BB'] = 1;
        $countries['BY'] = 375;
        $countries['BE'] = 32;
        $countries['BZ'] = 501;
        $countries['BJ'] = 229;
        $countries['BM'] = 1;
        $countries['BT'] = 975;
        $countries['BO'] = 591;
        $countries['BA'] = 387;
        $countries['BW'] = 267;
        $countries['BR'] = 55;
        $countries['IO'] = 246;
        $countries['VG'] = 1;
        $countries['BN'] = 673;
        $countries['BG'] = 359;
        $countries['BF'] = 226;
        $countries['MM'] = 95;
        $countries['BI'] = 257;
        $countries['KH'] = 855;
        $countries['CM'] = 237;
        $countries['CA'] = 1;
        $countries['CV'] = 238;
        $countries['KY'] = 1;
        $countries['CF'] = 236;
        $countries['TD'] = 235;
        $countries['CL'] = 56;
        $countries['CN'] = 86;
        $countries['CO'] = 57;
        $countries['KM'] = 269;
        $countries['CK'] = 682;
        $countries['CR'] = 506;
        $countries['CI'] = 225;
        $countries['HR'] = 385;
        $countries['CU'] = 53;
        $countries['CY'] = 357;
        $countries['CZ'] = 420;
        $countries['CD'] = 243;
        $countries['DK'] = 45;
        $countries['DJ'] = 253;
        $countries['DM'] = 1;
        $countries['DO'] = 1;
        $countries['EC'] = 593;
        $countries['EG'] = 20;
        $countries['SV'] = 503;
        $countries['GQ'] = 240;
        $countries['ER'] = 291;
        $countries['EE'] = 372;
        $countries['ET'] = 251;
        $countries['FK'] = 500;
        $countries['FO'] = 298;
        $countries['FM'] = 691;
        $countries['FJ'] = 679;
        $countries['FI'] = 358;
        $countries['FR'] = 33;
        $countries['GF'] = 594;
        $countries['PF'] = 689;
        $countries['GA'] = 241;
        $countries['GE'] = 995;
        $countries['DE'] = 49;
        $countries['GH'] = 233;
        $countries['GI'] = 350;
        $countries['GR'] = 30;
        $countries['GL'] = 299;
        $countries['GD'] = 1;
        $countries['GP'] = 590;
        $countries['GU'] = 1;
        $countries['GT'] = 502;
        $countries['GN'] = 224;
        $countries['GW'] = 245;
        $countries['GY'] = 592;
        $countries['HT'] = 509;
        $countries['HN'] = 504;
        $countries['HK'] = 852;
        $countries['HU'] = 36;
        $countries['IS'] = 354;
        $countries['IN'] = 91;
        $countries['ID'] = 62;
        $countries['IR'] = 98;
        $countries['IQ'] = 964;
        $countries['IE'] = 353;
        $countries['IL'] = 972;
        $countries['IT'] = 39;
        $countries['JM'] = 1;
        $countries['JP'] = 81;
        $countries['JO'] = 962;
        $countries['KZ'] = 7;
        $countries['KE'] = 254;
        $countries['KI'] = 686;
        $countries['XK'] = 381;
        $countries['KW'] = 965;
        $countries['KG'] = 996;
        $countries['LA'] = 856;
        $countries['LV'] = 371;
        $countries['LB'] = 961;
        $countries['LS'] = 266;
        $countries['LR'] = 231;
        $countries['LY'] = 218;
        $countries['LI'] = 423;
        $countries['LT'] = 370;
        $countries['LU'] = 352;
        $countries['MO'] = 853;
        $countries['MK'] = 389;
        $countries['MG'] = 261;
        $countries['MW'] = 265;
        $countries['MY'] = 60;
        $countries['MV'] = 960;
        $countries['ML'] = 223;
        $countries['MT'] = 356;
        $countries['MH'] = 692;
        $countries['MQ'] = 596;
        $countries['MR'] = 222;
        $countries['MU'] = 230;
        $countries['YT'] = 262;
        $countries['MX'] = 52;
        $countries['MD'] = 373;
        $countries['MC'] = 377;
        $countries['MN'] = 976;
        $countries['ME'] = 382;
        $countries['MS'] = 1;
        $countries['MA'] = 212;
        $countries['MZ'] = 258;
        $countries['NA'] = 264;
        $countries['NR'] = 674;
        $countries['NP'] = 977;
        $countries['NL'] = 31;
        $countries['AN'] = 599;
        $countries['NC'] = 687;
        $countries['NZ'] = 64;
        $countries['NI'] = 505;
        $countries['NE'] = 227;
        $countries['NG'] = 234;
        $countries['NU'] = 683;
        $countries['NF'] = 672;
        $countries['KP'] = 850;
        $countries['MP'] = 1;
        $countries['NO'] = 47;
        $countries['OM'] = 968;
        $countries['PK'] = 92;
        $countries['PW'] = 680;
        $countries['PS'] = 970;
        $countries['PA'] = 507;
        $countries['PG'] = 675;
        $countries['PY'] = 595;
        $countries['PE'] = 51;
        $countries['PH'] = 63;
        $countries['PL'] = 48;
        $countries['PT'] = 351;
        $countries['PR'] = 1;
        $countries['QA'] = 974;
        $countries['CG'] = 242;
        $countries['RE'] = 262;
        $countries['RO'] = 40;
        $countries['RU'] = 7;
        $countries['RW'] = 250;
        $countries['BL'] = 590;
        $countries['SH'] = 290;
        $countries['KN'] = 1;
        $countries['MF'] = 590;
        $countries['PM'] = 508;
        $countries['VC'] = 1;
        $countries['WS'] = 685;
        $countries['SM'] = 378;
        $countries['ST'] = 239;
        $countries['SA'] = 966;
        $countries['SN'] = 221;
        $countries['RS'] = 381;
        $countries['SC'] = 248;
        $countries['SL'] = 232;
        $countries['SG'] = 65;
        $countries['SK'] = 421;
        $countries['SI'] = 386;
        $countries['SB'] = 677;
        $countries['SO'] = 252;
        $countries['ZA'] = 27;
        $countries['KR'] = 82;
        $countries['ES'] = 34;
        $countries['LK'] = 94;
        $countries['LC'] = 1;
        $countries['SD'] = 249;
        $countries['SR'] = 597;
        $countries['SZ'] = 268;
        $countries['SE'] = 46;
        $countries['CH'] = 41;
        $countries['SY'] = 963;
        $countries['TW'] = 886;
        $countries['TJ'] = 992;
        $countries['TZ'] = 255;
        $countries['TH'] = 66;
        $countries['BS'] = 1;
        $countries['GM'] = 220;
        $countries['TL'] = 670;
        $countries['TG'] = 228;
        $countries['TK'] = 690;
        $countries['TO'] = 676;
        $countries['TT'] = 1;
        $countries['TN'] = 216;
        $countries['TR'] = 90;
        $countries['TM'] = 993;
        $countries['TC'] = 1;
        $countries['TV'] = 688;
        $countries['UG'] = 256;
        $countries['UA'] = 380;
        $countries['AE'] = 971;
        $countries['GB'] = 44;
        $countries['US'] = 1;
        $countries['UY'] = 598;
        $countries['VI'] = 1;
        $countries['UZ'] = 998;
        $countries['VU'] = 678;
        $countries['VA'] = 39;
        $countries['VE'] = 58;
        $countries['VN'] = 84;
        $countries['WF'] = 681;
        $countries['YE'] = 967;
        $countries['ZM'] = 260;
        $countries['ZW'] = 263;

        return (isset($countries[$country])) ? ''.$countries[$country]: 0 ;
    }

    private function _getTrackingInfo($order)
    {
        $trackInfo = array();
        $shipmentCollection = $order->getShipmentsCollection();
        foreach ($shipmentCollection as $shipment){

            foreach($shipment->getAllTracks() as $key=>$track)
            {
                $trackInfo[]=$track->getNumber();
            }
        }

        if(count($trackInfo) > 0) {
            $trackInfo = array_shift($trackInfo);
        } else {
            $trackInfo = '';
        }

        return $trackInfo;
    }

    private function _getScheduleDateTime($time) {
        $timeArray = explode(',',$time);

        $dateTime = new DateTime('now', new DateTimeZone(TIMEZONE));
        $dateTime->setTime($timeArray[0], $timeArray[1], $timeArray[2]);

        $now = new DateTime('now', new DateTimeZone(TIMEZONE));

        if($now > $dateTime) {
            $dateTime->modify('+1 day');
        }

        return $dateTime;
    }
}