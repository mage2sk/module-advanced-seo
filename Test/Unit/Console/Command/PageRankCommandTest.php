<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Console\Command\PageRankCommand;
use Panth\AdvancedSEO\Model\InternalLinking\Graph;
use Panth\AdvancedSEO\Model\InternalLinking\PageRank;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PageRankCommandTest extends TestCase
{
    private array $cleared = [];

    private array $deleted = [];

    private array $inserted = [];

    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        return $store;
    }

    private function tester(array $ranks, bool|\Throwable $hasTable = true): CommandTester
    {
        $this->cleared = [];
        $this->deleted = [];
        $this->inserted = [];

        $pageRank = $this->createStub(PageRank::class);
        $pageRank->method('compute')->willReturnCallback(static fn(int $storeId): array => $ranks[$storeId] ?? []);

        $graph = $this->createStub(Graph::class);
        $graph->method('clear')->willReturnCallback(function (int $storeId): void {
            $this->cleared[] = $storeId;
        });

        $stores = ['admin' => $this->store(0, 'admin'), 'default' => $this->store(1, 'default'), 'fr' => $this->store(2, 'fr')];
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn(array_values($stores));
        $repository->method('get')->willReturnCallback(static function (string $code) use ($stores) {
            if (!isset($stores[$code])) {
                throw new NoSuchEntityException(__('nope'));
            }
            return $stores[$code];
        });
        $repository->method('getById')->willReturnCallback(static function (int $id) use ($stores) {
            foreach ($stores as $store) {
                if ($store->getId() === $id) {
                    return $store;
                }
            }
            throw new NoSuchEntityException(__('nope'));
        });

        $connection = $this->createStub(AdapterInterface::class);
        if ($hasTable instanceof \Throwable) {
            $connection->method('isTableExists')->willThrowException($hasTable);
        } else {
            $connection->method('isTableExists')->willReturn($hasTable);
        }
        $connection->method('delete')->willReturnCallback(function (string $table, $where): int {
            $this->deleted[] = [$table, $where];
            return 1;
        });
        $connection->method('insertMultiple')->willReturnCallback(function (string $table, array $rows): int {
            $this->inserted[] = [$table, $rows];
            return count($rows);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CommandTester(
            new PageRankCommand($pageRank, $graph, $repository, $resource, $this->createStub(AppState::class))
        );
    }

    public function testAllStorefrontStoresAreRankedAndPersistedInDescendingOrder(): void
    {
        $tester = $this->tester([
            1 => ['product:5' => 0.1, 'category:3' => 0.7, 'broken' => 0.9],
            2 => [],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([1, 2], $this->cleared);
        $this->assertSame([
            ['panth_seo_related', ['store_id = ?' => 1]],
            ['panth_seo_related', ['store_id = ?' => 2]],
        ], $this->deleted);
        $this->assertCount(1, $this->inserted);
        $this->assertSame([
            ['source_type' => 'category', 'source_id' => 3, 'target_type' => 'category', 'target_id' => 3, 'score' => 0.7, 'store_id' => 1],
            ['source_type' => 'product', 'source_id' => 5, 'target_type' => 'product', 'target_id' => 5, 'score' => 0.1, 'store_id' => 1],
        ], $this->inserted[0][1]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Computing PageRank for store default...', $display);
        $this->assertStringContainsString('nodes ranked: 3', $display);
        $this->assertStringContainsString('PageRank persisted.', $display);
    }

    public function testLargeRankSetsAreWrittenInBatchesOfFiveHundred(): void
    {
        $ranks = [];
        for ($i = 1; $i <= 1200; $i++) {
            $ranks['product:' . $i] = 1 / $i;
        }

        $this->tester([1 => $ranks])->execute(['--store' => '1']);

        $this->assertSame([500, 500, 200], array_map(static fn(array $call): int => count($call[1]), $this->inserted));
    }

    public function testAStoreCanBeChosenByCode(): void
    {
        $tester = $this->tester([2 => ['product:1' => 1.0]]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--store' => 'fr']));
        $this->assertSame([2], $this->cleared);
    }

    public function testUnknownStoreFails(): void
    {
        $tester = $this->tester([]);

        $this->assertSame(Command::FAILURE, $tester->execute(['--store' => 'nowhere']));
        $this->assertStringContainsString('Store not found: nowhere', $tester->getDisplay());
        $this->assertSame([], $this->cleared);
    }

    public function testWithoutTheRelatedTableRanksAreComputedButNotStored(): void
    {
        $tester = $this->tester([1 => ['product:1' => 1.0]], new \RuntimeException('no db'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--store' => '1']));
        $this->assertSame([1], $this->cleared);
        $this->assertSame([], $this->deleted);
        $this->assertStringNotContainsString('PageRank persisted.', $tester->getDisplay());
    }
}
