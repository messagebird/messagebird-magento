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
        $countryCodes = array(
            "AF" => "93",
            "AL" => "355",
            "DZ" => "213",
            "AS" => "1684",
            "AD" => "376",
            "AO" => "244",
            "AI" => "1264",
            "AQ" => "672",
            "AG" => "1268",
            "AR" => "54",
            "AM" => "374",
            "AW" => "297",
            "AU" => "61",
            "AT" => "43",
            "AZ" => "994",
            "BS" => "1242",
            "BH" => "973",
            "BD" => "880",
            "BB" => "1246",
            "BY" => "375",
            "BE" => "32",
            "BZ" => "501",
            "BJ" => "229",
            "BM" => "1441",
            "BT" => "975",
            "BO" => "591",
            "BA" => "387",
            "BW" => "267",
            "BR" => "55",
            "VG" => "1284",
            "BN" => "673",
            "BG" => "359",
            "BF" => "226",
            "MM" => "95",
            "BI" => "257",
            "KH" => "855",
            "CM" => "237",
            "CA" => "1",
            "CV" => "238",
            "KY" => "1345",
            "CF" => "236",
            "TD" => "235",
            "CL" => "56",
            "CN" => "86",
            "CX" => "61",
            "CC" => "61",
            "CO" => "57",
            "KM" => "269",
            "CK" => "682",
            "CR" => "506",
            "HR" => "385",
            "CU" => "53",
            "CY" => "357",
            "CZ" => "420",
            "CD" => "243",
            "DK" => "45",
            "DJ" => "253",
            "DM" => "1767",
            "DO" => "1809",
            "EC" => "593",
            "EG" => "20",
            "SV" => "503",
            "GQ" => "240",
            "ER" => "291",
            "EE" => "372",
            "ET" => "251",
            "FK" => "500",
            "FO" => "298",
            "FJ" => "679",
            "FI" => "358",
            "FR" => "33",
            "PF" => "689",
            "GA" => "241",
            "GM" => "220",
            "GE" => "995",
            "DE" => "49",
            "GH" => "233",
            "GI" => "350",
            "GR" => "30",
            "GL" => "299",
            "GD" => "1473",
            "GU" => "1671",
            "GT" => "502",
            "GN" => "224",
            "GW" => "245",
            "GY" => "592",
            "HT" => "509",
            "VA" => "39",
            "HN" => "504",
            "HK" => "852",
            "HU" => "36",
            "IS" => "354",
            "IN" => "91",
            "ID" => "62",
            "IR" => "98",
            "IQ" => "964",
            "IE" => "353",
            "IM" => "44",
            "IL" => "972",
            "IT" => "39",
            "CI" => "225",
            "JM" => "1876",
            "JP" => "81",
            "JO" => "962",
            "KZ" => "7",
            "KE" => "254",
            "KI" => "686",
            "KW" => "965",
            "KG" => "996",
            "LA" => "856",
            "LV" => "371",
            "LB" => "961",
            "LS" => "266",
            "LR" => "231",
            "LY" => "218",
            "LI" => "423",
            "LT" => "370",
            "LU" => "352",
            "MO" => "853",
            "MK" => "389",
            "MG" => "261",
            "MW" => "265",
            "MY" => "60",
            "MV" => "960",
            "ML" => "223",
            "MT" => "356",
            "MH" => "692",
            "MR" => "222",
            "MU" => "230",
            "YT" => "262",
            "MX" => "52",
            "FM" => "691",
            "MD" => "373",
            "MC" => "377",
            "MN" => "976",
            "ME" => "382",
            "MA" => "1664",
            "MZ" => "258",
            "NA" => "264",
            "NR" => "674",
            "NP" => "977",
            "NL" => "31",
            "AN" => "599",
            "NC" => "687",
            "NZ" => "64",
            "NI" => "505",
            "NE" => "227",
            "NG" => "234",
            "NU" => "683",
            "NF" => "672",
            "KP" => "850",
            "MP" => "1670",
            "NO" => "47",
            "OM" => "968",
            "PK" => "92",
            "PW" => "680",
            "PA" => "507",
            "PG" => "675",
            "PY" => "595",
            "PE" => "51",
            "PH" => "63",
            "PN" => "870",
            "PL" => "48",
            "PT" => "351",
            "PR" => "1",
            "QA" => "974",
            "CG" => "242",
            "RO" => "40",
            "RU" => "7",
            "RW" => "250",
            "BL" => "590",
            "SH" => "290",
            "KN" => "1869",
            "LC" => "1758",
            "MF" => "1599",
            "PM" => "508",
            "VC" => "1784",
            "WS" => "685",
            "SM" => "378",
            "ST" => "239",
            "SA" => "966",
            "SN" => "221",
            "RS" => "381",
            "SC" => "248",
            "SL" => "232",
            "SG" => "65",
            "SK" => "421",
            "SI" => "386",
            "SB" => "677",
            "SO" => "252",
            "ZA" => "27",
            "KR" => "82",
            "ES" => "34",
            "LK" => "94",
            "SD" => "249",
            "SR" => "597",
            "SZ" => "268",
            "SE" => "46",
            "CH" => "41",
            "SY" => "963",
            "TW" => "886",
            "TJ" => "992",
            "TZ" => "255",
            "TH" => "66",
            "TL" => "670",
            "TG" => "228",
            "TK" => "690",
            "TO" => "676",
            "TT" => "1868",
            "TN" => "216",
            "TR" => "90",
            "TM" => "993",
            "TC" => "1649",
            "TV" => "688",
            "UG" => "256",
            "UA" => "380",
            "AE" => "971",
            "GB" => "44",
            "US" => "1",
            "UY" => "598",
            "VI" => "1340",
            "UZ" => "998",
            "VU" => "678",
            "VE" => "58",
            "VN" => "84",
            "WF" => "681",
            "YE" => "967",
            "ZM" => "260",
            "ZW" => "263",
        );

        return (isset($countryCodes[$country])) ? $countryCodes[$country]: 0 ;
    }
}