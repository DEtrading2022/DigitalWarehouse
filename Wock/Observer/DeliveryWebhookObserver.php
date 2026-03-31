<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles the wock_delivery_webhook_received event.
 *
 * Per WoCK best-practices, the incoming webhook payload is a bare minimum
 * signal only. This observer re-fetches the full delivery from the API.
 *
 * IMPORTANT: orders not downloaded within 96 hours are auto-cancelled.
 * Always process deliveries promptly.
 *
 * Extend or replace this observer (via di.xml) to deliver keys to customers.
 */
class DeliveryWebhookObserver implements ObserverInterface
{
    public function __construct(
        private readonly DeliveryServiceInterface $deliveryService,
        private readonly LoggerInterface          $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        $orderId = (string) $observer->getData('order_id');

        if (empty($orderId)) {
            return;
        }

        try {
            $delivery = $this->deliveryService->getDelivery($orderId);
        } catch (ApiException $e) {
            $this->logger->error('WoCK: failed to fetch delivery after webhook', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return;
        }

        $status = $delivery['status'] ?? [];

        if (!($status['ready'] ?? false)) {
            // Delivery not yet ready (status: false, null error means still processing)
            if (!empty($status['error'])) {
                $this->logger->error('WoCK: delivery error', [
                    'order_id' => $orderId,
                    'error'    => $status['error'],
                ]);
            } else {
                $this->logger->info('WoCK: delivery not yet ready, will retry via polling', [
                    'order_id' => $orderId,
                ]);
            }
            return;
        }

        $this->logger->info('WoCK: delivery ready, processing keys', [
            'order_id'      => $orderId,
            'product_count' => count($delivery['products'] ?? []),
        ]);

        // TODO: Deliver keys to the customer order.
        //
        // Iterate over $delivery['products'] and for each product iterate
        // $product['keys'] and handle both text keys and image (base64) keys.
        // Also check $product['keys'][n]['subKeys'] for DLC / bundle linked keys.
        //
        // Example:
        // foreach ($delivery['products'] as $product) {
        //     foreach ($product['keys'] as $key) {
        //         $this->keyFulfillment->fulfil($orderId, $product, $key);
        //     }
        // }
    }
}
