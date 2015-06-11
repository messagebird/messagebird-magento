<?php
/**
 * Created by PhpStorm.
 * User: luis
 * Date: 09/06/15
 * Time: 12:05
 */

class MessageBird_SmsConnector_Model_Sendmessageto
{
    public function toOptionArray()
    {
        return array(
            array('value'=>'customer','label'=>Mage::helper('smsconnector')->__('Customer')),
            array('value'=>'seller','label'=>Mage::helper('smsconnector')->__('Seller')),
            array('value'=>'customerseller','label'=>Mage::helper('smsconnector')->__('Customer, Seller')),
        );
    }
}