<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Cache;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContextFactory;
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
        private readonly LoggerInterface $logger,
        private readonly ?TypeListInterface $cacheTypeList = null,
        private readonly ?CacheContextFactory $cacheContextFactory = null,
        private readonly ?EventManager $eventManager = null
    ) {
    }

    public function cleanPageCache(): void
    {
        if ($this->cacheTypeList === null) {
            return;
        }
        try {
            $this->cacheTypeList->cleanType('full_page');
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: page cache clean failed', ['error' => $e->getMessage()]);
        }
    }

    public function cleanEntityPages(string $entityType, array $entityIds): void
    {
        $prefix = match ($entityType) {
            'product' => 'cat_p',
            'category' => 'cat_c',
            'cms', 'cms_page' => 'cms_p',
            default => '',
        };
        $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
        if ($prefix === '' || $ids === [] || $this->cacheContextFactory === null || $this->eventManager === null) {
            return;
        }
        try {
            $context = $this->cacheContextFactory->create();
            $context->registerEntities($prefix, $ids);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: page cache clean failed', ['error' => $e->getMessage()]);
        }
    }

    public function invalidateRules(): void
    {
        try {
            $this->cache->clean([self::RULE_CACHE_TAG]);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: rule cache clean failed', ['error' => $e->getMessage()]);
        }
        $this->cleanPageCache();
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
        $this->cleanPageCache();
    }

    public function invalidateAll(): void
    {
        $this->invalidateRules();
        $this->invalidateResolvedMeta();
    }
}
