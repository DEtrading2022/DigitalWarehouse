<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Creates the wock_sync_log table used to record sync events,
 * API errors, and processed order/delivery references.
 */
class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        // ── wock_sync_log ─────────────────────────────────────────────
        $tableName = $setup->getTable('wock_sync_log');
        if (!$connection->isTableExists($tableName)) {
            $table = $connection->newTable($tableName)
                ->addColumn(
                    'log_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Log ID'
                )
                ->addColumn(
                    'entity_type',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false],
                    'Entity type: product | order | delivery | token'
                )
                ->addColumn(
                    'entity_id',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => true, 'default' => null],
                    'WoCK entity ID (product int or order UUID)'
                )
                ->addColumn(
                    'action',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'Action: sync | webhook | create | delete | fetch'
                )
                ->addColumn(
                    'status',
                    Table::TYPE_TEXT,
                    16,
                    ['nullable' => false, 'default' => 'success'],
                    'success | error | skipped'
                )
                ->addColumn(
                    'message',
                    Table::TYPE_TEXT,
                    '64k',
                    ['nullable' => true, 'default' => null],
                    'Optional detail / error message'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addIndex(
                    $setup->getIdxName('wock_sync_log', ['entity_type', 'action']),
                    ['entity_type', 'action']
                )
                ->addIndex(
                    $setup->getIdxName('wock_sync_log', ['created_at']),
                    ['created_at']
                )
                ->setComment('WoCK API sync / webhook activity log');

            $connection->createTable($table);
        }

        // ── wock_order_map ────────────────────────────────────────────
        // Maps Magento order IDs to WoCK order UUIDs for cross-reference.
        $tableName = $setup->getTable('wock_order_map');
        if (!$connection->isTableExists($tableName)) {
            $table = $connection->newTable($tableName)
                ->addColumn(
                    'map_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Map ID'
                )
                ->addColumn(
                    'magento_order_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false],
                    'Magento sales_order.entity_id'
                )
                ->addColumn(
                    'magento_order_increment_id',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false],
                    'Magento order increment ID'
                )
                ->addColumn(
                    'wock_order_id',
                    Table::TYPE_TEXT,
                    36,
                    ['nullable' => false],
                    'WoCK order UUID'
                )
                ->addColumn(
                    'wock_partner_order_id',
                    Table::TYPE_TEXT,
                    50,
                    ['nullable' => true, 'default' => null],
                    'WoCK partnerOrderId (your reference sent to WoCK)'
                )
                ->addColumn(
                    'status',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => false, 'default' => 'pending'],
                    'pending | ready | fulfilled | cancelled | error'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addColumn(
                    'updated_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
                    'Updated At'
                )
                ->addIndex(
                    $setup->getIdxName('wock_order_map', ['magento_order_id'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                    ['magento_order_id'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->addIndex(
                    $setup->getIdxName('wock_order_map', ['wock_order_id'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                    ['wock_order_id'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->addIndex(
                    $setup->getIdxName('wock_order_map', ['status']),
                    ['status']
                )
                ->setComment('Magento ↔ WoCK order cross-reference');

            $connection->createTable($table);
        }

        $setup->endSetup();
    }
}
