<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Manages meta-content embeddings in panth_seo_meta_embedding (BLOB column).
 *
 * We avoid external ML dependencies. Instead we build a deterministic hashed
 * bag-of-ngrams vector: tokens → 1024 dims via fnv1a32 modulo. For meta text
 * of a few hundred characters this yields cosine similarity values that are
 * stable and effective at catching near-duplicates.
 */
class EmbeddingIndex
{
    public const DIMS = 1024;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int,float>
     */
    public function vectorize(string $text): array
    {
        $vec = array_fill(0, self::DIMS, 0.0);
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''));
        if ($text === '') {
            return $vec;
        }
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // unigrams + bigrams
        $ngrams = $tokens;
        $n = count($tokens);
        for ($i = 0; $i < $n - 1; $i++) {
            $ngrams[] = $tokens[$i] . '_' . $tokens[$i + 1];
        }
        foreach ($ngrams as $t) {
            $h = hash('fnv1a32', $t);
            $idx = hexdec($h) % self::DIMS;
            $vec[$idx] += 1.0;
        }
        // L2 normalise
        $norm = 0.0;
        foreach ($vec as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm > 0) {
            foreach ($vec as $i => $v) {
                $vec[$i] = $v / $norm;
            }
        }
        return $vec;
    }

    /**
     * @param array<int,float> $vector
     */
    public function store(string $entityType, int $entityId, int $storeId, array $vector): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_meta_embedding');
        try {
            $connection->insertOnDuplicate(
                $table,
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'store_id' => $storeId,
                    'dims' => self::DIMS,
                    'vector' => $this->pack($vector),
                    'updated_at' => $this->dateTime->gmtDate(),
                ],
                ['dims', 'vector', 'updated_at']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO embedding store failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int,float>|null
     */
    public function load(string $entityType, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_meta_embedding');
        $select = $connection->select()
            ->from($table, ['vector', 'dims'])
            ->where('entity_type = ?', $entityType)
            ->where('entity_id = ?', $entityId)
            ->where('store_id = ?', $storeId)
            ->limit(1);
        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }
        return $this->unpack((string)$row['vector']);
    }

    /**
     * Find top-k most similar embeddings to $vector within same entity_type+store.
     *
     * @param array<int,float> $vector
     * @return array<int,array{entity_type:string,entity_id:int,store_id:int,similarity:float}>
     */
    public function findSimilar(
        string $entityType,
        int $storeId,
        array $vector,
        int $excludeId = 0,
        int $limit = 10
    ): array {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_meta_embedding');
        $select = $connection->select()
            ->from($table, ['entity_type', 'entity_id', 'store_id', 'vector'])
            ->where('entity_type = ?', $entityType)
            ->where('store_id = ?', $storeId);
        if ($excludeId > 0) {
            $select->where('entity_id <> ?', $excludeId);
        }
        // Hard cap to avoid loading millions of rows; duplicate scan cron handles full sweeps.
        $select->limit(2000);

        $results = [];
        foreach ($connection->fetchAll($select) as $row) {
            $other = $this->unpack((string)$row['vector']);
            $sim = $this->cosine($vector, $other);
            $results[] = [
                'entity_type' => (string)$row['entity_type'],
                'entity_id' => (int)$row['entity_id'],
                'store_id' => (int)$row['store_id'],
                'similarity' => $sim,
            ];
        }

        usort($results, static fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, $limit);
    }

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    public function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * @param array<int,float> $vector
     */
    private function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return array<int,float>
     */
    private function unpack(string $blob): array
    {
        if ($blob === '') {
            return [];
        }
        $values = unpack('g*', $blob);
        return $values === false ? [] : array_values($values);
    }
}
