<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Sitemap;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Adds an "Exclude from Sitemap" checkbox to the product edit form's
 * SEO fieldset. The value is persisted via the `in_xml_sitemap` EAV
 * attribute (boolean, default 1 = included).
 *
 * The checkbox label is inverted for UX clarity: checking the box sets
 * `in_xml_sitemap` to 0 (excluded).
 */
class ProductFormSitemapPlugin
{
    public function __construct(
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $result['search-engine-optimization']['children']['container_exclude_from_sitemap'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'formElement'   => 'container',
                        'componentType' => 'container',
                        'breakLine'     => false,
                        'label'         => '',
                        'required'      => false,
                        'sortOrder'     => 200,
                    ],
                ],
            ],
            'children' => [
                'exclude_from_sitemap' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'dataType'      => 'boolean',
                                'formElement'   => 'checkbox',
                                'componentType' => 'field',
                                'label'         => __('Exclude from XML Sitemap'),
                                'description'   => __('When checked, this product will not appear in the XML sitemap.'),
                                'prefer'        => 'toggle',
                                'valueMap'      => [
                                    'true'  => '0', // checked = exclude (in_xml_sitemap = 0)
                                    'false' => '1', // unchecked = include (in_xml_sitemap = 1)
                                ],
                                'default'       => '1',
                                'dataScope'     => 'in_xml_sitemap',
                                'sortOrder'     => 200,
                                'switcherConfig' => [
                                    'enabled' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }
}
