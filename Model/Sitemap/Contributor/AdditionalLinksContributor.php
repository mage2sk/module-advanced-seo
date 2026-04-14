<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap\Contributor;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Api\SitemapContributorInterface;

/**
 * Contributes manually entered URLs (one per line in admin textarea) to the
 * sitemap. Useful for landing pages, external microsites, or any URL that is
 * not automatically discoverable by the standard contributors.
 */
class AdditionalLinksContributor implements SitemapContributorInterface
{
    private const CONFIG_LINKS      = 'panth_seo/sitemap/additional_links';
    private const CONFIG_CHANGEFREQ = 'panth_seo/sitemap/additional_links_changefreq';
    private const CONFIG_PRIORITY   = 'panth_seo/sitemap/additional_links_priority';

    private const DEFAULT_CHANGEFREQ = 'monthly';
    private const DEFAULT_PRIORITY   = 0.5;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getCode(): string
    {
        return 'additional_links';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::CONFIG_LINKS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (trim($raw) === '') {
            return;
        }

        $changefreq = $this->resolveChangefreq($storeId);
        $priority   = $this->resolvePriority($storeId);

        $lines = preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($lines === false) {
            return;
        }

        $seen = [];

        foreach ($lines as $line) {
            $url = trim($line);

            if ($url === '' || !$this->isValidUrl($url)) {
                continue;
            }

            // Deduplicate within the same generation run
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            yield [
                'loc'        => $url,
                'changefreq' => $changefreq,
                'priority'   => $priority,
            ];
        }
    }

    private function resolveChangefreq(int $storeId): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::CONFIG_CHANGEFREQ,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $allowed = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

        return in_array($value, $allowed, true) ? $value : self::DEFAULT_CHANGEFREQ;
    }

    private function resolvePriority(int $storeId): float
    {
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PRIORITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '') {
            return self::DEFAULT_PRIORITY;
        }

        $float = (float) $value;

        // Sitemap priority must be between 0.0 and 1.0 inclusive
        if ($float < 0.0 || $float > 1.0) {
            return self::DEFAULT_PRIORITY;
        }

        return round($float, 1);
    }

    /**
     * Validates that the string is a well-formed absolute HTTP(S) URL.
     */
    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}
