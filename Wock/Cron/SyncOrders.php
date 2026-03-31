<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Cron;

use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\OrderMap;
use DigitalWarehouse\Wock\Model\SyncLog;
use Psr\Log\LoggerInterface;

/**
 * Cron job: poll WoCK for orders in "Ready for download" status (30)
 * and fetch their delivery keys.
 *
 * This covers the "Receiving orders by polling" best-practice from the docs.
 * IMPORTANT: Orders must be downloaded within 96 hours or they auto-cancel.
 */
class SyncOrders
{
    /** Order status: Ready for download */
    private const STATUS_READY_FOR_DOWNLOAD = 30;

    public function __construct(
        private readonly Config                   $config,
        private readonly OrderServiceInterface    $orderService,
        private readonly DeliveryServiceInterface $deliveryService,
        private readonly OrderMap                 $orderMap,
        private readonly SyncLog                  $syncLog,
        private readonly LoggerInterface          $logger,
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isOrdersCronEnabled()) {
            return;
        }

        $this->logger->info('WoCK SyncOrders: polling for ready orders');

        try {
            $orders = $this->orderService->getAllOrders([
                'status' => ['eq' => self::STATUS_READY_FOR_DOWNLOAD],
            ]);
        } catch (ApiException $e) {
            $this->logger->error('WoCK SyncOrders: failed to fetch orders', [
                'error' => $e->getMessage(),
            ]);
            $this->syncLog->error('order', null, 'poll', $e->getMessage());
            return;
        }

        $this->logger->info('WoCK SyncOrders: found ready orders', ['count' => count($orders)]);

        foreach ($orders as $order) {
            $this->processOrder($order);
        }

        $this->syncLog->success('order', null, 'poll', sprintf('Polled %d ready orders', count($orders)));
    }

    private function processOrder(array $order): void
    {
        $orderId        = (string) ($order['id'] ?? '');
        $partnerOrderId = (string) ($order['partnerOrderId'] ?? '');

        if (empty($orderId)) {
            return;
        }

        // Update OrderMap status if it exists
        $mapping = $this->orderMap->getByWockOrderId($orderId);
        if ($mapping) {
            $this->orderMap->updateStatus($orderId, 'ready');
        }

        try {
            $delivery = $this->deliveryService->getDelivery($orderId);
        } catch (ApiException $e) {
            $this->logger->error('WoCK SyncOrders: failed to fetch delivery', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            $this->syncLog->error('delivery', $orderId, 'fetch', $e->getMessage());
            return;
        }

        $status = $delivery['status'] ?? [];

        if (!($status['ready'] ?? false)) {
            if (!empty($status['error'])) {
                $this->logger->error('WoCK SyncOrders: delivery has error status', [
                    'order_id' => $orderId,
                    'error'    => $status['error'],
                ]);
                $this->syncLog->error('delivery', $orderId, 'fetch', $status['error']);
                if ($mapping) {
                    $this->orderMap->updateStatus($orderId, 'error');
                }
            } else {
                $this->logger->debug('WoCK SyncOrders: delivery not ready yet', [
                    'order_id' => $orderId,
                ]);
            }
            return;
        }

        $this->logger->info('WoCK SyncOrders: delivery ready, processing', [
            'order_id'         => $orderId,
            'partner_order_id' => $partnerOrderId,
            'product_count'    => count($delivery['products'] ?? []),
        ]);

        $this->fulfillDelivery($orderId, $partnerOrderId, $delivery);
    }

    /**
     * Deliver keys to customer.
     *
     * @param  string               $wockOrderId
     * @param  string               $partnerOrderId  Your own order reference
     * @param  array<string, mixed> $delivery
     *
     * TODO: Implement key delivery to your customers.
     *
     * Key types to handle:
     *   - text keys: $key['mimeType'] === 'text/plain'  → $key['key'] is plain text
     *   - image keys: $key['mimeType'] starts with 'image/' → $key['key'] is base64
     *
     * Always check $key['subKeys'] for DLC / bundle linked keys.
     */
    private function fulfillDelivery(string $wockOrderId, string $partnerOrderId, array $delivery): void
    {
        $keyCount = 0;

        foreach ($delivery['products'] as $product) {
            $productId      = $product['details']['id']   ?? null;
            $productName    = $product['details']['name'] ?? '';
            $partnerProdId  = $product['partnerProductId'] ?? null;

            foreach ($product['keys'] as $key) {
                $keyValue = $key['key'];
                $mimeType = $key['mimeType'];

                $this->logger->debug('WoCK SyncOrders: key ready', [
                    'order_id'    => $wockOrderId,
                    'product_id'  => $productId,
                    'mime_type'   => $mimeType,
                    'has_subkeys' => !empty($key['subKeys']),
                ]);

                $keyCount++;

                // TODO: $this->keyFulfillment->deliver($partnerOrderId, $partnerProdId, $key);

                // Handle subKeys (DLC, bundles, dependent keys)
                foreach ($key['subKeys'] ?? [] as $subKey) {
                    $this->logger->debug('WoCK SyncOrders: sub-key ready', [
                        'order_id'   => $wockOrderId,
                        'product_id' => $productId,
                        'mime_type'  => $subKey['mimeType'],
                    ]);
                    $keyCount++;
                    // TODO: $this->keyFulfillment->deliver($partnerOrderId, $partnerProdId, $subKey);
                }
            }
        }

        // Update order map status to fulfilled
        $this->orderMap->updateStatus($wockOrderId, 'fulfilled');
        $this->syncLog->success('delivery', $wockOrderId, 'fulfill', sprintf(
            'Delivered %d keys for partner order %s',
            $keyCount,
            $partnerOrderId
        ));
    }
}
