<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;

/**
 * Adds meta_robots (select), custom_canonical_url (text), and
 * exclude_from_sitemap (toggle) fields to the product edit form's
 * "search-engine-optimization" fieldset.
 *
 * - meta_robots and in_xml_sitemap are EAV attributes, so Magento
 *   persists them automatically on save.
 * - custom_canonical_url is loaded from panth_seo_custom_canonical;
 *   saving is handled by {@see ProductSeoFieldsSavePlugin}.
 */
class ProductSeoFieldsPlugin
{
    private const CANONICAL_TABLE = 'panth_seo_custom_canonical';
    private const ENTITY_TYPE     = 'product';

    public function __construct(
        private readonly MetaRobots $metaRobotsSource,
        private readonly ResourceConnection $resource,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject SEO fields into the product form meta.
     *
     * @param ProductDataProvider   $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        // -- Meta Robots select --
        $result['search-engine-optimization']['children']['meta_robots'] = [
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

        // -- Custom Canonical URL text --
        $result['search-engine-optimization']['children']['custom_canonical_url'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'label'         => __('Custom Canonical URL'),
                        'notice'        => __('Leave empty to use auto-generated canonical'),
                        'sortOrder'     => 35,
                        'dataScope'     => 'custom_canonical_url',
                        'validation'    => [
                            'validate-url' => true,
                        ],
                    ],
                ],
            ],
        ];

        // -- OG Title --
        $result['search-engine-optimization']['children']['og_title'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'label'         => __('OG Title'),
                        'notice'        => __('Open Graph title for social sharing (Facebook, LinkedIn, etc.). Leave empty to use Meta Title.'),
                        'sortOrder'     => 50,
                        'dataScope'     => 'og_title',
                    ],
                ],
            ],
        ];

        // -- OG Description --
        $result['search-engine-optimization']['children']['og_description'] = [
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
        $result['search-engine-optimization']['children']['og_image'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement'   => 'input',
                        'dataType'      => 'text',
                        'label'         => __('OG Image URL'),
                        'notice'        => __('Open Graph image URL for social sharing. Leave empty to use product image.'),
                        'sortOrder'     => 58,
                        'dataScope'     => 'og_image',
                    ],
                ],
            ],
        ];

        // -- Exclude from Sitemap toggle --
        $result['search-engine-optimization']['children']['exclude_from_sitemap'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'dataType'      => 'boolean',
                        'formElement'   => 'checkbox',
                        'componentType' => 'field',
                        'label'         => __('Exclude from XML Sitemap'),
                        'notice'        => __('Exclude this product from XML sitemap'),
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

    /**
     * Pre-fill custom_canonical_url from the database.
     *
     * @param ProductDataProvider   $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetData(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        if (empty($result)) {
            return $result;
        }

        foreach ($result as $productId => &$productData) {
            if (!is_array($productData) || !isset($productData['product'])) {
                continue;
            }

            $entityId = (int) ($productData['product']['entity_id'] ?? $productId);
            $storeId  = (int) ($productData['product']['store_id'] ?? 0);

            $targetUrl = $this->loadCanonicalUrl(self::ENTITY_TYPE, $entityId, $storeId);
            if ($targetUrl !== null) {
                $productData['product']['custom_canonical_url'] = $targetUrl;
            }
        }

        return $result;
    }

    /**
     * Load the custom canonical URL from panth_seo_custom_canonical.
     */
    private function loadCanonicalUrl(string $entityType, int $entityId, int $storeId): ?string
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::CANONICAL_TABLE);

        $select = $connection->select()
            ->from($table, ['target_url'])
            ->where('source_entity_type = ?', $entityType)
            ->where('source_entity_id = ?', $entityId)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('store_id DESC')
            ->limit(1);

        $value = $connection->fetchOne($select);

        return $value !== false && $value !== '' ? (string) $value : null;
    }
}
