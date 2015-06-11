<?php
/**
 * Created by PhpStorm.
 * User: luis
 * Date: 09/06/15
 * Time: 15:20
 */

class MessageBird_SmsConnector_Model_Orderstatuses
{
    public function toOptionArray()
    {
        $statuses = Mage::getModel('sales/order_status')->getResourceCollection()->getData();
        $resultStatuses = array();

        foreach($statuses as $status) {
            $resultStatuses[] = array('value'=>$status['status'],'label'=>$status['label']);
        }

        return $resultStatuses;
    }
}
