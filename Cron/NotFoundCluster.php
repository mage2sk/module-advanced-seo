<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Clusters recent 404 URLs by normalised path and writes top offenders to
 * `panth_seo_404_cluster` so admins can create redirects in bulk.
 */
class NotFoundCluster
{
    private const LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $logTable = $this->resource->getTableName('panth_seo_404_log');
        $clusterTable = $this->resource->getTableName('panth_seo_404_cluster');
        if (!$connection->isTableExists($logTable) || !$connection->isTableExists($clusterTable)) {
            return;
        }

        try {
            $since = date('Y-m-d H:i:s', strtotime('-' . self::LOOKBACK_DAYS . ' days'));
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($logTable, ['request_path', 'store_id', 'hits' => 'hit_count'])
                    ->where('last_seen_at >= ?', $since)
                    ->order('hit_count DESC')
                    ->limit(500)
            );

            $clusters = [];
            foreach ($rows as $row) {
                $normalized = $this->normalize((string)$row['request_path']);
                $key = $normalized . '|' . (int)$row['store_id'];
                if (!isset($clusters[$key])) {
                    $clusters[$key] = [
                        'pattern' => $normalized,
                        'store_id' => (int)$row['store_id'],
                        'hits' => 0,
                        'sample' => (string)$row['request_path'],
                    ];
                }
                $clusters[$key]['hits'] += (int)$row['hits'];
            }

            $connection->delete($clusterTable);
            foreach ($clusters as $c) {
                $connection->insert($clusterTable, [
                    'pattern' => $c['pattern'],
                    'store_id' => $c['store_id'],
                    'hits' => $c['hits'],
                    'sample_url' => $c['sample'],
                    'created_at' => $this->dateTime->gmtDate(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO NotFoundCluster failed: ' . $e->getMessage());
        }
    }

    private function normalize(string $path): string
    {
        $path = strtolower(trim($path));
        $path = preg_replace('/\?.*$/', '', $path) ?? $path;
        $path = preg_replace('/[0-9]+/', '{n}', $path) ?? $path;
        return $path;
    }
}
