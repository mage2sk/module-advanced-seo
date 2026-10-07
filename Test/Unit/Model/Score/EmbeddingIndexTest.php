<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Score\EmbeddingIndex;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbeddingIndexTest extends TestCase
{
    private function index(
        ?AdapterInterface $connection = null,
        ?LoggerInterface $logger = null,
        ?EntityTableMap $map = null
    ): EmbeddingIndex {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection ?? $this->createStub(AdapterInterface::class));
        $resource->method('getTableName')->willReturnArgument(0);

        return new EmbeddingIndex(
            $resource,
            $this->createStub(DateTime::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            $map ?? $this->createStub(EntityTableMap::class)
        );
    }

    private function select(int $whereCalls): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->exactly($whereCalls))->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    public function testEmptyTextGivesAZeroVector(): void
    {
        $vector = $this->index()->vectorize("  <p> </p> ");

        $this->assertCount(EmbeddingIndex::DIMS, $vector);
        $this->assertSame(0.0, array_sum($vector));
    }

    public function testVectorsAreUnitLengthAndIgnoreMarkupAndCase(): void
    {
        $index = $this->index();
        $a = $index->vectorize('<b>BLUE</b>   cotton Shirt');
        $b = $index->vectorize('blue cotton shirt');

        $this->assertSame($a, $b);
        $this->assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(static fn ($v) => $v * $v, $a))), 0.000001);
        $this->assertEqualsWithDelta(1.0, $index->cosine($a, $b), 0.000001);
        $this->assertLessThan(0.5, $index->cosine($a, $index->vectorize('kitchen knife set')));
    }

    public function testCosineEdgeCases(): void
    {
        $index = $this->index();
        $this->assertSame(0.0, $index->cosine([], [1.0]));
        $this->assertSame(0.0, $index->cosine([0.0, 0.0], [1.0, 0.0]));
        $this->assertEqualsWithDelta(0.0, $index->cosine([1.0, 0.0], [0.0, 1.0]), 0.000001);
        $this->assertEqualsWithDelta(1.0, $index->cosine([2.0, 0.0, 9.0], [1.0, 0.0]), 0.000001);
    }

    public function testStorePacksTheVector(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'panth_seo_meta_embedding',
            $this->callback(function (array $row): bool {
                $this->assertSame('product', $row['entity_type']);
                $this->assertSame(3, $row['entity_id']);
                $this->assertSame('meta', $row['field']);
                $this->assertSame(pack('g*', 0.5, 0.25), $row['vector']);
                $this->assertSame(hash('sha256', pack('g*', 0.5, 0.25)), $row['hash']);
                $this->assertSame(EmbeddingIndex::DIMS, $row['dimensions']);

                return true;
            }),
            ['hash', 'vector', 'dimensions']
        );

        $this->index($connection)->store('product', 3, 1, [0.5, 0.25]);
    }

    public function testStoreFailureIsLogged(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('deadlock'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Panth SEO embedding store failed: deadlock');

        $this->index($connection, $logger)->store('product', 3, 1, [1.0]);
    }

    public function testLoadUnpacksTheStoredVector(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select(3));
        $connection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['vector' => pack('g*', 0.5, -1.0)],
            false,
            ['vector' => '']
        );
        $index = $this->index($connection);

        $this->assertSame([0.5, -1.0], $index->load('product', 1, 1));
    }

    public function testLoadReturnsNullOrEmptyForMissingData(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnOnConsecutiveCalls(false, ['vector' => '']);
        $index = $this->index($connection);

        $this->assertNull($index->load('product', 1, 1));
        $this->assertSame([], $index->load('product', 2, 1));
    }

    public function testFindSimilarRanksAndLimitsResults(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select(3));
        $connection->method('fetchAll')->willReturn([
            ['entity_type' => 'product', 'entity_id' => '2', 'store_id' => '1', 'vector' => pack('g*', 0.0, 1.0)],
            ['entity_type' => 'product', 'entity_id' => '3', 'store_id' => '1', 'vector' => pack('g*', 1.0, 0.0)],
            ['entity_type' => 'product', 'entity_id' => '4', 'store_id' => '1', 'vector' => pack('g*', 1.0, 1.0)],
        ]);
        $map = $this->createMock(EntityTableMap::class);
        $map->method('aliasesFor')->willReturn(['product']);
        $map->expects($this->once())->method('joinExisting')
            ->with($this->isInstanceOf(Select::class), 'product', 'embedding', 'entity_id')
            ->willReturn(true);

        $results = $this->index($connection, null, $map)->findSimilar('product', 1, [1.0, 0.0], 9, 2);

        $this->assertSame([3, 4], array_column($results, 'entity_id'));
        $this->assertEqualsWithDelta(1.0, $results[0]['similarity'], 0.000001);
        $this->assertSame(1, $results[0]['store_id']);
    }

    public function testFindSimilarWithoutExclusionAddsNoExtraFilter(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select(2));
        $connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->index($connection)->findSimilar('cms', 1, [1.0]));
    }
}
