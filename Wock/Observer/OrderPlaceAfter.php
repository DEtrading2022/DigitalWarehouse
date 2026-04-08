<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
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
        private readonly WockOrderKey    $wockOrderKey,
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        // Avoid duplicate rows if the observer fires more than once
        if ($this->wockOrderKey->hasRowsForOrder((int) $order->getId())) {
            return;
        }

        $orderId      = (int) $order->getId();
        $incrementId  = $order->getIncrementId();
        $storeId      = (int) $order->getStoreId();

        foreach ($order->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            if (!$product) {
                continue;
            }

            // Only process items flagged as WoCK products
            if (!(int) $product->getData('is_wock_product')) {
                continue;
            }

            $wockProductId = (int) $product->getData('wock_product_id');
            if (!$wockProductId) {
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
                    'order'          => $incrementId,
                    'wock_product_id' => $wockProductId,
                    'qty'            => $qty,
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
