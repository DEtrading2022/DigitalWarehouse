<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Adminhtml\Orders;

use DigitalWarehouse\Wock\Model\KeyFulfillmentService;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * AJAX action: send the stored product key to the customer by email.
 *
 * POST wock/orders/sendKey
 * Params: key_id (int)
 *
 * Response JSON:
 *   { "success": true }
 *   { "success": false, "message": "..." }
 */
class SendKey extends Action
{
    public const ADMIN_RESOURCE = 'DigitalWarehouse_Wock::config';

    public function __construct(
        Context $context,
        private readonly WockOrderKey          $wockOrderKey,
        private readonly KeyFulfillmentService $fulfillmentService,
        private readonly JsonFactory           $jsonFactory,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $keyId = (int) $this->getRequest()->getParam('key_id');
        if (!$keyId) {
            return $result->setData(['success' => false, 'message' => 'Missing key_id parameter.']);
        }

        $keyRow = $this->wockOrderKey->getByKeyId($keyId);
        if (!$keyRow) {
            return $result->setData(['success' => false, 'message' => 'Key row not found.']);
        }

        $sendResult = $this->fulfillmentService->sendKeyEmail($keyRow);

        return $result->setData($sendResult);
    }
}
