<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;

/**
 * BFS loop detection over the redirect graph (literal edges only — regex
 * redirects are inherently dynamic and excluded from static cycle analysis).
 */
class Loop
{
    private const MAX_DEPTH = 25;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Returns the chain of paths if a loop/chain-too-long is detected,
     * or an empty array if the edge (from -> to) is safe.
     *
     * @return array<int,string>
     */
    public function detect(string $from, string $to, int $storeId, ?int $ignoreRedirectId = null): array
    {
        $from = $this->normalize($from);
        $to   = $this->normalize($to);

        if ($from === $to) {
            return [$from, $to];
        }

        $graph = $this->loadLiteralGraph($storeId, $ignoreRedirectId);
        // Simulate adding the proposed edge
        $graph[$from] = $to;

        $visited = [];
        $chain   = [];
        $current = $from;
        $depth   = 0;

        while (isset($graph[$current])) {
            if (isset($visited[$current])) {
                $chain[] = $current;
                return $chain;
            }
            $visited[$current] = true;
            $chain[] = $current;
            $current = $graph[$current];
            if (++$depth > self::MAX_DEPTH) {
                $chain[] = $current;
                return $chain;
            }
        }
        return [];
    }

    /**
     * @return array<string,string>
     */
    private function loadLiteralGraph(int $storeId, ?int $ignoreRedirectId): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $select = $conn->select()
            ->from($table, ['redirect_id', 'pattern', 'target'])
            ->where('is_active = ?', 1)
            ->where('match_type = ?', 'literal')
            ->where('store_id IN (?)', [0, $storeId]);
        if ($ignoreRedirectId !== null) {
            $select->where('redirect_id <> ?', $ignoreRedirectId);
        }

        $graph = [];
        foreach ($conn->fetchAll($select) as $row) {
            $graph[$this->normalize((string) $row['pattern'])] = $this->normalize((string) $row['target']);
        }
        return $graph;
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        if (preg_match('#^https?://#i', $path)) {
            $parsed = parse_url($path);
            $path = ($parsed['path'] ?? '/');
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
