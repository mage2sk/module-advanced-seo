<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\InternalLinking;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\InternalLinking\PageRank;
use Panth\AdvancedSEO\Model\InternalLinking\Suggester;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SuggesterTest extends TestCase
{
    private static function vec(array $values): string
    {
        return pack('f*', ...$values);
    }

    /**
     * @param array|false $sourceRows rows returned by fetchRow in order (description, title)
     */
    private function resource(
        bool $relatedTable,
        array $persisted,
        array $sourceRows,
        array $candidates,
        bool $tableCheckThrows = false
    ): ResourceConnection {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $m) {
            $select->method($m)->willReturnSelf();
        }

        $candidates[] = false;
        $stmt = $this->createStub(\Zend_Db_Statement_Interface::class);
        $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$candidates);

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        if ($tableCheckThrows) {
            $conn->method('isTableExists')->willThrowException(new \RuntimeException('x'));
        } else {
            $conn->method('isTableExists')->willReturn($relatedTable);
        }
        $conn->method('fetchAll')->willReturn($persisted);
        $conn->method('fetchRow')->willReturnOnConsecutiveCalls(...$sourceRows);
        $conn->method('query')->willReturn($stmt);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function emptyCache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        return $cache;
    }

    public function testCachedResultReturnedDirectly(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('load')->with('panth_seo_related_1_product_9_3')
            ->willReturn('[{"type":"product","id":4,"score":0.5}]');
        $cache->expects($this->never())->method('save');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $suggester = new Suggester(
            $resource,
            $cache,
            new Json(),
            $this->createStub(PageRank::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([['type' => 'product', 'id' => 4, 'score' => 0.5]], $suggester->suggest('product', 9, 1, 3));
    }

    public function testPersistedRowsWinOverEmbeddings(): void
    {
        $suggester = new Suggester(
            $this->resource(true, [['target_type' => 'category', 'target_id' => '7', 'score' => '0.9']], [false], []),
            $this->emptyCache(),
            new Json(),
            $this->createStub(PageRank::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([['type' => 'category', 'id' => 7, 'score' => 0.9]], $suggester->suggest('product', 1, 1));
    }

    public function testEmbeddingSimilarityBlendedWithPageRank(): void
    {
        $candidates = [
            ['entity_id' => '2', 'vector' => self::vec([1.0, 0.0]), 'dimensions' => 2],
            ['entity_id' => '3', 'vector' => self::vec([1.0, 1.0]), 'dimensions' => 2],
            ['entity_id' => '4', 'vector' => self::vec([-1.0, 0.0]), 'dimensions' => 2],
            ['entity_id' => '5', 'vector' => 'short', 'dimensions' => 2],
            ['entity_id' => '6', 'vector' => self::vec([0.0, 0.0]), 'dimensions' => 2],
        ];

        $pageRank = $this->createStub(PageRank::class);
        $pageRank->method('compute')->willReturn(['product:3' => 0.4, 'product:2' => 0.0]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')
            ->with($this->isString(), 'panth_seo_related_1_product_1_5', ['panth_seo_link_graph'], 7200);

        $suggester = new Suggester(
            $this->resource(false, [], [['vector' => self::vec([1.0, 0.0]), 'dimensions' => 2]], $candidates),
            $cache,
            new Json(),
            $pageRank,
            $this->createStub(LoggerInterface::class)
        );

        $result = $suggester->suggest('product', 1, 1);

        $this->assertSame([3, 2], array_column($result, 'id'));
        $this->assertEqualsWithDelta(0.7 * (1 / sqrt(2)) + 0.3, $result[0]['score'], 1e-6);
        $this->assertEqualsWithDelta(0.7, $result[1]['score'], 1e-6);
    }

    public function testTitleEmbeddingFallbackAndPageRankFailureLogged(): void
    {
        $candidates = [['entity_id' => '2', 'vector' => self::vec([0.5]), 'dimensions' => 1]];

        $pageRank = $this->createStub(PageRank::class);
        $pageRank->method('compute')->willThrowException(new \RuntimeException('pr'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('[PanthSEO] pagerank compute failed: pr');

        $suggester = new Suggester(
            $this->resource(true, [], [false, ['vector' => self::vec([2.0]), 'dimensions' => 1]], $candidates),
            $this->emptyCache(),
            new Json(),
            $pageRank,
            $logger
        );

        $result = $suggester->suggest('category', 1, 1, 1);

        $this->assertCount(1, $result);
        $this->assertSame('category', $result[0]['type']);
        $this->assertEqualsWithDelta(0.7, $result[0]['score'], 1e-6);
    }

    public function testNoEmbeddingReturnsEmptyAndSaveFailureIgnored(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{corrupt');
        $cache->method('save')->willThrowException(new \RuntimeException('x'));

        $suggester = new Suggester(
            $this->resource(false, [], [['vector' => '', 'dimensions' => 0], ['vector' => 'abc', 'dimensions' => 0]], [], true),
            $cache,
            new Json(),
            $this->createStub(PageRank::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $suggester->suggest('product', 1, 1));
    }
}
