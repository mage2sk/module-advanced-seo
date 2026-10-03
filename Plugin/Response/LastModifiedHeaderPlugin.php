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

    public function beforeSendResponse(HttpResponse $subject): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($subject->getHeader('Last-Modified')) {
            return;
        }

        $updatedAt = null;
        $entityId = null;

        $product = $this->registry->registry('current_product');
        if ($product !== null) {
            $updatedAt = $this->extractUpdatedAt($product);
            $entityId = 'product-' . $product->getId();
        }

        if ($updatedAt === null) {
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

        $lastModified = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
        $subject->setHeader('Last-Modified', $lastModified, true);

        $etag = '"' . hash('sha256', $entityId . '|' . $updatedAt) . '"';
        $subject->setHeader('ETag', $etag, true);

        if ($this->isNotModified($timestamp, $etag)) {
            $subject->setStatusHeader(304, null, 'Not Modified');

            $subject->clearBody();
        }
    }

    private function isNotModified(int $timestamp, string $etag): bool
    {
        $ifNoneMatch = (string) ($this->request->getHeader('If-None-Match') ?: '');
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);

                if (str_starts_with($candidate, 'W/')) {
                    $candidate = substr($candidate, 2);
                }
                if ($candidate !== '' && hash_equals($etag, $candidate)) {
                    return true;
                }
            }

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

        return $timestamp <= $since;
    }

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
