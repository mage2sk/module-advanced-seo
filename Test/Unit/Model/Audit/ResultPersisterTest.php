<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\ResultPersister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResultPersisterTest extends TestCase
{
    private function persister(MockObject $connection, bool $tableExists = true): ResultPersister
    {
        $connection->method('isTableExists')->willReturn($tableExists);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-12 10:00:00');

        return new ResultPersister($resource, $dateTime, $this->createStub(LoggerInterface::class));
    }

    private function results(int $count): array
    {
        $results = [];
        for ($i = 1; $i <= $count; $i++) {
            $results[] = new CrawlResult('https://example.com/p' . $i, 200);
        }

        return $results;
    }

    public function testEmptyResultsNeverTouchTheStoredRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('insertMultiple');
        $connection->expects($this->never())->method('beginTransaction');

        $this->assertSame(0, $this->persister($connection)->persist(1, []));
    }

    public function testMissingTableIsReportedAsZeroSaved(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');

        $this->assertSame(0, $this->persister($connection, false)->persist(1, $this->results(3)));
    }

    public function testResultsReplaceTheStoreRowsInOneTransaction(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())
            ->method('delete')
            ->with('panth_seo_crawl_result', ['store_id = ?' => 2]);
        $connection->expects($this->once())
            ->method('insertMultiple')
            ->willReturnCallback(function (string $table, array $rows): int {
                $this->assertCount(2, $rows);
                $this->assertSame(2, $rows[0]['store_id']);
                $this->assertSame('2026-09-12 10:00:00', $rows[0]['crawled_at']);
                return count($rows);
            });
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');

        $this->assertSame(2, $this->persister($connection)->persist(2, $this->results(2)));
    }

    public function testRowsAreInsertedInBatchesOfOneHundred(): void
    {
        $batches = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->exactly(3))
            ->method('insertMultiple')
            ->willReturnCallback(
                function (string $table, array $rows) use (&$batches): int {
                    $batches[] = count($rows);
                    return count($rows);
                }
            );

        $this->assertSame(250, $this->persister($connection)->persist(1, $this->results(250)));
        $this->assertSame([100, 100, 50], $batches);
    }

    public function testFailureRollsBackAndRethrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('insertMultiple')->willThrowException(new \RuntimeException('deadlock'));
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);

        $this->persister($connection)->persist(1, $this->results(1));
    }
}
