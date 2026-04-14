<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Panth\AdvancedSEO\Model\Sitemap\Builder;
use Psr\Log\LoggerInterface;

/**
 * Nightly sitemap rebuild.
 *
 * Iterates all active sitemap profiles with cron_enabled = 1 and generates
 * sitemaps for each. After generation, updates the profile row with stats.
 *
 * Falls back to Magento's built-in sitemap collection when no profiles exist.
 */
class SitemapRebuild
{
    public function __construct(
        private readonly SitemapCollectionFactory $collectionFactory,
        private readonly Builder $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            // Profile-based generation: iterate all cron-enabled active profiles
            $profiles = $this->builder->loadActiveProfiles(null, true);

            if (!empty($profiles)) {
                $this->logger->info(sprintf(
                    '[PanthSEO] Sitemap cron: found %d active cron-enabled profile(s)',
                    count($profiles)
                ));

                foreach ($profiles as $profile) {
                    $profileId = (int) ($profile['profile_id'] ?? 0);
                    $profileName = $profile['name'] ?? 'unnamed';

                    try {
                        $stats = $this->builder->buildFromProfile($profile);

                        // Update profile row with generation stats
                        $this->builder->updateProfileStats($profileId, $stats);

                        $this->logger->info(sprintf(
                            '[PanthSEO] Sitemap cron: profile "%s" (id %d) completed — %d URLs, %d files, %.2fs',
                            $profileName,
                            $profileId,
                            $stats['url_count'],
                            $stats['file_count'],
                            $stats['generation_time']
                        ));
                    } catch (\Throwable $e) {
                        $this->logger->warning(sprintf(
                            '[PanthSEO] Sitemap cron: profile "%s" (id %d) failed: %s',
                            $profileName,
                            $profileId,
                            $e->getMessage()
                        ));
                    }
                }

                return;
            }

            // Fallback: Magento's built-in sitemap collection
            $collection = $this->collectionFactory->create();
            foreach ($collection as $sitemap) {
                try {
                    $sitemap->generateXml();
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Panth SEO sitemap rebuild failed for ' . $sitemap->getId() . ': ' . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO sitemap cron error: ' . $e->getMessage());
        }
    }
}
