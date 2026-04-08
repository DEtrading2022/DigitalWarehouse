<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Manages the wock_order_keys table.
 *
 * Stores one row per WoCK product item in a Magento order.
 * Initially holds a placeholder key; once the order reaches the
 * configured fulfillment status, the real key from the WoCK API
 * replaces the placeholder.
 */
class WockOrderKey
{
    private const TABLE = 'wock_order_keys';

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
        $placeholder = sprintf('PENDING-ORD%s-WP%d', $orderIncrementId, $wockProductId);

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
                'product_key'        => $placeholder,
                'store_id'           => $storeId,
                'status'             => 'pending',
            ]
        );
    }

    /**
     * Update a key row with the real product key from the WoCK API.
     */
    public function fulfill(int $keyId, string $productKey, ?string $wockOrderId = null): void
    {
        $bind = [
            'product_key' => $productKey,
            'status'      => 'fulfilled',
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
     * Mark a key row as errored.
     */
    public function markError(int $keyId, string $errorMessage): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            [
                'status'      => 'error',
                'product_key' => $errorMessage,
            ],
            ['key_id = ?' => $keyId]
        );
    }

    // ── Read ───────────────────────────────────────────────────────────

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
     * Check if placeholder rows already exist for order.
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
