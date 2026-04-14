<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Injects a live Google SERP preview fieldset into the product edit form.
 *
 * The preview sits inside the "search-engine-optimization" group so the admin
 * sees exactly what the title/description/URL will look like in search results
 * while editing SEO fields.
 */
class SerpPreviewPlugin
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ProductDataProvider $subject
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');

        $result['search-engine-optimization']['children']['panth_seo_serp_preview'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'container',
                        'component' => 'Panth_AdvancedSEO/js/serp-preview-component',
                        'template' => 'Panth_AdvancedSEO/serp-preview',
                        'sortOrder' => 5,
                        'baseUrl' => $baseUrl,
                        'entityType' => 'product',
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
