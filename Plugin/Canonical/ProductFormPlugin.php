<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Canonical;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Adds a "SEO Canonical" fieldset with a custom_canonical_url text input to
 * the product edit form and pre-fills it from the panth_seo_custom_canonical
 * table when an override already exists.
 */
class ProductFormPlugin
{
    private const TABLE = 'panth_seo_custom_canonical';
    private const ENTITY_TYPE = 'product';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject the SEO Canonical fieldset into the product form meta.
     *
     * @param ProductDataProvider          $subject
     * @param array<string, mixed>         $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $result['seo_canonical'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'         => __('SEO Canonical'),
                        'componentType' => 'fieldset',
                        'collapsible'   => true,
                        'sortOrder'     => 150,
                    ],
                ],
            ],
            'children' => [
                'custom_canonical_url' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'field',
                                'formElement'   => 'input',
                                'dataType'      => 'text',
                                'label'         => __('Custom Canonical URL'),
                                'notice'        => __('Leave empty to use the auto-generated canonical. Enter a full URL to override.'),
                                'sortOrder'     => 10,
                                'dataScope'     => 'custom_canonical_url',
                                'validation'    => [
                                    'validate-url' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }

    /**
     * Pre-fill the custom canonical URL field from the database.
     *
     * @param ProductDataProvider    $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetData(ProductDataProvider $subject, array $result): array
    {
        if (empty($result)) {
            return $result;
        }

        foreach ($result as $productId => &$productData) {
            if (!is_array($productData) || !isset($productData['product'])) {
                continue;
            }
            $entityId = (int) ($productData['product']['entity_id'] ?? $productId);
            $storeId  = (int) ($productData['product']['store_id'] ?? 0);

            $targetUrl = $this->loadTargetUrl(self::ENTITY_TYPE, $entityId, $storeId);
            if ($targetUrl !== null) {
                $productData['product']['custom_canonical_url'] = $targetUrl;
            }
        }

        return $result;
    }

    private function loadTargetUrl(string $entityType, int $entityId, int $storeId): ?string
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

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
