<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Crosslink;

use Magento\Catalog\Helper\Output;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Crosslink\ReplacementService;

/**
 * After-plugin on Catalog\Helper\Output to inject crosslink anchors
 * into product and category description attributes.
 */
class CatalogOutputPlugin
{
    /** Attributes eligible for crosslink injection */
    private const PRODUCT_ATTRIBUTES = ['description', 'short_description'];
    private const CATEGORY_ATTRIBUTES = ['description'];

    public function __construct(
        private readonly ReplacementService $replacementService,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject crosslinks into product description/short_description.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProductAttribute(
        Output $subject,
        ?string $result,
        \Magento\Catalog\Model\Product $product,
        $attributeHtml,
        $attributeName
    ): ?string {
        if (!in_array($attributeName, self::PRODUCT_ATTRIBUTES, true)) {
            return $result;
        }

        if ($result === null || $result === '') {
            return $result;
        }

        if (!$this->isEnabled()) {
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        return $this->replacementService->processContent($result, 'product', $storeId);
    }

    /**
     * Inject crosslinks into category description.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCategoryAttribute(
        Output $subject,
        ?string $result,
        \Magento\Catalog\Model\Category $category,
        $attributeHtml,
        $attributeName
    ): ?string {
        if (!in_array($attributeName, self::CATEGORY_ATTRIBUTES, true)) {
            return $result;
        }

        if ($result === null || $result === '') {
            return $result;
        }

        if (!$this->isEnabled()) {
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        return $this->replacementService->processContent($result, 'category', $storeId);
    }

    private function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                'panth_seo/crosslinks/crosslinks_enabled',
                ScopeInterface::SCOPE_STORE
            );
    }
}
