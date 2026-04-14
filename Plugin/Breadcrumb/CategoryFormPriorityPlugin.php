<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Breadcrumb;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Injects the `breadcrumbs_priority` field into the category admin form's
 * Search Engine Optimization fieldset.
 *
 * The field renders as a numeric input and includes a tooltip that explains
 * how the priority system works.
 */
class CategoryFormPriorityPlugin
{
    public function __construct(
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param  array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $result['search_engine_optimization']['children']['container_breadcrumbs_priority'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'formElement'   => 'container',
                        'componentType' => 'container',
                        'breakLine'     => false,
                        'label'         => '',
                        'required'      => false,
                        'sortOrder'     => 70,
                    ],
                ],
            ],
            'children' => [
                'breadcrumbs_priority' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'dataType'      => 'number',
                                'formElement'   => 'input',
                                'componentType' => 'field',
                                'label'         => __('Breadcrumb Priority'),
                                'dataScope'     => 'breadcrumbs_priority',
                                'sortOrder'     => 70,
                                'validation'    => [
                                    'validate-digits' => true,
                                ],
                                'tooltip'       => [
                                    'description' => __(
                                        'Assign a numeric weight to influence which category path is '
                                        . 'shown in product breadcrumbs. When a product belongs to '
                                        . 'multiple categories, the path whose categories have the '
                                        . 'highest combined priority is selected. A value of 0 means '
                                        . 'no preference. Higher values take precedence.'
                                    ),
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
