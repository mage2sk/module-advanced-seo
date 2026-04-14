<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Frontend ViewModel exposing the resolved meta for the current page.
 * Works in both Luma and Hyva because it only consumes Registry + ViewModel
 * contracts — no jQuery, no RequireJS, no x-magento-init.
 */
class Meta implements ArgumentInterface
{
    private ?ResolvedMetaInterface $cached = null;

    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $config
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolveCurrent(): ?ResolvedMetaInterface
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        [$type, $id] = $this->detectEntity();
        if ($type === null) {
            return null;
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->cached = $this->metaResolver->resolve($type, $id, $storeId);
        } catch (\Throwable) {
            return null;
        }
        return $this->cached;
    }

    public function getTitle(): string
    {
        return (string) ($this->resolveCurrent()?->getMetaTitle() ?? '');
    }

    public function getDescription(): string
    {
        return (string) ($this->resolveCurrent()?->getMetaDescription() ?? '');
    }

    public function getKeywords(): string
    {
        return (string) ($this->resolveCurrent()?->getMetaKeywords() ?? '');
    }

    public function getRobots(): string
    {
        return (string) ($this->resolveCurrent()?->getRobots() ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    public function getHreflang(): array
    {
        return $this->resolveCurrent()?->getHreflangPayload() ?? [];
    }

    /**
     * @return array{0:?string,1:int}
     */
    private function detectEntity(): array
    {
        $product = $this->registry->registry('current_product');
        if ($product !== null && $product->getId()) {
            return [MetaResolverInterface::ENTITY_PRODUCT, (int) $product->getId()];
        }
        $category = $this->registry->registry('current_category');
        if ($category !== null && $category->getId()) {
            return [MetaResolverInterface::ENTITY_CATEGORY, (int) $category->getId()];
        }
        $cmsPage = $this->registry->registry('cms_page');
        if ($cmsPage !== null && $cmsPage->getId()) {
            return [MetaResolverInterface::ENTITY_CMS, (int) $cmsPage->getId()];
        }
        // Fall back to a request-derived id if someone set it explicitly.
        $id = (int) $this->request->getParam('id', 0);
        if ($id > 0) {
            return [MetaResolverInterface::ENTITY_OTHER, $id];
        }
        return [null, 0];
    }
}
