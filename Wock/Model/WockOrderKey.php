<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Manages the wock_order_keys table.
 *
 * Key status lifecycle:
 *
 *   pending            → placeholder row created at order placement.
 *                        Also used when createOrder failed transiently
 *                        (out of stock, price mismatch) — error_message
 *                        is set, wock_order_id is NULL. Cron will retry.
 *
 *   awaiting_delivery  → WoCK order placed, wock_order_id saved.
 *                        Cron is polling for delivery keys.
 *
 *   fulfilled          → real keys received, stored in product_key,
 *                        email sent to customer.
 *
 *   error              → permanent unrecoverable failure (delivery error,
 *                        row expired past WoCK's 96-hour window, or admin
 *                        explicitly gave up). No automatic retry.
 */
class WockOrderKey
{
    private const TABLE = 'wock_order_keys';

    /** WoCK auto-cancels orders after 96 hours — we stop retrying at 90h */
    private const RETRY_CUTOFF_HOURS = 90;

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {}

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    // ── Write ──────────────────────────────────────────────────────────

    /**
     * Insert a new placeholder key row for an order item.
     */
    public function createPlaceholder(
        int    $orderId,
        string $orderIncrementId,
        int    $orderItemId,
        int    $productId,
        string $productName,
        int    $wockProductId,
        int    $qty,
        int    $storeId
    ): void {
        $this->getConnection()->insert(
            $this->getTable(),
            [
                'order_id'           => $orderId,
                'order_increment_id' => $orderIncrementId,
                'order_item_id'      => $orderItemId,
                'product_id'         => $productId,
                'product_name'       => $productName,
                'wock_product_id'    => $wockProductId,
                'qty'                => $qty,
                'product_key'        => sprintf('PENDING-ORD%s-WP%d', $orderIncrementId, $wockProductId),
                'store_id'           => $storeId,
                'status'             => 'pending',
            ]
        );
    }

    /**
     * Record a transient createOrder failure.
     *
     * Status stays 'pending' so the cron will retry automatically.
     * The error message is stored in the dedicated error_message column,
     * leaving product_key intact (it still shows the PENDING placeholder).
     */
    public function markCreateOrderFailed(int $keyId, string $errorMessage): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            ['error_message' => mb_substr($errorMessage, 0, 1000)],
            ['key_id = ?' => $keyId]
        );
        // status intentionally left as 'pending' so cron retries
    }

    /**
     * Record the WoCK order ID once the API order has been placed.
     * Transitions status from 'pending' → 'awaiting_delivery'.
     * Clears any previous error message from a failed attempt.
     */
    public function markWockOrderPlaced(int $keyId, string $wockOrderId): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            [
                'wock_order_id' => $wockOrderId,
                'status'        => 'awaiting_delivery',
                'error_message' => null,
            ],
            ['key_id = ?' => $keyId]
        );
    }

    /**
     * Update a key row with the real product key from the WoCK API.
     * Transitions status to 'fulfilled'.
     */
    public function fulfill(int $keyId, string $productKey, ?string $wockOrderId = null): void
    {
        $bind = [
            'product_key'   => $productKey,
            'status'        => 'fulfilled',
            'error_message' => null,
        ];
        if ($wockOrderId !== null) {
            $bind['wock_order_id'] = $wockOrderId;
        }

        $this->getConnection()->update(
            $this->getTable(),
            $bind,
            ['key_id = ?' => $keyId]
        );
    }

    /**
     * Mark a key row as permanently errored (unrecoverable).
     * Used for delivery-level errors and rows past the 90-hour retry cutoff.
     */
    public function markError(int $keyId, string $errorMessage): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            [
                'status'        => 'error',
                'error_message' => mb_substr($errorMessage, 0, 1000),
            ],
            ['key_id = ?' => $keyId]
        );
    }

    // ── Read ───────────────────────────────────────────────────────────

    /**
     * Get a single key row by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function getByKeyId(int $keyId): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('key_id = ?', $keyId)
            ->limit(1);

        $row = $this->getConnection()->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Get all key rows for a given Magento order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByOrderId(int $orderId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('order_id = ?', $orderId)
            ->order('key_id ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Get all pending key rows for a given Magento order.
     * Used by OrderStatusChange to find rows to process.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingByOrderId(int $orderId): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('order_id = ?', $orderId)
            ->where('status = ?', 'pending')
            ->order('key_id ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Get rows waiting for delivery keys (WoCK order placed, keys not yet received).
     * Polled by Cron/SyncOrders Phase 2.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAwaitingDelivery(): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('status = ?', 'awaiting_delivery')
            ->where('wock_order_id IS NOT NULL')
            ->order('created_at ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Get pending rows that previously failed createOrder and need a retry.
     *
     * Conditions:
     *   - status = 'pending' (not yet placed with WoCK)
     *   - error_message IS NOT NULL (failed at least once)
     *   - created_at within the retry cutoff window (90 h)
     *
     * Rows outside the cutoff are expired by expireStalePending() instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRetryablePending(): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETRY_CUTOFF_HOURS . ' hours'));

        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('status = ?', 'pending')
            ->where('wock_order_id IS NULL')
            ->where('error_message IS NOT NULL')
            ->where('created_at >= ?', $cutoff)
            ->order('created_at ASC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Permanently error all pending rows that are past the WoCK retry window.
     * Called by Cron/SyncOrders before retrying to avoid wasting API calls.
     */
    public function expireStalePending(): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETRY_CUTOFF_HOURS . ' hours'));

        return (int) $this->getConnection()->update(
            $this->getTable(),
            [
                'status'        => 'error',
                'error_message' => 'Expired: no WoCK order placed within 90 hours.',
            ],
            [
                'status = ?'        => 'pending',
                'wock_order_id IS NULL',
                'error_message IS NOT NULL',
                'created_at < ?'    => $cutoff,
            ]
        );
    }

    /**
     * Get all rows (for admin grid display).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->order('created_at DESC');

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * Check if placeholder rows already exist for an order.
     */
    public function hasRowsForOrder(int $orderId): bool
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable(), ['cnt' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('order_id = ?', $orderId);

        return (int) $this->getConnection()->fetchOne($select) > 0;
    }
}
