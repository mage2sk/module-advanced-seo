<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Logs 404s into panth_seo_404_log with hit counter & last_seen timestamp.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE on (store_id, path_hash).
 */
class NotFoundLogger
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(string $requestPath, int $storeId, ?string $referer = null): void
    {
        $path = $this->normalize($requestPath);
        if ($path === '' || $path === '/') {
            return;
        }
        $hash = hash('sha256', $storeId . '|' . $path);

        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_404_log');
            $now   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $refererValue = $referer !== null ? substr($referer, 0, 1024) : '';
            $pathValue = substr($path, 0, 1024);

            $conn->query(
                "INSERT INTO {$table} (store_id, request_path, path_hash, referer, hit_count, first_seen_at, last_seen_at) "
                . "VALUES (?, ?, ?, ?, 1, ?, ?) "
                . "ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = VALUES(last_seen_at), "
                . "referer = IF(VALUES(referer) != '', VALUES(referer), referer)",
                [$storeId, $pathValue, $hash, $refererValue, $now, $now]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] 404 log failed: ' . $e->getMessage());
        }
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
