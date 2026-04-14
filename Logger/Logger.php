<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Logger;

use Monolog\Logger as MonologLogger;

/**
 * Dedicated Monolog logger channel for Panth\AdvancedSEO.
 *
 * Instances are wired via etc/di.xml with the Handler below, so any calls
 * reach var/log/panth_seo.log (independent of the system logger).
 *
 * Log entries are gated in the caller with Config::isDebug() so nothing is
 * written unless the "Debug Logging" toggle in Stores -> Configuration ->
 * Panth Extensions -> Advanced SEO -> General is enabled.
 */
class Logger extends MonologLogger
{
}
