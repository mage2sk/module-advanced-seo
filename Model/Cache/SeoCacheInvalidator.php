<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Cache;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Psr\Log\LoggerInterface;

class SeoCacheInvalidator
{
    public const RULE_CACHE_TAG = 'panth_seo_rule';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly MetaCache $metaCache,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function invalidateRules(): void
    {
        try {
            $this->cache->clean([self::RULE_CACHE_TAG]);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: rule cache clean failed', ['error' => $e->getMessage()]);
        }
    }

    public function invalidateResolvedMeta(): void
    {
        try {
            foreach ($this->storeRepository->getList() as $store) {
                $this->metaCache->invalidateStore((int) $store->getId());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: meta cache invalidation failed', ['error' => $e->getMessage()]);
        }

        try {
            $indexer = $this->indexerRegistry->get(ResolvedMeta::INDEXER_ID);
            if (!$indexer->isScheduled()) {
                $indexer->invalidate();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: resolved meta indexer invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function invalidateAll(): void
    {
        $this->invalidateRules();
        $this->invalidateResolvedMeta();
    }
}
