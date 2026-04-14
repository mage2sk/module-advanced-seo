<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap\Contributor;

use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\SitemapContributorInterface;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;

/**
 * Sitemap contributor for landing pages.
 *
 * Emits URLs for CMS pages identified as landing pages with a higher priority
 * (0.8) than regular CMS pages (0.5). Landing pages included here are excluded
 * from the regular CmsPageContributor by identifier overlap; the sitemap
 * builder deduplicates URLs by `loc`, so even if both contributors emit the
 * same URL the higher priority wins.
 */
class LandingPageContributor implements SitemapContributorInterface
{
    private const PRIORITY   = 0.8;
    private const CHANGEFREQ = 'weekly';

    public function __construct(
        private readonly LandingPageDetector $detector,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getCode(): string
    {
        return 'landing_page';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
        $pages   = $this->detector->getLandingPages($storeId);

        foreach ($pages as $row) {
            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '' || $identifier === 'no-route') {
                continue;
            }

            $entry = [
                'loc'        => $baseUrl . ltrim($identifier, '/'),
                'changefreq' => self::CHANGEFREQ,
                'priority'   => self::PRIORITY,
            ];

            if (!empty($row['update_time'])) {
                try {
                    $entry['lastmod'] = (new \DateTimeImmutable((string) $row['update_time']))
                        ->format('Y-m-d\TH:i:sP');
                } catch (\Throwable) {
                    // Ignore malformed dates.
                }
            }

            yield $entry;
        }
    }
}
