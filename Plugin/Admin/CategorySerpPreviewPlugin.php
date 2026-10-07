<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class CategorySerpPreviewPlugin
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/');

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
