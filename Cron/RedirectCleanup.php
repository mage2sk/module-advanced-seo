<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Periodic redirect cleanup:
 *  1. Deletes redirects whose `finish_at` is in the past (expired schedule).
 *  2. Deletes redirects older than `expiry_days` that have never been hit
 *     (hit_count = 0), preventing stale never-used rules from accumulating.
 *
 * Configured via `panth_seo/auto_redirect/expiry_days` (default 365).
 */
class RedirectCleanup
{
    private const TABLE = 'panth_seo_redirect';
    private const DEFAULT_EXPIRY_DAYS = 365;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        if (!$connection->isTableExists($table)) {
            return;
        }

        $now = $this->dateTime->gmtDate();
        $expiryDays = $this->getExpiryDays();
        $cutoff = $this->dateTime->gmtDate(
            null,
            strtotime(sprintf('-%d days', $expiryDays))
        );

        $totalCleaned = 0;

        try {
            // 1. Remove redirects whose finish_at is in the past
            $expiredCount = $connection->delete($table, [
                'finish_at IS NOT NULL',
                'finish_at < ?' => $now,
            ]);
            $totalCleaned += $expiredCount;

            // 2. Remove never-used AUTO-GENERATED redirects older than expiry_days.
            // Admin-curated redirects (is_auto_generated = 0) are ALWAYS preserved,
            // even if unused, because an admin explicitly created them.
            $staleCount = $connection->delete($table, [
                'hit_count = 0',
                'is_auto_generated = ?' => 1,
                'created_at < ?' => $cutoff,
            ]);
            $totalCleaned += $staleCount;

            if ($totalCleaned > 0) {
                $this->logger->info(
                    sprintf(
                        '[PanthSEO] Redirect cleanup: removed %d expired, %d stale (unused > %d days). Total: %d.',
                        $expiredCount,
                        $staleCount,
                        $expiryDays,
                        $totalCleaned
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PanthSEO] Redirect cleanup failed: ' . $e->getMessage());
        }
    }

    private function getExpiryDays(): int
    {
        $value = $this->config->getValue('panth_seo/auto_redirect/expiry_days');
        $days = $value !== null ? (int) $value : self::DEFAULT_EXPIRY_DAYS;

        return $days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS;
    }
}
