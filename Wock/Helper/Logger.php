<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Helper;

use Magento\Framework\Logger\Monolog;

/**
 * WoCK-specific logger.
 *
 * Configured via di.xml to write to var/log/wock.log through LogHandler.
 * The handler is injected by the DI <type> configuration, not by the class itself.
 */
class Logger extends Monolog
{
    // Intentionally empty — handlers are injected via di.xml
}
