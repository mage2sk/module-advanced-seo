<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Queue;

use Panth\AdvancedSEO\Api\SitemapBuilderInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer for topic `panth_seo.sitemap_shard`.
 *
 * Message payload: JSON { "store_id": int }
 *
 * Triggers a full sitemap rebuild for the given store. This allows the
 * sitemap generation to be offloaded to a queue worker instead of running
 * synchronously during cron or admin actions.
 */
class SitemapShardConsumer
{
    public function __construct(
        private readonly SitemapBuilderInterface $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        $decoded = json_decode($message, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Panth SEO sitemap shard: invalid message', ['message' => $message]);
            return;
        }

        $storeId = (int) ($decoded['store_id'] ?? 0);
        if ($storeId <= 0) {
            $this->logger->warning('Panth SEO sitemap shard: missing or invalid store_id', ['message' => $message]);
            return;
        }

        try {
            $files = $this->builder->build($storeId);
            $count = is_countable($files) ? count($files) : iterator_count($files);
            $this->logger->info('Panth SEO sitemap shard: built ' . $count . ' files for store ' . $storeId);
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO sitemap shard consumer failed: ' . $e->getMessage(), [
                'store_id' => $storeId,
            ]);
        }
    }
}
