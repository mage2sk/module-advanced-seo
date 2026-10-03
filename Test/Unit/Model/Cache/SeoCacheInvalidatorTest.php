<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Cache;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SeoCacheInvalidatorTest extends TestCase
{
    private function store(int $id): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        return $store;
    }

    public function testInvalidateRulesCleansTag(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with(['panth_seo_rule']);

        (new SeoCacheInvalidator(
            $cache,
            $this->createStub(MetaCache::class),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(LoggerInterface::class)
        ))->invalidateRules();
    }

    public function testInvalidateRulesFailureIsLogged(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willThrowException(new \RuntimeException('down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO: rule cache clean failed', ['error' => 'down']);

        (new SeoCacheInvalidator(
            $cache,
            $this->createStub(MetaCache::class),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(StoreRepositoryInterface::class),
            $logger
        ))->invalidateRules();
    }

    public function testInvalidateResolvedMetaInvalidatesStoresAndNonScheduledIndexer(): void
    {
        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('getList')->willReturn([$this->store(0), $this->store(1)]);

        $seen = [];
        $metaCache = $this->createMock(MetaCache::class);
        $metaCache->expects($this->exactly(2))->method('invalidateStore')
            ->willReturnCallback(static function (int $id) use (&$seen): void {
                $seen[] = $id;
            });

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects($this->once())->method('invalidate');

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->once())->method('get')->with(ResolvedMeta::INDEXER_ID)->willReturn($indexer);

        (new SeoCacheInvalidator(
            $this->createStub(CacheInterface::class),
            $metaCache,
            $registry,
            $repo,
            $this->createStub(LoggerInterface::class)
        ))->invalidateResolvedMeta();

        $this->assertSame([0, 1], $seen);
    }

    public function testScheduledIndexerIsNotInvalidated(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(true);
        $indexer->expects($this->never())->method('invalidate');

        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('getList')->willReturn([]);

        (new SeoCacheInvalidator(
            $this->createStub(CacheInterface::class),
            $this->createStub(MetaCache::class),
            $registry,
            $repo,
            $this->createStub(LoggerInterface::class)
        ))->invalidateResolvedMeta();
    }

    public function testFailuresAreLoggedIndependently(): void
    {
        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('getList')->willThrowException(new \RuntimeException('repo'));

        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willThrowException(new \RuntimeException('idx'));

        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')
            ->willReturnCallback(static function (string $msg, array $ctx) use (&$messages): void {
                $messages[$msg] = $ctx['error'];
            });

        (new SeoCacheInvalidator(
            $this->createStub(CacheInterface::class),
            $this->createStub(MetaCache::class),
            $registry,
            $repo,
            $logger
        ))->invalidateResolvedMeta();

        $this->assertSame([
            'Panth SEO: meta cache invalidation failed' => 'repo',
            'Panth SEO: resolved meta indexer invalidation failed' => 'idx',
        ], $messages);
    }

    public function testInvalidateAllRunsBoth(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean');

        $repo = $this->createMock(StoreRepositoryInterface::class);
        $repo->expects($this->once())->method('getList')->willReturn([]);

        $indexer = $this->createStub(IndexerInterface::class);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        (new SeoCacheInvalidator(
            $cache,
            $this->createStub(MetaCache::class),
            $registry,
            $repo,
            $this->createStub(LoggerInterface::class)
        ))->invalidateAll();
    }
}
