<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily score recompute for the most-stale entities.
 * Recomputes up to 500 products + 100 categories per run.
 */
class ScoreRecompute
{
    private const PRODUCT_BATCH = 500;
    private const CATEGORY_BATCH = 100;

    public function __construct(
        private readonly SeoScorerInterface $scorer,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $scoreTable = $this->resource->getTableName('panth_seo_score');

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $this->recomputeFor($connection, $scoreTable, 'product', $storeId, self::PRODUCT_BATCH, 'catalog_product_entity');
            $this->recomputeFor($connection, $scoreTable, 'category', $storeId, self::CATEGORY_BATCH, 'catalog_category_entity');
        }
    }

    private function recomputeFor(
        $connection,
        string $scoreTable,
        string $entityType,
        int $storeId,
        int $limit,
        string $entityTable
    ): void {
        try {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from(['e' => $this->resource->getTableName($entityTable)], ['entity_id'])
                    ->joinLeft(
                        ['s' => $scoreTable],
                        sprintf(
                            's.entity_id = e.entity_id AND s.entity_type = %s AND s.store_id = %d',
                            $connection->quote($entityType),
                            $storeId
                        ),
                        []
                    )
                    ->order('s.computed_at ASC')
                    ->limit($limit)
            );
            foreach ($ids as $id) {
                try {
                    $this->scorer->score($entityType, (int)$id, $storeId);
                } catch (\Throwable $e) {
                    $this->logger->warning('Panth SEO score recompute entity failed', [
                        'type' => $entityType, 'id' => $id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO ScoreRecompute: ' . $e->getMessage());
        }
    }
}
