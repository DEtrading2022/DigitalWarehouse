<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a clickable button in the store configuration page.
 */
class Button extends Field
{
    protected $_template = 'DigitalWarehouse_Wock::system/config/button.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getButtonUrl(): string
    {
        return $this->getUrl('wock/mapped/index');
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
            'id'    => 'wock_view_mapped_products',
            'label' => __('View Mapped WoCK Products'),
            'class' => 'action-secondary',
            'onclick' => sprintf("window.open('%s', '_blank')", $this->getButtonUrl())
        ]);

        return $button->toHtml();
    }
}
