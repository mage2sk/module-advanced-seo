<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cross-domain canonical resolver.
 *
 * When the `panth_seo/canonical/cross_domain_store` config points to another
 * store, this resolver replaces the domain portion of the canonical URL with
 * the base URL of that target store, producing a cross-domain canonical tag.
 */
class CrossDomainResolver
{
    private const XML_CROSS_DOMAIN_STORE = 'panth_seo/canonical/cross_domain_store';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Replace the domain of $canonicalUrl with the cross-domain store's base
     * URL when configured.  Returns the original URL unchanged when no
     * cross-domain store is set.
     */
    public function resolve(string $canonicalUrl, int $storeId): string
    {
        if ($canonicalUrl === '') {
            return '';
        }

        $crossDomainStoreId = $this->getCrossDomainStoreId($storeId);
        if ($crossDomainStoreId === null) {
            return $canonicalUrl;
        }

        try {
            $targetBaseUrl = $this->getStoreBaseUrl($crossDomainStoreId);
            if ($targetBaseUrl === '') {
                return $canonicalUrl;
            }

            return $this->replaceDomain($canonicalUrl, $targetBaseUrl);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO cross-domain canonical resolution failed', [
                'canonical_url'        => $canonicalUrl,
                'cross_domain_store'   => $crossDomainStoreId,
                'error'                => $e->getMessage(),
            ]);
            return $canonicalUrl;
        }
    }

    /**
     * Read the cross-domain store ID from config; returns null when unset or
     * pointing at the same store.
     */
    private function getCrossDomainStoreId(int $storeId): ?int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_CROSS_DOMAIN_STORE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        $targetStoreId = (int) $value;

        // No point rewriting to the same store.
        if ($targetStoreId === $storeId) {
            return null;
        }

        return $targetStoreId;
    }

    /**
     * Get the base web URL for a given store.
     */
    private function getStoreBaseUrl(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Replace scheme + host (+ port) of $url with those from $targetBaseUrl.
     */
    private function replaceDomain(string $url, string $targetBaseUrl): string
    {
        $sourceParts = parse_url($url);
        $targetParts = parse_url($targetBaseUrl);

        if ($sourceParts === false || $targetParts === false) {
            return $url;
        }

        if (!isset($sourceParts['host'], $targetParts['host'])) {
            return $url;
        }

        $scheme = $targetParts['scheme'] ?? 'https';
        $host   = $targetParts['host'];
        $port   = isset($targetParts['port']) ? ':' . $targetParts['port'] : '';

        $path     = $sourceParts['path'] ?? '/';
        $query    = isset($sourceParts['query']) ? '?' . $sourceParts['query'] : '';
        $fragment = isset($sourceParts['fragment']) ? '#' . $sourceParts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }
}
