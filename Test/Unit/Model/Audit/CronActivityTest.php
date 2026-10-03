<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Audit\CronActivity;
use PHPUnit\Framework\TestCase;

class CronActivityTest extends TestCase
{
    private const NOW = 1757671200;

    private array $where = [];

    private function activity(bool $tableExists, mixed $lastRun): CronActivity
    {
        $this->where = [];

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select): Select {
                $this->where[$condition] = $value;
                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($lastRun);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn ($format = null, $input = null): string => gmdate(
                $format ?: 'Y-m-d H:i:s',
                $input !== null ? (int) $input : self::NOW
            )
        );

        return new CronActivity($resource, $dateTime);
    }

    public function testNoCronScheduleTableMeansCronIsNotRunning(): void
    {
        $this->assertFalse($this->activity(false, '2026-09-12 09:00:00')->isActive());
    }

    public function testARecentlyExecutedJobMeansCronIsRunning(): void
    {
        $activity = $this->activity(true, '2026-09-12 09:55:00');

        $this->assertTrue($activity->isActive());
        $this->assertSame('2026-09-12 09:55:00', $activity->lastRunAt());
    }

    public function testNoRowInTheWindowMeansCronIsNotRunning(): void
    {
        $activity = $this->activity(true, false);

        $this->assertFalse($activity->isActive());
        $this->assertNull($activity->lastRunAt());
    }

    public function testTheWindowIsOneGmtHourBeforeNow(): void
    {
        $this->activity(true, false)->isActive();

        $this->assertArrayHasKey('executed_at >= ?', $this->where);
        $this->assertSame(
            gmdate('Y-m-d H:i:s', self::NOW - CronActivity::WINDOW_SECONDS),
            $this->where['executed_at >= ?']
        );
    }
}
