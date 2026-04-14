<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\InternalLinking;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Suggests related entities using:
 *   1. Embedding cosine similarity from panth_seo_meta_embedding
 *   2. Blended with PageRank weight from InternalLinking\PageRank
 *
 * Writes top-N into cache keyed per (store, type, id). Optionally persists
 * to panth_seo_related if the AddLinkGraphTable patch has run.
 */
class Suggester
{
    private const CACHE_TTL = 7200;
    private const BLEND_SIM = 0.7;
    private const BLEND_PR  = 0.3;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly PageRank $pageRank,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int,array{type:string,id:int,score:float}>
     */
    public function suggest(string $entityType, int $entityId, int $storeId, int $limit = 5): array
    {
        $cacheKey = sprintf('panth_seo_related_%d_%s_%d_%d', $storeId, $entityType, $entityId, $limit);
        $cached   = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable) {
                // rebuild
            }
        }

        $persisted = $this->loadPersisted($entityType, $entityId, $storeId, $limit);
        if (!empty($persisted)) {
            return $persisted;
        }

        $source = $this->loadEmbedding($entityType, $entityId, $storeId, 'description')
            ?? $this->loadEmbedding($entityType, $entityId, $storeId, 'title');
        $results = [];

        if ($source !== null) {
            $candidates = $this->loadCandidateEmbeddings($entityType, $storeId, $entityId);
            try {
                $ranks = $this->pageRank->compute($storeId);
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] pagerank compute failed: ' . $e->getMessage());
                $ranks = [];
            }
            $maxRank = !empty($ranks) ? max($ranks) : 1.0;
            $maxRank = $maxRank > 0 ? $maxRank : 1.0;

            $scored = [];
            foreach ($candidates as $cand) {
                $sim = $this->cosine($source, $cand['vector']);
                if ($sim <= 0) {
                    continue;
                }
                $node = Graph::node($entityType, (int) $cand['entity_id']);
                $pr   = (float) ($ranks[$node] ?? 0.0) / $maxRank;
                $score = (self::BLEND_SIM * $sim) + (self::BLEND_PR * $pr);
                $scored[] = [
                    'type'  => $entityType,
                    'id'    => (int) $cand['entity_id'],
                    'score' => $score,
                ];
            }
            usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
            $results = array_slice($scored, 0, $limit);
        }

        try {
            $this->cache->save(
                $this->serializer->serialize($results),
                $cacheKey,
                [Graph::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable) {
            // best effort
        }

        return $results;
    }

    /**
     * @return array<int,array{type:string,id:int,score:float}>
     */
    private function loadPersisted(string $entityType, int $entityId, int $storeId, int $limit): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_related');
        try {
            if (!$conn->isTableExists($table)) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
        $select = $conn->select()
            ->from($table, ['target_type', 'target_id', 'score'])
            ->where('source_type = ?', $entityType)
            ->where('source_id = ?', $entityId)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('score DESC')
            ->limit($limit);

        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            $out[] = [
                'type'  => (string) $row['target_type'],
                'id'    => (int) $row['target_id'],
                'score' => (float) $row['score'],
            ];
        }
        return $out;
    }

    /**
     * @return float[]|null
     */
    private function loadEmbedding(string $entityType, int $entityId, int $storeId, string $field): ?array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_meta_embedding');
        $select = $conn->select()
            ->from($table, ['vector', 'dimensions'])
            ->where('entity_type = ?', $entityType)
            ->where('entity_id = ?', $entityId)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('field = ?', $field)
            ->limit(1);
        $row = $conn->fetchRow($select);
        if (!$row || empty($row['vector'])) {
            return null;
        }
        return $this->unpackVector((string) $row['vector'], (int) $row['dimensions']);
    }

    /**
     * @return array<int,array{entity_id:int,vector:float[]}>
     */
    private function loadCandidateEmbeddings(string $entityType, int $storeId, int $excludeId): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_meta_embedding');
        $select = $conn->select()
            ->from($table, ['entity_id', 'vector', 'dimensions'])
            ->where('entity_type = ?', $entityType)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('field = ?', 'description')
            ->where('entity_id <> ?', $excludeId)
            ->limit(2000);

        $rows = [];
        $stmt = $conn->query($select);
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $vec = $this->unpackVector((string) $r['vector'], (int) $r['dimensions']);
            if ($vec === null) {
                continue;
            }
            $rows[] = ['entity_id' => (int) $r['entity_id'], 'vector' => $vec];
        }
        return $rows;
    }

    /**
     * @return float[]|null
     */
    private function unpackVector(string $blob, int $dims): ?array
    {
        if ($blob === '' || $dims <= 0) {
            return null;
        }
        $expected = $dims * 4;
        if (strlen($blob) < $expected) {
            return null;
        }
        $unpacked = unpack('f' . $dims, substr($blob, 0, $expected));
        if ($unpacked === false) {
            return null;
        }
        return array_values($unpacked);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $na  += $av * $av;
            $nb  += $bv * $bv;
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
