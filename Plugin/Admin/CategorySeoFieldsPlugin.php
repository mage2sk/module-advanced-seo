<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;

/**
 * Adds meta_robots (select) and exclude_from_sitemap (toggle) fields to the
 * category edit form's "search_engine_optimization" fieldset.
 *
 * Both fields map to EAV attributes (`meta_robots` and `in_xml_sitemap`),
 * so Magento persists them automatically on category save.
 */
class CategorySeoFieldsPlugin
{
    public function __construct(
        private readonly MetaRobots $metaRobotsSource,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject SEO fields into the category form meta.
     *
     * @param CategoryDataProvider  $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        /*
         * Magento category form uses "search_engine_optimization" (underscores)
         * while the product form uses "search-engine-optimization" (hyphens).
         */
        $seoGroupKey = 'search_engine_optimization';
        if (!isset($result[$seoGroupKey])) {
            $seoGroupKey = 'search-engine-optimization';
        }

        // -- Meta Robots select --
        $result[$seoGroupKey]['children']['meta_robots'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'select',
                        'dataType'      => 'text',
                        'label'         => __('Meta Robots'),
                        'options'       => $this->metaRobotsSource->toOptionArray(),
                        'sortOrder'     => 30,
                        'dataScope'     => 'meta_robots',
                    ],
                ],
            ],
        ];

        // -- OG Title --
        $result[$seoGroupKey]['children']['og_title'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'label'         => __('OG Title'),
                        'notice'        => __('Open Graph title for social sharing. Leave empty to use Meta Title.'),
                        'sortOrder'     => 50,
                        'dataScope'     => 'og_title',
                    ],
                ],
            ],
        ];

        // -- OG Description --
        $result[$seoGroupKey]['children']['og_description'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'textarea',
                        'dataType'      => 'text',
                        'label'         => __('OG Description'),
                        'notice'        => __('Open Graph description for social sharing. Leave empty to use Meta Description.'),
                        'sortOrder'     => 55,
                        'dataScope'     => 'og_description',
                    ],
                ],
            ],
        ];

        // -- OG Image URL --
        $result[$seoGroupKey]['children']['og_image'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'label'         => __('OG Image URL'),
                        'notice'        => __('Open Graph image URL for social sharing. Leave empty to use category image.'),
                        'sortOrder'     => 58,
                        'dataScope'     => 'og_image',
                    ],
                ],
            ],
        ];

        // -- Exclude from Sitemap toggle --
        $result[$seoGroupKey]['children']['exclude_from_sitemap'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'dataType'      => 'boolean',
                        'formElement'   => 'checkbox',
                        'componentType' => 'field',
                        'label'         => __('Exclude from XML Sitemap'),
                        'notice'        => __('Exclude this category from XML sitemap'),
                        'prefer'        => 'toggle',
                        'valueMap'      => [
                            'true'  => '0',
                            'false' => '1',
                        ],
                        'default'       => '1',
                        'dataScope'     => 'in_xml_sitemap',
                        'sortOrder'     => 40,
                        'switcherConfig' => [
                            'enabled' => false,
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }
}
