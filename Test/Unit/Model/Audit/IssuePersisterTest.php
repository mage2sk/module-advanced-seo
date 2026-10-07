<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssuePersister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IssuePersisterTest extends TestCase
{
    private function persister(
        AdapterInterface $connection,
        bool $tableExists = true,
        ?LoggerInterface $logger = null,
        ?Select $select = null
    ): IssuePersister {
        $connection->method('isTableExists')->willReturn($tableExists);
        if ($select === null) {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnSelf();
            $select->method('where')->willReturnSelf();
            $select->method('group')->willReturnSelf();
            $select->method('order')->willReturnSelf();
            $select->method('limit')->willReturnSelf();
        }
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 08:00:00');

        return new IssuePersister($resource, $dateTime, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function issues(int $count): array
    {
        $issues = [];
        for ($i = 0; $i < $count; $i++) {
            $issues[] = new Issue('missing_title', 'error', 'meta', 'https://example.com/p' . $i);
        }

        return $issues;
    }

    public function testAvailabilityAndNextRunId(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('4', null);
        $persister = $this->persister($connection);

        $this->assertTrue($persister->isAvailable());
        $this->assertSame(5, $persister->nextRunId());
        $this->assertSame(1, $persister->nextRunId());
    }

    public function testMissingTableSavesNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('beginTransaction');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO audit: table panth_seo_audit_issue does not exist, issues not saved.');
        $persister = $this->persister($connection, false, $logger);

        $this->assertSame(0, $persister->persist(1, $this->issues(2)));
        $this->assertSame([], $persister->getRunIds(1));
        $this->assertSame(0, $persister->getPreviousRunId(1, 5));
        $this->assertSame([], $persister->getRunIssues(5));
        $this->assertNull($persister->getRunCreatedAt(5));
    }

    public function testIssuesAreInsertedInBatchesAndOldRunsPruned(): void
    {
        $batches = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');
        $connection->method('fetchOne')->willReturn('9');
        $connection->expects($this->exactly(3))->method('insertMultiple')->willReturnCallback(
            static function (string $table, array $rows) use (&$batches): int {
                $batches[] = $rows;

                return count($rows);
            }
        );
        $connection->method('fetchCol')->willReturn(['10', '9', '8', '7', '6', '5']);
        $connection->expects($this->once())->method('delete')->with(
            'panth_seo_audit_issue',
            ['store_id = ?' => 2, 'run_id < ?' => 6]
        );

        $issues   = $this->issues(450);
        $issues[] = 'not an issue';
        $runId    = $this->persister($connection)->persist(2, $issues);

        $this->assertSame(10, $runId);
        $this->assertSame([200, 200, 50], array_map('count', $batches));
        $this->assertSame(10, $batches[0][0]['run_id']);
        $this->assertSame(2, $batches[0][0]['store_id']);
        $this->assertSame('2026-10-03 08:00:00', $batches[2][49]['created_at']);
    }

    public function testLongValuesAreTruncatedAndPruneKeepsAtLeastTwoRuns(): void
    {
        $rows = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('insertMultiple')->willReturnCallback(
            static function (string $table, array $data) use (&$rows): int {
                $rows = $data;

                return 1;
            }
        );
        $connection->method('fetchCol')->willReturn(['1', '0']);
        $connection->expects($this->never())->method('delete');

        $issue = new Issue(
            str_repeat('a', 70),
            'notice',
            'links',
            'https://example.com/' . str_repeat('u', 600),
            str_repeat("\u{00e9}", 1100)
        );
        $this->persister($connection)->persist(1, [$issue], 1);

        $this->assertSame(64, strlen($rows[0]['code']));
        $this->assertSame(IssuePersister::URL_LENGTH, mb_strlen($rows[0]['url']));
        $this->assertSame(IssuePersister::ELEMENT_LENGTH, mb_strlen($rows[0]['element']));
    }

    public function testFailureRollsBackLogsAndRethrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->method('insertMultiple')->willThrowException(new \RuntimeException('disk full'));
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO audit: saving issues for store 3 failed: disk full');

        $this->expectException(\RuntimeException::class);
        $this->persister($connection, true, $logger)->persist(3, $this->issues(1));
    }

    public function testRunQueries(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchCol')->willReturn(['8', '7']);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('6', '2026-10-01 10:00:00', '');
        $connection->method('fetchAll')->willReturn([['id' => 1]]);
        $persister = $this->persister($connection);

        $this->assertSame([8, 7], $persister->getRunIds(1));
        $this->assertSame(6, $persister->getPreviousRunId(1, 7));
        $this->assertSame('2026-10-01 10:00:00', $persister->getRunCreatedAt(7));
        $this->assertNull($persister->getRunCreatedAt(8));
        $this->assertNull($persister->getRunCreatedAt(0));
        $this->assertSame([], $persister->getRunIssues(0));
        $this->assertSame([['id' => 1]], $persister->getRunIssues(7));
    }

    public function testRunIssueFiltersAreApplied(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->expects($this->exactly(3))->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->persister($connection, true, null, $select)->getRunIssues(7, 'error', 'meta'));
    }
}
