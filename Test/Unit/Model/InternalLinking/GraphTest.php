<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\InternalLinking;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\InternalLinking\Graph;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GraphTest extends TestCase
{
    private function statement(array $rows): \Zend_Db_Statement_Interface
    {
        $rows[] = false;
        $stmt = $this->createStub(\Zend_Db_Statement_Interface::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
        return $stmt;
    }

    private function resource(array $productRows, array $treeRows): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('query')->willReturnOnConsecutiveCalls(
            $this->statement($productRows),
            $this->statement($treeRows)
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testNodeFormat(): void
    {
        $this->assertSame('product:12', Graph::node('product', 12));
    }

    public function testBuildFromDatabaseCachesAndMemoizes(): void
    {
        $resource = $this->resource(
            [['category_id' => '3', 'product_id' => '10'], ['category_id' => '3', 'product_id' => '10']],
            [['entity_id' => '3', 'parent_id' => '2'], ['entity_id' => '4', 'parent_id' => '4']]
        );

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('load')->with('panth_seo_link_graph_1')->willReturn(false);
        $cache->expects($this->once())->method('save')
            ->with($this->isString(), 'panth_seo_link_graph_1', ['panth_seo_link_graph'], 3600);

        $graph = new Graph($resource, $cache, new Json(), $this->createStub(LoggerInterface::class));
        $adj = $graph->build(1);

        $this->assertEqualsWithDelta(2.0, $adj['category:3']['product:10'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $adj['product:10']['category:3'], 1e-9);
        $this->assertEqualsWithDelta(0.7, $adj['category:2']['category:3'], 1e-9);
        $this->assertEqualsWithDelta(0.35, $adj['category:3']['category:2'], 1e-9);
        $this->assertArrayNotHasKey('category:4', $adj);

        $this->assertSame($adj, $graph->build(1));
    }

    public function testBuildUsesValidCache(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{"a:1":{"b:2":1.5}}');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $graph = new Graph($resource, $cache, new Json(), $this->createStub(LoggerInterface::class));

        $this->assertSame(['a:1' => ['b:2' => 1.5]], $graph->build(5));
    }

    public function testCorruptCacheRebuildsAndSaveFailureIsLogged(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{corrupt');
        $cache->method('save')->willThrowException(new \RuntimeException('full'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('[PanthSEO] graph cache failed: full');

        $graph = new Graph($this->resource([], []), $cache, new Json(), $logger);

        $this->assertSame([], $graph->build(1));
    }

    public function testClearDropsMemoAndCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->exactly(2))->method('load')->willReturn('{"x:1":{"y:2":1}}');
        $cache->expects($this->once())->method('remove')->with('panth_seo_link_graph_3');

        $graph = new Graph(
            $this->createStub(ResourceConnection::class),
            $cache,
            new Json(),
            $this->createStub(LoggerInterface::class)
        );

        $graph->build(3);
        $graph->clear(3);
        $graph->build(3);
    }
}
