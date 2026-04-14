<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Psr\Log\LoggerInterface;

/**
 * When a category moves its children's canonical URLs and the products inside
 * them all shift. We publish per-product score/reresolve jobs asynchronously —
 * never inline, because a move can touch thousands of rows.
 */
class MoveAfter implements ObserverInterface
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PublisherInterface $publisher,
        private readonly MetaCache $cache,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }

            $categoryId = (int) $observer->getEvent()->getCategoryId();
            if ($categoryId <= 0) {
                $category = $observer->getEvent()->getCategory();
                $categoryId = $category ? (int) $category->getId() : 0;
            }
            if ($categoryId <= 0) {
                return;
            }

            try {
                $category = $this->categoryRepository->get($categoryId);
            } catch (\Throwable) {
                return;
            }

            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_CATEGORY, $categoryId);

            $connection = $this->resource->getConnection();

            // All descendant categories.
            $pathPattern = rtrim((string) $category->getPath(), '/') . '/%';
            $descendantIds = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_category_entity'), ['entity_id'])
                    ->where('path LIKE ?', $pathPattern)
                    ->orWhere('entity_id = ?', $categoryId)
            );
            $descendantIds = array_map('intval', $descendantIds);
            foreach ($descendantIds as $id) {
                $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_CATEGORY, $id);
            }

            if ($descendantIds === []) {
                return;
            }

            // All products linked to any of those categories.
            $productIds = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_category_product'), ['product_id'])
                    ->where('category_id IN (?)', $descendantIds)
                    ->distinct()
            );
            $productIds = array_unique(array_map('intval', $productIds));

            foreach (array_chunk($productIds, self::BATCH_SIZE) as $chunk) {
                foreach ($chunk as $productId) {
                    $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_PRODUCT, $productId);
                    $this->publisher->publish(
                        'panth_seo.score_entity',
                        json_encode([
                            'entity_type' => MetaResolverInterface::ENTITY_PRODUCT,
                            'entity_id'   => $productId,
                            'store_id'    => 0,
                        ]) ?: ''
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO category move observer failed', ['error' => $e->getMessage()]);
        }
    }
}
