<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Helper;

use Magento\Framework\Logger\Handler\Base as BaseHandler;
use Monolog\Logger;

/**
 * Writes WoCK-specific logs to var/log/wock.log
 */
class Logger extends \Monolog\Logger
{
    // Inherited — just defines the name used by Monolog
}
