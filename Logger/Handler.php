<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * File handler for the Panth\AdvancedSEO debug log.
 *
 * Writes to var/log/panth_seo.log whenever the Panth\AdvancedSEO\Logger\Logger
 * receives a record. The "only when debug is on" gate lives in the calling
 * classes — this handler just owns the file target and log-level ceiling.
 */
class Handler extends Base
{
    /**
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * @var string
     */
    protected $fileName = '/var/log/panth_seo.log';
}
