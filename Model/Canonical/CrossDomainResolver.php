<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CrossDomainResolver
{
    private const XML_CROSS_DOMAIN_STORE = 'panth_seo/canonical/cross_domain_store';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

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

        if ($targetStoreId === $storeId) {
            return null;
        }

        return $targetStoreId;
    }

    private function getStoreBaseUrl(int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
        } catch (\Throwable) {
            return '';
        }
    }

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
