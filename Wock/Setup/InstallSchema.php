<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * @deprecated Use etc/db_schema.xml instead (declarative schema).
 *
 * This file is kept for backward compatibility with installations that
 * ran setup:upgrade before the migration to db_schema.xml. Magento will
 * skip this class when db_schema.xml is present for the same tables.
 */
class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        // Schema is now managed by etc/db_schema.xml
        // This file is intentionally empty.
    }
}
