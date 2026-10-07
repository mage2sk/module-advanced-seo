<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Cron\DuplicateScan;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DuplicateScanTest extends TestCase
{
    private array $deleted = [];

    private array $inserted = [];

    private array $logged = [];

    private function cron(bool $tablesExist, array $duplicates, ?\Throwable $deleteFails = null): DuplicateScan
    {
        $this->deleted = [];
        $this->inserted = [];
        $this->logged = [];

        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'having', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $fetches = 0;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tablesExist);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($v): string => '`' . $v . '`');
        $connection->method('delete')->willReturnCallback(
            function (string $table) use ($deleteFails): int {
                if ($deleteFails !== null) {
                    throw $deleteFails;
                }
                $this->deleted[] = $table;
                return 1;
            }
        );
        $connection->method('fetchAll')->willReturnCallback(
            static function () use (&$fetches, $duplicates): array {
                $fetches++;
                if ($fetches === 1) {
                    return $duplicates;
                }
                if ($fetches === 2 && $duplicates !== []) {
                    return [['entity_type' => 'product', 'entity_id' => '4']];
                }
                return [];
            }
        );
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $fields): int {
                $this->inserted[] = [$table, $data, $fields];
                return 1;
            }
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn($v): string => json_encode($v));

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message): void {
                $this->logged[] = $message;
            }
        );

        return new DuplicateScan($resource, $serializer, $dateTime, $logger);
    }

    public function testNothingRunsWhileTheModuleIsSwitchedOff(): void
    {
        $resource = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $config = $this->createStub(\Panth\AdvancedSEO\Helper\Config::class);
        $config->method('isEnabled')->willReturn(false);

        (new DuplicateScan(
            $resource,
            $this->createStub(\Magento\Framework\Serialize\SerializerInterface::class),
            $this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class),
            $this->createStub(\Psr\Log\LoggerInterface::class),
            $config
        ))->execute();
    }

    public function testMissingTablesStopTheScan(): void
    {
        $this->cron(false, [['store_id' => 1, 'content_hash' => 'h', 'dup_count' => 2]])->execute();

        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->inserted);
    }

    public function testDuplicatesAreRecordedWithASampleOfEntities(): void
    {
        $this->cron(true, [['store_id' => '1', 'content_hash' => 'abc', 'dup_count' => '3']])->execute();

        $this->assertSame(['panth_seo_duplicate'], $this->deleted);
        $this->assertCount(1, $this->inserted);
        [$table, $data, $fields] = $this->inserted[0];
        $this->assertSame('panth_seo_duplicate', $table);
        $this->assertSame([
            'store_id'        => 1,
            'field'           => 'meta_title',
            'hash'            => 'abc',
            'count'           => 3,
            'sample_entities' => '[{"entity_type":"product","entity_id":"4"}]',
            'detected_at'     => '2026-01-01 00:00:00',
        ], $data);
        $this->assertSame(['count', 'sample_entities', 'detected_at'], $fields);
    }

    public function testNoDuplicatesClearsTheTableOnly(): void
    {
        $this->cron(true, [])->execute();

        $this->assertSame(['panth_seo_duplicate'], $this->deleted);
        $this->assertSame([], $this->inserted);
    }

    public function testAFailureIsLogged(): void
    {
        $this->cron(true, [], new \RuntimeException('locked'))->execute();

        $this->assertSame(['Panth SEO duplicate scan failed: locked'], $this->logged);
    }
}
