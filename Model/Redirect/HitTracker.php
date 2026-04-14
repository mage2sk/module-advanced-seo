<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Atomic hit-counter service for redirect rules.
 *
 * Increments `hit_count` and stamps `last_hit_at` in a single UPDATE
 * using a SQL expression, so no SELECT-then-UPDATE race condition exists
 * under concurrent traffic.
 *
 * This service is the canonical entry point for hit tracking. The existing
 * {@see \Panth\AdvancedSEO\Model\Redirect\Matcher::recordHit()} performs
 * the same operation inline; callers that need a standalone service
 * (e.g., queue consumers, CLI commands) should use this class.
 */
class HitTracker
{
    private const TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record a single hit against the given redirect rule.
     *
     * The update is atomic: `hit_count = hit_count + 1` prevents lost
     * increments under concurrency, and `last_hit_at = NOW()` uses the
     * DB server clock for consistency.
     */
    public function recordHit(int $redirectId): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);

            $connection->query(
                sprintf(
                    'UPDATE %s SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE redirect_id = ?',
                    $connection->quoteIdentifier($table)
                ),
                [$redirectId]
            );
        } catch (\Throwable $e) {
            // Never let hit tracking break the redirect itself.
            $this->logger->warning(
                '[PanthSEO] HitTracker::recordHit failed: ' . $e->getMessage(),
                ['redirect_id' => $redirectId]
            );
        }
    }
}
