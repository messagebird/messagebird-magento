<?php
class MessageBird_SmsConnector_Block_Adminhtml_System_Config_Form_Field_Orderstatuses extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    const CONFIG_PATH = 'smsconnectorconfig/sendonorderstatuschangegroup/orderstatuses';
    protected $_values = null;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('messagebird_smsconnector/system/config/form/field/orderstatuses.phtml');
    }

    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setNamePrefix($element->getName())
            ->setHtmlId($element->getHtmlId());

        return $this->_toHtml();
    }

    public function getValues()
    {
        $values = array();
        foreach (Mage::getSingleton('smsconnector/orderstatuses')->toOptionArray() as $value) {
            $values[$value['value']] = $value['label'];
        }

        return $values;
    }

    public function getIsChecked($name)
    {
        return in_array($name, $this->getCheckedValues());
    }

    public function getCheckedValues(){

        if (is_null($this->_values)) {
            $data = $this->getConfigData();

            if (isset($data[self::CONFIG_PATH])) {
                $data = $data[self::CONFIG_PATH];
            } else {
                $data = '';
            }

            $this->_values = explode(',', $data);
        }
        return $this->_values;
    }
}