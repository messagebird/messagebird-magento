<?php
/**
 * Created by PhpStorm.
 * User: luis
 * Date: 09/06/15
 * Time: 12:05
 */

class MessageBird_SmsBridge_Model_Sendmessageto
{
    public function toOptionArray()
    {
        return array(
            array('value'=>'customer','label'=>Mage::helper('smsbridge')->__('Customer')),
            array('value'=>'seller','label'=>Mage::helper('smsbridge')->__('Seller')),
            array('value'=>'customerseller','label'=>Mage::helper('smsbridge')->__('Customer, Seller')),
        );
    }
}