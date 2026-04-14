<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Product;

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

            $product = $observer->getEvent()->getProduct();
            if (!$product || !$product->getId()) {
                return;
            }
            $productId = (int) $product->getId();
            $storeId = (int) $product->getStoreId();

            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_PRODUCT, $productId);

            // Honor the MView toggle: only queue changelog rows when enabled.
            if ($this->config->isMviewEnabled($storeId)) {
                $indexer = $this->indexerRegistry->get(ResolvedMetaIndexer::INDEXER_ID);
                if ($indexer->isScheduled()) {
                    // mview auto-captures via db changelog; nothing further needed.
                } else {
                    $indexer->reindexRow($productId);
                }
            }

            // Honor the Async Indexing toggle. When enabled, publish to the
            // message queue for non-blocking processing. When disabled, score
            // synchronously so SEO data is up-to-date immediately on save.
            if ($this->config->isAsyncIndexing($storeId)) {
                $this->publisher->publish(
                    'panth_seo.score_entity',
                    json_encode([
                        'entity_type' => MetaResolverInterface::ENTITY_PRODUCT,
                        'entity_id'   => $productId,
                        'store_id'    => $storeId,
                    ]) ?: ''
                );
            } else {
                $this->scorer->score(
                    MetaResolverInterface::ENTITY_PRODUCT,
                    $productId,
                    $storeId
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO product save observer failed', ['error' => $e->getMessage()]);
        }
    }
}
