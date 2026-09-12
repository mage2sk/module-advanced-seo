<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Maintenance;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class OrphanCleaner
{
    public const BATCH_SIZE = 1000;

    private const MAX_BATCHES = 500;

    private const DERIVED = [
        ['table' => 'panth_seo_score', 'pk' => 'score_id', 'type' => 'entity_type', 'id' => 'entity_id'],
        ['table' => 'panth_seo_meta_embedding', 'pk' => 'embedding_id', 'type' => 'entity_type', 'id' => 'entity_id'],
        ['table' => 'panth_seo_resolved', 'pk' => 'resolved_id', 'type' => 'entity_type', 'id' => 'entity_id'],
        ['table' => 'panth_seo_related', 'pk' => 'related_id', 'type' => 'source_type', 'id' => 'source_id'],
        ['table' => 'panth_seo_related', 'pk' => 'related_id', 'type' => 'target_type', 'id' => 'target_id'],
    ];

    private const AUTHORED = [
        ['table' => 'panth_seo_override', 'pk' => 'override_id', 'type' => 'entity_type', 'id' => 'entity_id'],
        ['table' => 'panth_seo_custom_canonical', 'pk' => 'canonical_id', 'type' => 'source_entity_type', 'id' => 'source_entity_id'],
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EntityTableMap $entityTableMap,
        private readonly LoggerInterface $logger
    ) {
    }

    public function forEntity(string $entityType, int $entityId, bool $includeAuthored = false): array
    {
        if ($entityId <= 0) {
            return [];
        }

        $aliases = $this->entityTableMap->aliasesFor($entityType);
        $removed = [];

        foreach ($this->targets($includeAuthored) as $target) {
            $table = $this->resource->getTableName($target['table']);
            if (!$this->tableExists($table)) {
                continue;
            }

            try {
                $count = $this->resource->getConnection()->delete($table, [
                    $target['type'] . ' IN (?)' => $aliases,
                    $target['id'] . ' = ?'      => $entityId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO orphan cleanup failed', [
                    'table' => $target['table'],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($count > 0) {
                $removed[$target['table']] = ($removed[$target['table']] ?? 0) + $count;
            }
        }

        return $removed;
    }

    public function count(bool $includeAuthored = false): array
    {
        return $this->walk($includeAuthored, false);
    }

    public function sweep(bool $includeAuthored = false): array
    {
        return $this->walk($includeAuthored, true);
    }

    private function walk(bool $includeAuthored, bool $delete): array
    {
        $connection = $this->resource->getConnection();
        $totals = [];

        foreach ($this->targets($includeAuthored) as $target) {
            $table = $this->resource->getTableName($target['table']);
            if (!$this->tableExists($table)) {
                continue;
            }

            foreach ($this->entityTableMap->sweepTypes() as $entityType) {
                $totals[$target['table']] = $totals[$target['table']] ?? 0;

                if (!$delete) {
                    $countSelect = $this->entityTableMap->orphanCountSelect(
                        $table,
                        $target['type'],
                        $target['id'],
                        $entityType
                    );
                    if ($countSelect === null) {
                        continue;
                    }

                    try {
                        $totals[$target['table']] += (int) $connection->fetchOne($countSelect);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Panth SEO orphan scan failed', [
                            'table' => $target['table'],
                            'entity_type' => $entityType,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    continue;
                }

                for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
                    $select = $this->entityTableMap->orphanIdsSelect(
                        $table,
                        $target['pk'],
                        $target['type'],
                        $target['id'],
                        $entityType,
                        self::BATCH_SIZE
                    );
                    if ($select === null) {
                        break;
                    }

                    try {
                        $ids = array_map('intval', $connection->fetchCol($select));
                    } catch (\Throwable $e) {
                        $this->logger->warning('Panth SEO orphan scan failed', [
                            'table' => $target['table'],
                            'entity_type' => $entityType,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    }

                    if ($ids === []) {
                        break;
                    }

                    $totals[$target['table']] += count($ids);

                    try {
                        $connection->delete($table, [$target['pk'] . ' IN (?)' => $ids]);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Panth SEO orphan delete failed', [
                            'table' => $target['table'],
                            'entity_type' => $entityType,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    }

                    if (count($ids) < self::BATCH_SIZE) {
                        break;
                    }
                }
            }
        }

        return array_filter($totals, static fn (int $count): bool => $count > 0);
    }

    private function targets(bool $includeAuthored): array
    {
        return $includeAuthored ? array_merge(self::DERIVED, self::AUTHORED) : self::DERIVED;
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->resource->getConnection()->isTableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
