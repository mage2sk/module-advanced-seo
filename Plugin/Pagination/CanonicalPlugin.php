<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Pagination;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Paginated listing canonical policy. Runs around PageConfig::publicBuild so
 * it observes anything other blocks already set, then overrides the canonical
 * asset if the current request has `?p=N`.
 */
class CanonicalPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly SeoConfig $seoConfig,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Canonical for paginated pages is now handled by Block\Head\Canonical
     * (via ViewModel\Canonical) which is pagination-aware and outputs in
     * head.additional.  Adding it here via addRemotePageAsset would create
     * a duplicate <link rel="canonical"> tag.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePublicBuild(PageConfig $subject): array
    {
        return [];
    }

    /**
     * @return array{0:?string,1:int}
     */
    private function detectEntity(): array
    {
        $category = $this->registry->registry('current_category');
        if ($category !== null && $category->getId()) {
            return [MetaResolverInterface::ENTITY_CATEGORY, (int) $category->getId()];
        }
        $product = $this->registry->registry('current_product');
        if ($product !== null && $product->getId()) {
            return [MetaResolverInterface::ENTITY_PRODUCT, (int) $product->getId()];
        }
        return [null, 0];
    }
}
