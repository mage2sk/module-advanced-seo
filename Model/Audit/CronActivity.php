<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class CronActivity
{
    public const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    public function isActive(): bool
    {
        return $this->lastRunAt() !== null;
    }

    public function lastRunAt(): ?string
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('cron_schedule');

        if (!$connection->isTableExists($table)) {
            return null;
        }

        $since = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - self::WINDOW_SECONDS
        );

        $lastRun = $connection->fetchOne(
            $connection->select()
                ->from($table, ['executed_at'])
                ->where('status IN (?)', ['success', 'running', 'error'])
                ->where('executed_at IS NOT NULL')
                ->where('executed_at >= ?', $since)
                ->order('executed_at DESC')
                ->limit(1)
        );

        return is_string($lastRun) && $lastRun !== '' ? $lastRun : null;
    }
}
