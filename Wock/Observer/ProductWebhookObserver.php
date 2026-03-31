<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\SyncLog;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles the wock_product_webhook_received event.
 *
 * Per WoCK best-practices, the incoming webhook payload is a bare minimum
 * signal only. This observer re-fetches the product from the API to get
 * the authoritative, up-to-date data.
 *
 * Extend this observer (or replace it via di.xml) to persist the product
 * data into your own Magento catalogue / custom tables.
 */
class ProductWebhookObserver implements ObserverInterface
{
    public function __construct(
        private readonly ProductServiceInterface $productService,
        private readonly SyncLog                 $syncLog,
        private readonly LoggerInterface         $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        $productId = (int) $observer->getData('product_id');
        $type      = (string) $observer->getData('type');

        if ($productId <= 0) {
            return;
        }

        $productIdStr = (string) $productId;

        if ($type === 'PRODUCT_DELETED') {
            // Product removed from platform — handle deletion in your own logic
            $this->logger->info('WoCK: product deleted on platform', ['product_id' => $productId]);
            $this->syncLog->success('product', $productIdStr, 'webhook', 'Product deleted on WoCK platform');
            // TODO: disable / delete the corresponding Magento product
            return;
        }

        // Re-fetch the product from the API as the source of truth
        try {
            $products = $this->productService->getProductsByIds([$productId]);
        } catch (ApiException $e) {
            $this->logger->error('WoCK: failed to re-fetch product after webhook', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
            $this->syncLog->error('product', $productIdStr, 'webhook', $e->getMessage());
            return;
        }

        if (empty($products)) {
            $this->logger->warning('WoCK: re-fetch returned no product', ['product_id' => $productId]);
            $this->syncLog->skipped('product', $productIdStr, 'webhook', 'API returned empty result');
            return;
        }

        $product = $products[0];

        $this->logger->info('WoCK: product data refreshed from webhook trigger', [
            'product_id' => $productId,
            'name'       => $product['name'] ?? '',
        ]);

        $this->syncLog->success('product', $productIdStr, 'webhook', sprintf(
            '%s refreshed | stock: %d',
            $product['name'] ?? '',
            $product['quantity']['all'] ?? 0
        ));

        // TODO: Persist $product into your Magento catalogue or custom table.
        // Example: update stock quantity, price, disabled status, etc.
        //
        // $this->catalogSync->upsertProduct($product);
    }
}
