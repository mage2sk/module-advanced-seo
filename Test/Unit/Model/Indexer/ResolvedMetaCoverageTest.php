<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolvedMetaCoverageTest extends TestCase
{
    private array $inserts = [];
    private array $deleted = [];

    private function store(int $id): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        return $store;
    }

    private function row(string $title, array $og = [], string $source = ''): ResolvedMetaInterface
    {
        $row = $this->createStub(ResolvedMetaInterface::class);
        $row->method('getMetaTitle')->willReturn($title);
        $row->method('getMetaDescription')->willReturn('desc');
        $row->method('getMetaKeywords')->willReturn('kw');
        $row->method('getCanonicalUrl')->willReturn('https://shop.test/x');
        $row->method('getRobots')->willReturn('INDEX,FOLLOW');
        $row->method('getOgPayload')->willReturn($og);
        $row->method('getJsonldPayload')->willReturn([]);
        $row->method('getHreflangPayload')->willReturn([]);
        $row->method('getSource')->willReturn($source);
        return $row;
    }

    private function resource(array $cols): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchCol')->willReturnOnConsecutiveCalls(...$cols);
        $conn->method('delete')->willReturnCallback(function ($table, $where = '') {
            $this->deleted[] = $where === '' ? $table : [$table, $where];
            return 1;
        });
        $conn->method('insertOnDuplicate')->willReturnCallback(function ($table, array $rows, array $fields) {
            $this->inserts[] = [$table, $rows, $fields];
            return count($rows);
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testExecuteFullRebuildsEveryStoreAndType(): void
    {
        $calls = [];
        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolveBatch')->willReturnCallback(
            function (string $type, array $ids, int $storeId) use (&$calls) {
                $calls[] = [$type, $ids, $storeId];
                return $type === 'product' ? [10 => $this->row('P10', ['title' => 'og']), 11 => $this->row('P11', [], 'override')] : [];
            }
        );

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('getList')->willReturn([$this->store(0), $this->store(1)]);

        $metaCache = $this->createMock(MetaCache::class);
        $metaCache->expects($this->once())->method('invalidateEntities')->with('product', [10, 11]);

        $seoConfig = $this->createStub(SeoConfig::class);
        $seoConfig->method('isDebug')->willReturn(true);
        $debug = $this->createMock(SeoDebugLogger::class);
        $debug->expects($this->once())->method('debug')
            ->with('panth_seo: indexer.run', ['indexer' => 'panth_seo_resolved_meta', 'mode' => 'full']);

        $indexer = new ResolvedMeta(
            $this->resource([['10', '11'], ['10', '11', '12'], ['3'], [], ['2', '2', '5'], ['5']]),
            $stores,
            $resolver,
            new Json(),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EntityTableMap::class),
            $seoConfig,
            $debug,
            $metaCache
        );

        $indexer->executeFull();

        $this->assertSame([
            ['panth_seo_resolved', ['store_id = ?' => 1, 'entity_type = ?' => 'product', 'entity_id IN (?)' => [12]]],
            ['panth_seo_resolved', ['store_id NOT IN (?)' => [1]]],
        ], $this->deleted);
        $this->assertSame([
            ['product', [10, 11], 1],
            ['category', [3], 1],
            ['cms', [2, 5], 1],
        ], $calls);

        $this->assertCount(2, $this->inserts);
        [$table, $rows, $fields] = $this->inserts[0];
        $this->assertSame('panth_seo_resolved', $table);
        $this->assertContains('source', $fields);
        $this->assertContains('og_payload', $fields);
        $this->assertSame('P10', $rows[0]['meta_title']);
        $this->assertSame('{"title":"og"}', $rows[0]['og_payload']);
        $this->assertNull($rows[0]['jsonld_payload']);
        $this->assertSame('template', $rows[0]['source']);
        [, $ownRows, $ownFields] = $this->inserts[1];
        $this->assertSame(['meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'robots', 'source'], $ownFields);
        $this->assertSame('override', $ownRows[0]['source']);
        $this->assertSame(11, $ownRows[0]['entity_id']);
        $this->assertSame(1, $ownRows[0]['store_id']);
    }

    public function testResolveFailureAndCacheFailureAreLogged(): void
    {
        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolveBatch')->willReturnCallback(function (string $type) {
            if ($type === 'category') {
                throw new \RuntimeException('resolver down');
            }
            return [4 => $this->row('T')];
        });

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('getList')->willReturn([$this->store(2)]);

        $map = $this->createStub(EntityTableMap::class);
        $map->method('existingIds')->willReturnCallback(static fn (string $type, array $ids) => $type === 'cms' ? [] : $ids);

        $metaCache = $this->createStub(MetaCache::class);
        $metaCache->method('invalidateEntities')->willThrowException(new \RuntimeException('cache down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('[panth_seo_resolved_meta] resolveBatch failed store=2 type=category: resolver down');
        $logger->expects($this->once())->method('warning')
            ->with('[panth_seo_resolved_meta] meta cache clean failed: cache down');

        $indexer = new ResolvedMeta(
            $this->resource([]),
            $stores,
            $resolver,
            new Json(),
            $logger,
            $map,
            null,
            null,
            $metaCache
        );

        $indexer->executeList([4]);

        $this->assertCount(1, $this->inserts);
        $this->assertSame('product', $this->inserts[0][1][0]['entity_type']);
    }

    public function testDebugSkippedWhenDisabledAndEmptyIdsIgnored(): void
    {
        $seoConfig = $this->createStub(SeoConfig::class);
        $seoConfig->method('isDebug')->willReturn(false);
        $debug = $this->createMock(SeoDebugLogger::class);
        $debug->expects($this->never())->method('debug');

        $stores = $this->createMock(StoreRepositoryInterface::class);
        $stores->expects($this->once())->method('getList')->willReturn([$this->store(0)]);

        $indexer = new ResolvedMeta(
            $this->resource([]),
            $stores,
            $this->createStub(MetaResolverInterface::class),
            new Json(),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EntityTableMap::class),
            $seoConfig,
            $debug
        );

        $indexer->execute([]);
        $indexer->execute([5]);
        $this->assertSame([], $this->inserts);
    }
}
