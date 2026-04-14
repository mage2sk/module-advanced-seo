<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Response;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Sets Last-Modified and ETag HTTP headers on product and category pages
 * using the entity's `updated_at` timestamp. This enables efficient browser
 * caching and CDN revalidation (conditional GET with If-Modified-Since /
 * If-None-Match).
 *
 * When the client sends a conditional request (If-Modified-Since or
 * If-None-Match) that matches the current entity state, the plugin emits
 * a 304 Not Modified response with an empty body.
 *
 * ETag comparison uses hash_equals() for timing-safe equality.
 *
 * RFC 7231 format: "Thu, 01 Jan 2026 00:00:00 GMT"
 */
class LastModifiedHeaderPlugin
{
    public const XML_ENABLED = 'panth_seo/advanced/last_modified_header';

    public function __construct(
        private readonly Registry $registry,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig,
        private readonly State $appState,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function beforeSendResponse(HttpResponse $subject): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Skip if Last-Modified header is already set by another module
        if ($subject->getHeader('Last-Modified')) {
            return;
        }

        $updatedAt = null;
        $entityId = null;

        /** @var ProductInterface|null $product */
        $product = $this->registry->registry('current_product');
        if ($product !== null) {
            $updatedAt = $this->extractUpdatedAt($product);
            $entityId = 'product-' . $product->getId();
        }

        if ($updatedAt === null) {
            /** @var CategoryInterface|null $category */
            $category = $this->registry->registry('current_category');
            if ($category !== null) {
                $updatedAt = $this->extractUpdatedAt($category);
                $entityId = 'category-' . $category->getId();
            }
        }

        if ($updatedAt === null || $entityId === null) {
            return;
        }

        $timestamp = strtotime($updatedAt);
        if ($timestamp === false) {
            return;
        }

        // Last-Modified in RFC 7231 format
        $lastModified = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
        $subject->setHeader('Last-Modified', $lastModified, true);

        // ETag for efficient revalidation: sha256 of entity_id + updated_at
        $etag = '"' . hash('sha256', $entityId . '|' . $updatedAt) . '"';
        $subject->setHeader('ETag', $etag, true);

        // Conditional GET: honor If-None-Match and If-Modified-Since.
        if ($this->isNotModified($timestamp, $etag)) {
            $subject->setStatusHeader(304, null, 'Not Modified');
            // A 304 response must not contain a message body.
            $subject->clearBody();
        }
    }

    /**
     * Evaluate RFC 7232 conditional request headers.
     *
     * Precedence per RFC 7232 section 6:
     *   1. If-None-Match is present -> compare ETags (timing-safe).
     *   2. Otherwise, If-Modified-Since -> compare timestamps (>= wins).
     */
    private function isNotModified(int $timestamp, string $etag): bool
    {
        $ifNoneMatch = (string) ($this->request->getHeader('If-None-Match') ?: '');
        if ($ifNoneMatch !== '') {
            // Clients may send multiple tags, comma-separated. Any timing-safe
            // match returns 304.
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                // Strip weak-validator prefix.
                if (str_starts_with($candidate, 'W/')) {
                    $candidate = substr($candidate, 2);
                }
                if ($candidate !== '' && hash_equals($etag, $candidate)) {
                    return true;
                }
            }
            // Explicit If-None-Match that did not match means the resource
            // is considered modified; skip If-Modified-Since per RFC.
            return false;
        }

        $ifModifiedSince = (string) ($this->request->getHeader('If-Modified-Since') ?: '');
        if ($ifModifiedSince === '') {
            return false;
        }

        $since = strtotime($ifModifiedSince);
        if ($since === false) {
            return false;
        }

        // Not modified when the client's cached copy is at least as new as
        // the entity's updated_at.
        return $timestamp <= $since;
    }

    /**
     * Extract updated_at from a product or category entity.
     */
    private function extractUpdatedAt(ProductInterface|CategoryInterface $entity): ?string
    {
        if (method_exists($entity, 'getUpdatedAt')) {
            $value = $entity->getUpdatedAt();
            return ($value !== null && $value !== '') ? (string) $value : null;
        }
        return null;
    }

    private function isEnabled(): bool
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                self::XML_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
    }
}
