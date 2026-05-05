<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Catalog\Api\ProductRepositoryInterface;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Psr\Log\LoggerInterface;

/**
 * After a Magento order is placed, creates placeholder key rows
 * in wock_order_keys for every order item whose product has
 * is_wock_product = Yes.
 *
 * Event: sales_order_place_after
 */
class OrderPlaceAfter implements ObserverInterface
{
    public function __construct(
        private readonly WockOrderKey               $wockOrderKey,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Config                     $config,
        private readonly LoggerInterface            $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        $this->logger->debug('WoCK OrderPlaceAfter: observer fired');

        if (!$this->config->isEnabled()) {
            $this->logger->debug('WoCK OrderPlaceAfter: SKIPPED — module is disabled in config');
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            $this->logger->debug('WoCK OrderPlaceAfter: SKIPPED — no order on event');
            return;
        }

        $orderId     = (int) $order->getId();
        $incrementId = $order->getIncrementId();
        $storeId     = (int) $order->getStoreId();

        $this->logger->debug('WoCK OrderPlaceAfter: processing order', [
            'order_id'     => $orderId,
            'increment_id' => $incrementId,
            'store_id'     => $storeId,
        ]);

        // Avoid duplicate rows if the observer fires more than once
        if ($this->wockOrderKey->hasRowsForOrder($orderId)) {
            $this->logger->debug('WoCK OrderPlaceAfter: SKIPPED — rows already exist for order', [
                'order_id' => $orderId,
            ]);
            return;
        }

        $itemCount = count($order->getAllVisibleItems());
        $this->logger->debug('WoCK OrderPlaceAfter: iterating items', ['item_count' => $itemCount]);

        foreach ($order->getAllVisibleItems() as $item) {
            $productId = (int) $item->getProductId();

            if (!$productId) {
                $this->logger->debug('WoCK OrderPlaceAfter: item skipped — no product ID', [
                    'item_id' => $item->getItemId(),
                ]);
                continue;
            }

            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (\Exception $e) {
                $this->logger->warning('WoCK OrderPlaceAfter: could not load product', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            $isWock       = (int) $product->getData('is_wock_product');
            $wockProductId = (int) $product->getData('wock_product_id');

            $this->logger->debug('WoCK OrderPlaceAfter: product attribute check', [
                'product_id'     => $productId,
                'is_wock_product' => $isWock,
                'wock_product_id' => $wockProductId,
            ]);

            // Only process items flagged as WoCK products
            if (!$isWock) {
                $this->logger->debug('WoCK OrderPlaceAfter: item skipped — is_wock_product is not set', [
                    'product_id' => $productId,
                ]);
                continue;
            }

            if (!$wockProductId) {
                $this->logger->warning('WoCK OrderPlaceAfter: item skipped — wock_product_id is empty/zero', [
                    'product_id' => $productId,
                ]);
                continue;
            }

            $qty = (int) $item->getQtyOrdered();

            try {
                $this->wockOrderKey->createPlaceholder(
                    orderId:          $orderId,
                    orderIncrementId: $incrementId,
                    orderItemId:      (int) $item->getItemId(),
                    productId:        (int) $product->getId(),
                    productName:      (string) $item->getName(),
                    wockProductId:    $wockProductId,
                    qty:              $qty,
                    storeId:          $storeId
                );

                $this->logger->info('WoCK OrderPlaceAfter: placeholder created', [
                    'order'           => $incrementId,
                    'wock_product_id' => $wockProductId,
                    'qty'             => $qty,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('WoCK OrderPlaceAfter: failed to create placeholder', [
                    'order' => $incrementId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
