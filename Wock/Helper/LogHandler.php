<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Helper;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class LogHandler extends Base
{
    protected $loggerType = Logger::DEBUG;
    protected $fileName   = '/var/log/wock.log';
}
