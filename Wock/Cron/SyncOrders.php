<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Cron;

use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\KeyFulfillmentService;
use DigitalWarehouse\Wock\Model\OrderMap;
use DigitalWarehouse\Wock\Model\SyncLog;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron job: two responsibilities.
 *
 * Phase 1 — WoCK API polling (unchanged from v1.0.0):
 *   Poll WoCK for orders in status 30 (Ready for download) and fetch their
 *   delivery keys via the WoCK-native polling approach.
 *
 * Phase 2 — Key row fulfillment:
 *   Pick up wock_order_keys rows in 'awaiting_delivery' status, check whether
 *   their delivery is ready, write the real keys to the DB, and email the
 *   customer. This decouples delivery polling from the HTTP request so that
 *   no sleep() call ever blocks checkout.
 *
 * IMPORTANT: Orders must be downloaded within 96 hours or they auto-cancel.
 */
class SyncOrders
{
    /** WoCK order status: Ready for download */
    private const STATUS_READY_FOR_DOWNLOAD = 30;

    public function __construct(
        private readonly Config                  $config,
        private readonly OrderServiceInterface   $orderService,
        private readonly DeliveryServiceInterface $deliveryService,
        private readonly OrderMap                $orderMap,
        private readonly SyncLog                 $syncLog,
        private readonly WockOrderKey            $wockOrderKey,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly KeyFulfillmentService   $fulfillmentService,
        private readonly LoggerInterface         $logger,
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isOrdersCronEnabled()) {
            return;
        }

        $this->expireStalePending();
        $this->pollWockOrders();
        $this->processAwaitingKeyRows();
        $this->processRetryablePending();
    }

    // ── Phase 1: WoCK-native polling ───────────────────────────────────

    private function pollWockOrders(): void
    {
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
     * @param array<string, mixed> $delivery
     *
     * TODO: Implement key delivery to your customers.
     * Key types: text/plain → $key['key'] is plain text; image/* → $key['key'] is base64.
     * Always check $key['subKeys'] for DLC / bundle linked keys.
     */
    private function fulfillDelivery(string $wockOrderId, string $partnerOrderId, array $delivery): void
    {
        $keyCount = 0;

        foreach ($delivery['products'] as $product) {
            $productId = $product['details']['id'] ?? null;
            $mimeType  = '';

            foreach ($product['keys'] as $key) {
                $mimeType = $key['mimeType'];
                $this->logger->debug('WoCK SyncOrders: key ready', [
                    'order_id'    => $wockOrderId,
                    'product_id'  => $productId,
                    'mime_type'   => $mimeType,
                    'has_subkeys' => !empty($key['subKeys']),
                ]);
                $keyCount++;
                // TODO: $this->keyFulfillment->deliver($partnerOrderId, $product['partnerProductId'], $key);

                foreach ($key['subKeys'] ?? [] as $subKey) {
                    $this->logger->debug('WoCK SyncOrders: sub-key ready', [
                        'order_id'   => $wockOrderId,
                        'product_id' => $productId,
                        'mime_type'  => $subKey['mimeType'],
                    ]);
                    $keyCount++;
                    // TODO: $this->keyFulfillment->deliver($partnerOrderId, $product['partnerProductId'], $subKey);
                }
            }
        }

        $this->orderMap->updateStatus($wockOrderId, 'fulfilled');
        $this->syncLog->success('delivery', $wockOrderId, 'fulfill', sprintf(
            'Delivered %d keys for partner order %s',
            $keyCount,
            $partnerOrderId
        ));
    }

    // ── Phase 2: key row fulfillment ───────────────────────────────────

    /**
     * Poll delivery for all key rows that are awaiting keys and email customers.
     * Delegates to KeyFulfillmentService so the same logic is reused by the
     * manual FetchKey and SendKey admin controllers.
     */
    private function processAwaitingKeyRows(): void
    {
        $rows = $this->wockOrderKey->getAwaitingDelivery();

        if (empty($rows)) {
            return;
        }

        $this->logger->info('WoCK SyncOrders: checking awaiting key rows', ['count' => count($rows)]);

        // Group fulfilled keys by Magento order ID so we send one email per order
        $fulfilledByOrder = [];

        foreach ($rows as $row) {
            $keyId   = (int) $row['key_id'];
            $orderId = (int) $row['order_id'];

            $fetchResult = $this->fulfillmentService->fetchKeyForRow($row);

            if (!$fetchResult['success']) {
                $msg = $fetchResult['message'] ?? '';
                // "not ready" is expected — leave as awaiting, retry next run
                if (strpos($msg, 'not ready') !== false) {
                    $this->logger->debug('WoCK SyncOrders: delivery not ready yet', [
                        'key_id' => $keyId,
                    ]);
                } else {
                    $this->logger->error('WoCK SyncOrders: key fetch failed', [
                        'key_id' => $keyId,
                        'error'  => $msg,
                    ]);
                }
                continue;
            }

            $fulfilledByOrder[$orderId][] = [
                'product_name'    => (string) $row['product_name'],
                'wock_product_id' => (int)    $row['wock_product_id'],
                'product_key'     => $fetchResult['key'],
                'qty'             => (int)    $row['qty'],
            ];

            $this->logger->info('WoCK SyncOrders: key row fulfilled', [
                'key_id'    => $keyId,
                'key_count' => $fetchResult['key_count'] ?? 1,
            ]);
        }

        // Send one email per order that had at least one newly-fulfilled key
        foreach ($fulfilledByOrder as $orderId => $fulfilledKeys) {
            try {
                $order = $this->orderRepository->get($orderId);
                $this->fulfillmentService->sendBatchEmail($order, $fulfilledKeys);
            } catch (\Throwable $e) {
                $this->logger->error('WoCK SyncOrders: failed to send keys email', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
    // ── Phase 0: expire stale rows ─────────────────────────────────────

    /**
     * Permanently error any pending rows that are past the 90-hour WoCK
     * retry window before we attempt any retries this run.
     */
    private function expireStalePending(): void
    {
        $expired = $this->wockOrderKey->expireStalePending();
        if ($expired > 0) {
            $this->logger->warning('WoCK SyncOrders: expired stale pending rows', [
                'count' => $expired,
            ]);
            $this->syncLog->error('order', null, 'expire', sprintf('%d rows expired past 90-hour window', $expired));
        }
    }

    // ── Phase 3: retry pending rows that previously failed createOrder ──

    /**
     * Attempt createOrder again for pending rows that had a previous transient
     * failure (out of stock, price mismatch, etc.).
     *
     * When WoCK stock is replenished the retry will succeed and the row will
     * transition to awaiting_delivery, where Phase 2 picks it up normally.
     *
     * Rows within the same order are processed independently so one
     * unavailable product never blocks another.
     */
    private function processRetryablePending(): void
    {
        $rows = $this->wockOrderKey->getRetryablePending();

        if (empty($rows)) {
            return;
        }

        $this->logger->info('WoCK SyncOrders: retrying pending rows', ['count' => count($rows)]);

        foreach ($rows as $row) {
            $keyId = (int) $row['key_id'];

            // fetchKeyForRow handles placeWockOrder internally when wock_order_id is empty
            $result = $this->fulfillmentService->fetchKeyForRow($row);

            if (!$result['success']) {
                $msg = $result['message'] ?? '';
                // Still not ready/available — leave pending, will retry next run
                $this->logger->info('WoCK SyncOrders: retry not yet successful', [
                    'key_id'  => $keyId,
                    'message' => $msg,
                ]);
                continue;
            }

            // Fulfilled on retry — queue email
            $this->logger->info('WoCK SyncOrders: retry fulfilled key', ['key_id' => $keyId]);

            // Send a standalone email for this key since it was delayed
            $orderId = (int) $row['order_id'];
            try {
                $order = $this->orderRepository->get($orderId);
                $this->fulfillmentService->sendBatchEmail($order, [[
                    'product_name'    => (string) $row['product_name'],
                    'wock_product_id' => (int)    $row['wock_product_id'],
                    'product_key'     => $result['key'],
                    'qty'             => (int)    $row['qty'],
                ]]);
            } catch (\Throwable $e) {
                $this->logger->error('WoCK SyncOrders: failed to send retry key email', [
                    'key_id'   => $keyId,
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
