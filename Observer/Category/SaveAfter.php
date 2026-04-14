<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Category;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\MessageQueue\PublisherInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta as ResolvedMetaIndexer;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Psr\Log\LoggerInterface;

class SaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly PublisherInterface $publisher,
        private readonly SeoScorerInterface $scorer,
        private readonly MetaCache $cache,
        private readonly SeoConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }

            $category = $observer->getEvent()->getCategory();
            if (!$category || !$category->getId()) {
                return;
            }
            $categoryId = (int) $category->getId();
            $storeId = (int) $category->getStoreId();
            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_CATEGORY, $categoryId);

            if ($this->config->isMviewEnabled($storeId)) {
                $indexer = $this->indexerRegistry->get(ResolvedMetaIndexer::INDEXER_ID);
                if (!$indexer->isScheduled()) {
                    $indexer->reindexRow($categoryId);
                }
            }

            // Honor the Async Indexing toggle (queue vs synchronous scoring).
            if ($this->config->isAsyncIndexing($storeId)) {
                $this->publisher->publish(
                    'panth_seo.score_entity',
                    json_encode([
                        'entity_type' => MetaResolverInterface::ENTITY_CATEGORY,
                        'entity_id'   => $categoryId,
                        'store_id'    => $storeId,
                    ]) ?: ''
                );
            } else {
                $this->scorer->score(
                    MetaResolverInterface::ENTITY_CATEGORY,
                    $categoryId,
                    $storeId
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO category save observer failed', ['error' => $e->getMessage()]);
        }
    }
}
