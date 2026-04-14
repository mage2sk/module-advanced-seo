<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Clusters 404 paths by Levenshtein similarity and proposes targets drawn
 * from the url_rewrite table. Writes suggestions back into
 * panth_seo_404_log.suggested_target (column added dynamically if missing).
 */
class SuggestionEngine
{
    private const SIMILARITY_THRESHOLD = 72; // 0..100
    private const MAX_URL_REWRITE_SAMPLE = 20000;
    private const BATCH = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(?int $storeId = null, int $limit = 5000): int
    {
        $this->ensureSuggestionColumn();

        $conn   = $this->resource->getConnection();
        $logTbl = $this->resource->getTableName('panth_seo_404_log');
        $urlTbl = $this->resource->getTableName('url_rewrite');

        $select = $conn->select()
            ->from($logTbl, ['log_id', 'store_id', 'request_path'])
            ->where('hit_count >= ?', 1)
            ->where('(suggested_target IS NULL OR suggested_target = "")')
            ->order('hit_count DESC')
            ->limit($limit);
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        $rows = $conn->fetchAll($select);
        if (empty($rows)) {
            return 0;
        }

        // Cache url_rewrite samples per store to avoid re-query
        $rewritesByStore = [];
        $updated = 0;

        foreach ($rows as $row) {
            $sid = (int) $row['store_id'];
            if (!isset($rewritesByStore[$sid])) {
                $rewritesByStore[$sid] = $this->loadRewrites($sid);
            }
            $candidates = $rewritesByStore[$sid];
            if (empty($candidates)) {
                continue;
            }
            $target = $this->bestMatch((string) $row['request_path'], $candidates);
            if ($target === null) {
                continue;
            }
            try {
                $conn->update(
                    $logTbl,
                    [
                        'suggested_target' => substr($target['path'], 0, 1024),
                        'cluster_key'      => substr(hash('sha256', $target['path']), 0, 32),
                    ],
                    ['log_id = ?' => (int) $row['log_id']]
                );
                $updated++;
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] 404 suggest update failed: ' . $e->getMessage());
            }
        }

        return $updated;
    }

    /**
     * @param array<int,string> $candidates
     * @return array{path:string,score:int}|null
     */
    private function bestMatch(string $needle, array $candidates): ?array
    {
        $needle = $this->slug($needle);
        if ($needle === '') {
            return null;
        }
        $bestScore = -1;
        $bestPath  = null;
        $nLen = max(1, strlen($needle));
        foreach ($candidates as $path) {
            $slug = $this->slug($path);
            if ($slug === '') {
                continue;
            }
            $lenDiff = abs(strlen($slug) - $nLen);
            if ($lenDiff > max(8, (int) ($nLen * 0.6))) {
                continue;
            }
            $dist = levenshtein(substr($needle, 0, 180), substr($slug, 0, 180));
            $score = (int) round(max(0.0, 1 - ($dist / max($nLen, 1))) * 100);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath  = $path;
            }
        }
        if ($bestPath !== null && $bestScore >= self::SIMILARITY_THRESHOLD) {
            return ['path' => $bestPath, 'score' => $bestScore];
        }
        return null;
    }

    /**
     * @return array<int,string>
     */
    private function loadRewrites(int $storeId): array
    {
        $conn = $this->resource->getConnection();
        $tbl  = $this->resource->getTableName('url_rewrite');
        $select = $conn->select()
            ->from($tbl, ['request_path'])
            ->where('store_id IN (?)', [0, $storeId])
            ->where('redirect_type = 0')
            ->limit(self::MAX_URL_REWRITE_SAMPLE);
        $paths = [];
        $stmt = $conn->query($select);
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $p = (string) $r['request_path'];
            if ($p !== '') {
                $paths[] = '/' . ltrim($p, '/');
            }
        }
        return $paths;
    }

    private function slug(string $path): string
    {
        $p = strtolower(trim($path));
        $p = preg_replace('#\?.*$#', '', $p) ?? $p;
        $p = preg_replace('#[^a-z0-9]+#', '-', $p) ?? $p;
        return trim((string) $p, '-');
    }

    private function ensureSuggestionColumn(): void
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_404_log');
        try {
            if (!$conn->tableColumnExists($table, 'suggested_target')) {
                $conn->addColumn($table, 'suggested_target', [
                    'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'length'   => 1024,
                    'nullable' => true,
                    'comment'  => 'Suggested redirect target',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] ensure suggested_target column failed: ' . $e->getMessage());
        }
    }
}
