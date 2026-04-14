<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Canonical;

use Magento\Cms\Model\Page\DataProvider as CmsPageDataProvider;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Adds a "SEO Canonical" fieldset with a custom_canonical_url text input to
 * the CMS page edit form and pre-fills it from the panth_seo_custom_canonical
 * table when an override already exists.
 */
class CmsPageFormPlugin
{
    private const TABLE = 'panth_seo_custom_canonical';
    private const ENTITY_TYPE = 'cms';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject the SEO Canonical fieldset into the CMS page form meta.
     *
     * @param CmsPageDataProvider        $subject
     * @param array<string, mixed>       $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CmsPageDataProvider $subject, array $result): array
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
     * @param CmsPageDataProvider    $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetData(CmsPageDataProvider $subject, array $result): array
    {
        if (empty($result)) {
            return $result;
        }

        foreach ($result as $pageId => &$pageData) {
            if (!is_array($pageData)) {
                continue;
            }
            $entityId = (int) ($pageData['page_id'] ?? $pageId);
            $storeId  = 0;
            if (isset($pageData['store_id'])) {
                $stores = is_array($pageData['store_id']) ? $pageData['store_id'] : [$pageData['store_id']];
                $storeId = (int) reset($stores);
            }

            $targetUrl = $this->loadTargetUrl(self::ENTITY_TYPE, $entityId, $storeId);
            if ($targetUrl !== null) {
                $pageData['custom_canonical_url'] = $targetUrl;
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
