<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Helper\OrderBuilder;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Psr\Log\LoggerInterface;

/**
 * Listens to sales_order_save_after.
 *
 * When a Magento order's status *changes* to the configured fulfillment
 * status (e.g. "complete"), this observer:
 *
 *   1. Finds all pending WoCK key rows for the order.
 *   2. Places a WoCK API order for each row.
 *   3. Saves the returned wock_order_id and marks the row 'awaiting_delivery'.
 *
 * Delivery polling and customer email are handled by Cron/SyncOrders
 * so that no sleep() calls block the HTTP request.
 */
class OrderStatusChange implements ObserverInterface
{
    public function __construct(
        private readonly WockOrderKey          $wockOrderKey,
        private readonly OrderServiceInterface $orderService,
        private readonly OrderBuilder          $orderBuilder,
        private readonly Config                $config,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $fulfillmentStatus = $this->config->getOrderFulfillmentStatus();
        if (empty($fulfillmentStatus)) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        // Guard 1: only act when the order reaches the configured fulfillment status
        if ($order->getStatus() !== $fulfillmentStatus) {
            return;
        }

        // Guard 2: only act when the status actually changed on this save —
        // sales_order_save_after fires for every order write (invoices, comments, etc.)
        if ($order->getOrigData('status') === $order->getStatus()) {
            return;
        }

        $pendingKeys = $this->wockOrderKey->getPendingByOrderId((int) $order->getId());
        if (empty($pendingKeys)) {
            return;
        }

        $this->logger->info('WoCK OrderStatusChange: placing WoCK orders', [
            'order'  => $order->getIncrementId(),
            'status' => $fulfillmentStatus,
            'count'  => count($pendingKeys),
        ]);

        foreach ($pendingKeys as $keyRow) {
            $this->placeWockOrder($keyRow, $order);
        }
    }

    /**
     * Place a single WoCK API order for a pending key row and save the returned
     * wock_order_id. Delivery polling happens in Cron/SyncOrders.
     */
    private function placeWockOrder(array $keyRow, Order $order): void
    {
        $wockProductId = (int) $keyRow['wock_product_id'];
        $qty           = (int) $keyRow['qty'];
        $keyId         = (int) $keyRow['key_id'];

        // Resolve unit price from the matching order item
        $unitPrice = 0.0;
        foreach ($order->getAllVisibleItems() as $item) {
            if ((int) $item->getItemId() === (int) $keyRow['order_item_id']) {
                $unitPrice = (float) $item->getPrice();
                break;
            }
        }

        try {
            $this->orderBuilder->reset();
            $this->orderBuilder->setPartnerOrderId($order->getIncrementId() . '-' . $keyId);
            $this->orderBuilder->addProduct(
                productId: $wockProductId,
                quantity:  $qty,
                unitPrice: $unitPrice
            );

            $result      = $this->orderService->createOrder($this->orderBuilder->build());
            $wockOrderId = $result['orderId'] ?? null;

            if (!$wockOrderId) {
                $msg = 'WoCK createOrder returned no orderId';
                $this->wockOrderKey->markCreateOrderFailed($keyId, $msg);
                $this->logger->warning('WoCK OrderStatusChange: no orderId returned — row kept pending for retry', [
                    'key_id' => $keyId,
                    'order'  => $order->getIncrementId(),
                ]);
                return;
            }

            // Persist the WoCK order ID; the cron will poll for keys and send email
            $this->wockOrderKey->markWockOrderPlaced($keyId, $wockOrderId);

            $this->logger->info('WoCK OrderStatusChange: WoCK order placed, awaiting delivery', [
                'key_id'        => $keyId,
                'wock_order_id' => $wockOrderId,
            ]);

        } catch (\Throwable $e) {
            // Keep pending — likely out of stock or price mismatch. Cron will retry.
            $this->wockOrderKey->markCreateOrderFailed($keyId, $e->getMessage());
            $this->logger->warning('WoCK OrderStatusChange: createOrder failed — row kept pending for cron retry', [
                'key_id' => $keyId,
                'order'  => $order->getIncrementId(),
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
