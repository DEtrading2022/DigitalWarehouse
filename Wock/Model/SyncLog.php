<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Writes structured entries to the wock_sync_log table.
 *
 * Usage:
 *   $this->syncLog->info('product', '420', 'webhook', 'Product refreshed');
 *   $this->syncLog->error('order', $uuid, 'create', $e->getMessage());
 */
class SyncLog
{
    private const TABLE = 'wock_sync_log';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {}

    public function success(string $entityType, ?string $entityId, string $action, ?string $message = null): void
    {
        $this->write($entityType, $entityId, $action, 'success', $message);
    }

    public function error(string $entityType, ?string $entityId, string $action, ?string $message = null): void
    {
        $this->write($entityType, $entityId, $action, 'error', $message);
    }

    public function skipped(string $entityType, ?string $entityId, string $action, ?string $message = null): void
    {
        $this->write($entityType, $entityId, $action, 'skipped', $message);
    }

    private function write(
        string  $entityType,
        ?string $entityId,
        string  $action,
        string  $status,
        ?string $message
    ): void {
        try {
            $connection = $this->resource->getConnection();
            $connection->insert(
                $this->resource->getTableName(self::TABLE),
                [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'action'      => $action,
                    'status'      => $status,
                    'message'     => $message,
                ]
            );
        } catch (\Throwable) {
            // Never let logging failures affect the main flow
        }
    }
}
