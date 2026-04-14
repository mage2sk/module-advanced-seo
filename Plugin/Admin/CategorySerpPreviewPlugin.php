<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Injects a live Google SERP preview fieldset into the category edit form.
 *
 * Mirrors the product plugin but targets the category data provider and the
 * category form's "search_engine_optimization" fieldset (note the underscore
 * naming convention that Magento uses for category forms).
 */
class CategorySerpPreviewPlugin
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param CategoryDataProvider $subject
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');

        /*
         * Magento category form uses "search_engine_optimization" (underscores)
         * while the product form uses "search-engine-optimization" (hyphens).
         * We inject into whichever key exists, falling back to the underscore variant.
         */
        $seoGroupKey = 'search_engine_optimization';
        if (!isset($result[$seoGroupKey])) {
            $seoGroupKey = 'search-engine-optimization';
        }

        $result[$seoGroupKey]['children']['panth_seo_serp_preview'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'container',
                        'component' => 'Panth_AdvancedSEO/js/serp-preview-component',
                        'template' => 'Panth_AdvancedSEO/serp-preview',
                        'sortOrder' => 5,
                        'baseUrl' => $baseUrl,
                        'entityType' => 'category',
                        'titleMaxPx' => 580,
                        'titleMaxChars' => 60,
                        'descriptionMaxChars' => 160,
                        'descriptionMaxPx' => 920,
                    ],
                ],
            ],
        ];

        return $result;
    }
}
