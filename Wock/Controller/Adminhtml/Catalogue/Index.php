<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Adminhtml\Catalogue;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'DigitalWarehouse_Wock::config';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('DigitalWarehouse_Wock::api_catalogue');
        $resultPage->getConfig()->getTitle()->prepend(__('WoCK API Explorer'));

        return $resultPage;
    }
}
