<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * On admin product save, fetches fresh price and stock data from the WoCK API
 * and writes it back onto the product before Magento persists it.
 *
 * Only runs when the product has a wock_product_id attribute set.
 */
class ProductSaveBefore implements ObserverInterface
{
    public function __construct(
        private readonly ProductServiceInterface $productService,
        private readonly Config                  $config,
        private readonly LoggerInterface         $logger,
        private readonly StockRegistryInterface  $stockRegistry,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$this->config->isEnabled()) {
            return;
        }

        // Only sync if the product is flagged as a WoCK product
        if (!(int) $product->getData('is_wock_product')) {
            return;
        }

        $wockId = (int) $product->getData('wock_product_id');

        if (!$wockId) {
            return;
        }

        try {
            // getProductsByIds() returns a flat array: [0 => [...], 1 => [...]]
            $products = $this->productService->getProductsByIds([$wockId]);

            if (empty($products)) {
                $this->logger->warning('WoCK ProductSaveBefore: no product returned for WoCK ID ' . $wockId);
                return;
            }

            $item = $products[0];

            // --- Price (unit wholesale cost at minimumQuantity === 1) ---
            $costPrice  = null;
            $bulkPrices = $item['bulkPrices'] ?? [];

            foreach ($bulkPrices as $bp) {
                if ((int) $bp['minimumQuantity'] === 1) {
                    $costPrice = (float) $bp['price'];
                    break;
                }
            }

            // Fallback: use the first tier if qty=1 is not explicitly listed
            if ($costPrice === null && !empty($bulkPrices)) {
                $costPrice = (float) $bulkPrices[0]['price'];
            }

            if ($costPrice !== null) {
                $this->logger->info('WoCK ProductSaveBefore: setting price/cost', [
                    'wock_id' => $wockId,
                    'price'   => $costPrice,
                ]);
                $product->setPrice($costPrice);
                $product->setCost($costPrice);
            }

            // --- Stock quantity ---
            $stockQty = (int) ($item['quantity']['all'] ?? 0);

            $this->logger->info('WoCK ProductSaveBefore: setting stock', [
                'wock_id' => $wockId,
                'qty'     => $stockQty,
            ]);

            $product->setData('quantity_and_stock_status', [
                'qty'         => $stockQty,
                'is_in_stock' => $stockQty > 0 ? 1 : 0,
            ]);

            // Also push directly into the stock item for existing products
            if ($product->getId()) {
                try {
                    $stockItem = $this->stockRegistry->getStockItem(
                        $product->getId(),
                        $product->getStore()->getWebsiteId()
                    );
                    $stockItem->setQty($stockQty);
                    $stockItem->setIsInStock($stockQty > 0);
                    $stockItem->setManageStock(1);
                    $stockItem->setUseConfigManageStock(0);
                } catch (\Exception $e) {
                    $this->logger->warning('WoCK ProductSaveBefore: could not update stock item', [
                        'wock_id' => $wockId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // --- Metadata attributes ---
            $product->setData('wock_platform',    $item['platform'] ?? null);
            $product->setData('wock_region',      $item['region'] ?? null);
            $product->setData('wock_stock_all',   $stockQty);
            $product->setData('wock_is_disabled', !empty($item['isDisabled']) ? 1 : 0);
            $product->setData('wock_last_update', $item['lastUpdateDateTime'] ?? null);

            $this->logger->info('WoCK ProductSaveBefore: sync complete', ['wock_id' => $wockId]);

        } catch (\Throwable $e) {
            $this->logger->error('WoCK ProductSaveBefore: sync failed', [
                'wock_id' => $wockId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
