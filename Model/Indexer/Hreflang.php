<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Api\HreflangResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Indexer for panth_seo_hreflang: rebuilds the `hreflang_payload` JSON column
 * on `panth_seo_resolved` for every member referenced by a hreflang group.
 */
class Hreflang implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'panth_seo_hreflang';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly HreflangResolverInterface $hreflangResolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function executeFull(): void
    {
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $select = $connection->select()
            ->from($memberTable, ['store_id', 'entity_type', 'entity_id'])
            ->distinct(true);

        $rows = $connection->fetchAll($select);
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['store_id'] . ':' . $row['entity_type'];
            $buckets[$key][] = (int) $row['entity_id'];
        }

        foreach ($buckets as $key => $ids) {
            [$storeId, $type] = explode(':', $key, 2);
            foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
                $this->updateBatch((int) $storeId, $type, $chunk);
            }
        }
    }

    /**
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        if ($ids === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $intIds = array_map('intval', $ids);
        $memberIn = $connection->quoteInto('member_id IN (?)', $intIds);
        $groupIn  = $connection->quoteInto('group_id IN (?)', $intIds);
        $select = $connection->select()
            ->from($memberTable, ['store_id', 'entity_type', 'entity_id'])
            ->where("{$memberIn} OR {$groupIn}")
            ->distinct(true);

        $rows = $connection->fetchAll($select);
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['store_id'] . ':' . $row['entity_type'];
            $buckets[$key][] = (int) $row['entity_id'];
        }
        foreach ($buckets as $key => $entityIds) {
            [$storeId, $type] = explode(':', $key, 2);
            $this->updateBatch((int) $storeId, $type, $entityIds);
        }
    }

    /**
     * @param int[] $ids
     */
    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    /**
     * @param int $id
     */
    public function executeRow($id): void
    {
        $this->execute([(int) $id]);
    }

    /**
     * @param int[] $entityIds
     */
    private function updateBatch(int $storeId, string $entityType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');

        foreach ($entityIds as $entityId) {
            try {
                $alternates = $this->hreflangResolver->getAlternates($entityType, $entityId, $storeId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '[panth_seo_hreflang] getAlternates failed store=%d type=%s id=%d: %s',
                        $storeId,
                        $entityType,
                        $entityId,
                        $e->getMessage()
                    )
                );
                continue;
            }

            $payload = $alternates === [] ? null : $this->json->serialize($alternates);

            $connection->update(
                $resolvedTable,
                ['hreflang_payload' => $payload],
                [
                    'store_id = ?'    => $storeId,
                    'entity_type = ?' => $entityType,
                    'entity_id = ?'   => $entityId,
                ]
            );
        }
    }
}
