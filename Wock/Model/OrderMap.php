<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Manages the wock_order_map table.
 *
 * Provides simple CRUD helpers for cross-referencing Magento order IDs
 * with WoCK order UUIDs so that webhook / polling callbacks can locate
 * the correct Magento order.
 */
class OrderMap
{
    private const TABLE = 'wock_order_map';

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

    public function save(
        int     $magentoOrderId,
        string  $incrementId,
        string  $wockOrderId,
        ?string $wockPartnerOrderId = null,
        string  $status = 'pending'
    ): void {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable(),
            [
                'magento_order_id'            => $magentoOrderId,
                'magento_order_increment_id'  => $incrementId,
                'wock_order_id'               => $wockOrderId,
                'wock_partner_order_id'       => $wockPartnerOrderId,
                'status'                      => $status,
            ],
            ['wock_order_id', 'wock_partner_order_id', 'status', 'updated_at']
        );
    }

    public function updateStatus(string $wockOrderId, string $status): void
    {
        $this->getConnection()->update(
            $this->getTable(),
            ['status' => $status],
            ['wock_order_id = ?' => $wockOrderId]
        );
    }

    // ── Read ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function getByWockOrderId(string $wockOrderId): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('wock_order_id = ?', $wockOrderId)
            ->limit(1);

        $row = $this->getConnection()->fetchRow($select);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByMagentoOrderId(int $magentoOrderId): ?array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('magento_order_id = ?', $magentoOrderId)
            ->limit(1);

        $row = $this->getConnection()->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Return all orders in a given status, e.g. 'pending' or 'ready'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByStatus(string $status): array
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getTable())
            ->where('status = ?', $status)
            ->order('created_at ASC');

        return $this->getConnection()->fetchAll($select);
    }
}
