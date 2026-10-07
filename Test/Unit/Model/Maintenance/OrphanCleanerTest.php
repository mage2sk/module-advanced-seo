<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Maintenance;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrphanCleanerTest extends TestCase
{
    private array $deletes = [];

    private array $missingTables = [];

    private array $throwingTables = [];

    private array $orphanBatches = [];

    private array $orphanCounts = [];

    private function cleaner(): OrphanCleaner
    {
        $this->deletes = [];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            fn (string $table): bool => !in_array($table, $this->missingTables, true)
        );
        $connection->method('delete')->willReturnCallback(
            function (string $table, $where = ''): int {
                if (in_array($table, $this->throwingTables, true)) {
                    throw new \RuntimeException('delete blew up on ' . $table);
                }
                $this->deletes[] = ['table' => $table, 'where' => $where];
                return 1;
            }
        );
        $connection->method('fetchCol')->willReturnCallback(
            function (): array {
                return array_shift($this->orphanBatches) ?? [];
            }
        );
        $connection->method('fetchOne')->willReturnCallback(
            function (): int {
                return (int) (array_shift($this->orphanCounts) ?? 0);
            }
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => $t);

        $map = $this->createStub(EntityTableMap::class);
        $map->method('aliasesFor')->willReturnCallback(
            static fn (string $type): array => $type === 'cms' ? ['cms', 'cms_page'] : [$type]
        );
        $map->method('sweepTypes')->willReturn(['product']);
        $map->method('orphanIdsSelect')->willReturn($this->createStub(Select::class));
        $map->method('orphanCountSelect')->willReturn($this->createStub(Select::class));

        return new OrphanCleaner($resource, $map, $this->createStub(LoggerInterface::class));
    }

    private function tablesDeleted(): array
    {
        return array_values(array_unique(array_column($this->deletes, 'table')));
    }

    public function testForEntityClearsEveryDerivedTable(): void
    {
        $this->cleaner()->forEntity('product', 42);

        $this->assertSame([
            'panth_seo_score',
            'panth_seo_meta_embedding',
            'panth_seo_resolved',
            'panth_seo_related',
        ], $this->tablesDeleted());
    }

    public function testForEntityClearsBothSidesOfTheLinkGraph(): void
    {
        $this->cleaner()->forEntity('product', 42);

        $related = array_values(array_filter(
            $this->deletes,
            static fn (array $d): bool => $d['table'] === 'panth_seo_related'
        ));

        $this->assertCount(2, $related);
        $this->assertArrayHasKey('source_type IN (?)', $related[0]['where']);
        $this->assertArrayHasKey('target_type IN (?)', $related[1]['where']);
    }

    public function testForEntityLeavesHandEnteredRowsAlone(): void
    {
        $this->cleaner()->forEntity('product', 42);

        $this->assertNotContains('panth_seo_override', $this->tablesDeleted());
        $this->assertNotContains('panth_seo_custom_canonical', $this->tablesDeleted());
    }

    public function testForEntityClearsHandEnteredRowsWhenAsked(): void
    {
        $this->cleaner()->forEntity('product', 42, true);

        $this->assertContains('panth_seo_override', $this->tablesDeleted());
        $this->assertContains('panth_seo_custom_canonical', $this->tablesDeleted());
    }

    public function testForEntityMatchesBothCmsTypeSpellings(): void
    {
        $this->cleaner()->forEntity('cms', 7);

        $this->assertSame(['cms', 'cms_page'], $this->deletes[0]['where']['entity_type IN (?)']);
        $this->assertSame(7, $this->deletes[0]['where']['entity_id = ?']);
    }

    public function testForEntityDeletesAcrossEveryStore(): void
    {
        $this->cleaner()->forEntity('product', 42);

        foreach ($this->deletes as $delete) {
            $this->assertArrayNotHasKey('store_id = ?', $delete['where']);
        }
    }

    public function testForEntityIgnoresAnInvalidId(): void
    {
        $this->assertSame([], $this->cleaner()->forEntity('product', 0));
        $this->assertSame([], $this->deletes);
    }

    public function testForEntitySkipsTablesThatDoNotExist(): void
    {
        $this->missingTables = ['panth_seo_resolved'];
        $this->cleaner()->forEntity('product', 42);
        $this->missingTables = [];

        $this->assertNotContains('panth_seo_resolved', $this->tablesDeleted());
        $this->assertContains('panth_seo_score', $this->tablesDeleted());
    }

    public function testForEntityKeepsGoingWhenOneTableFails(): void
    {
        $this->throwingTables = ['panth_seo_score'];
        $removed = $this->cleaner()->forEntity('product', 42);
        $this->throwingTables = [];

        $this->assertArrayNotHasKey('panth_seo_score', $removed);
        $this->assertArrayHasKey('panth_seo_meta_embedding', $removed);
    }

    public function testForEntityReportsRowsRemovedPerTable(): void
    {
        $removed = $this->cleaner()->forEntity('product', 42);

        $this->assertSame(1, $removed['panth_seo_score']);
        $this->assertSame(2, $removed['panth_seo_related']);
    }

    public function testSweepDeletesOrphansInBatchesUntilAShortBatchArrives(): void
    {
        $full = range(1, OrphanCleaner::BATCH_SIZE);
        $this->orphanBatches = [$full, [9001, 9002]];

        $cleaner = $this->cleaner();
        $removed = $cleaner->sweep();

        $this->assertSame(OrphanCleaner::BATCH_SIZE + 2, $removed['panth_seo_score']);
        $this->assertCount(2, array_filter(
            $this->deletes,
            static fn (array $d): bool => $d['table'] === 'panth_seo_score'
        ));
    }

    public function testSweepStopsAtTheFirstEmptyBatch(): void
    {
        $this->orphanBatches = [];

        $removed = $this->cleaner()->sweep();

        $this->assertSame([], $removed);
        $this->assertSame([], $this->deletes);
    }

    public function testCountReportsOrphansWithoutDeletingAnything(): void
    {
        $this->orphanCounts = [13, 11, 4, 2, 2];

        $counts = $this->cleaner()->count();

        $this->assertSame(13, $counts['panth_seo_score']);
        $this->assertSame([], $this->deletes);
    }

    public function testCountIsNotCappedByTheBatchSize(): void
    {
        $this->orphanCounts = [OrphanCleaner::BATCH_SIZE * 7];

        $counts = $this->cleaner()->count();

        $this->assertSame(OrphanCleaner::BATCH_SIZE * 7, $counts['panth_seo_score']);
    }
}
