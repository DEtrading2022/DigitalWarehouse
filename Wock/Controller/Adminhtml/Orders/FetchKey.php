<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Adminhtml\Orders;

use DigitalWarehouse\Wock\Model\KeyFulfillmentService;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * AJAX action: fetch and store the WoCK delivery key for a single key row.
 *
 * POST wock/orders/fetchKey
 * Params: key_id (int)
 *
 * Response JSON:
 *   { "success": true,  "key": "XXXX-YYYY", "key_count": 1 }
 *   { "success": false, "message": "..." }
 */
class FetchKey extends Action
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

        // If already fulfilled, return the existing key immediately
        if ($keyRow['status'] === 'fulfilled') {
            return $result->setData([
                'success'  => true,
                'key'      => $keyRow['product_key'],
                'status'   => 'fulfilled',
                'key_count' => substr_count($keyRow['product_key'], "\n") + 1,
                'message'  => 'Key already retrieved — showing stored value.',
            ]);
        }

        $fetchResult = $this->fulfillmentService->fetchKeyForRow($keyRow);

        return $result->setData($fetchResult);
    }
}
