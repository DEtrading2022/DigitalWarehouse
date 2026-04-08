<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestConnection extends Field
{
    protected $_template = 'DigitalWarehouse_Wock::system/config/test_connection.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('wock/test/connection', ['_current' => true]);
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
            'id' => 'wock_test_connection_btn',
            'label' => __('Test OAuth Connection'),
        ]);

        return $button->toHtml();
    }
}
