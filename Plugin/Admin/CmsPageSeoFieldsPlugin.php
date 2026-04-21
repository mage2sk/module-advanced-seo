<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Cms\Model\Page\DataProvider as CmsPageDataProvider;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;

/**
 * Adds meta_robots (select) and hreflang_identifier (text) fields to the
 * CMS page edit form.
 *
 * CMS pages do not use EAV, so both values are stored in the
 * `panth_seo_override` table keyed by entity_type = 'cms_page'.
 */
class CmsPageSeoFieldsPlugin
{
    private const OVERRIDE_TABLE = 'panth_seo_override';
    private const ENTITY_TYPE    = 'cms_page';

    public function __construct(
        private readonly MetaRobots $metaRobotsSource,
        private readonly ResourceConnection $resource,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Inject SEO fields into the CMS page form meta.
     *
     * @param CmsPageDataProvider   $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CmsPageDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $result['search_engine_optimisation'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'         => __('Search Engine Optimization'),
                        'componentType' => 'fieldset',
                        'collapsible'   => true,
                        'sortOrder'     => 40,
                    ],
                ],
            ],
            'children' => [
                'meta_robots' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'field',
                                'formElement'   => 'select',
                                'dataType'      => 'text',
                                'label'         => __('Meta Robots'),
                                'options'       => $this->metaRobotsSource->toOptionArray(),
                                'sortOrder'     => 10,
                                'dataScope'     => 'meta_robots',
                            ],
                        ],
                    ],
                ],
                'hreflang_identifier' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'field',
                                'formElement'   => 'input',
                                'dataType'      => 'text',
                                'label'         => __('Hreflang Identifier'),
                                'notice'        => __(
                                    'Use the same identifier across store views to link '
                                    . 'this CMS page for hreflang tag generation '
                                    . '(e.g. "about-us").'
                                ),
                                'sortOrder'     => 20,
                                'dataScope'     => 'hreflang_identifier',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }

    /**
     * Pre-fill meta_robots and hreflang_identifier from the override table.
     *
     * @param CmsPageDataProvider   $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetData(CmsPageDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

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
                $stores  = is_array($pageData['store_id']) ? $pageData['store_id'] : [$pageData['store_id']];
                $storeId = (int) reset($stores);
            }

            $override = $this->loadOverride($entityId, $storeId);
            if ($override === null) {
                continue;
            }

            if (!empty($override['robots'])) {
                $pageData['meta_robots'] = $override['robots'];
            }
            if (!empty($override['hreflang_identifier'])) {
                $pageData['hreflang_identifier'] = $override['hreflang_identifier'];
            }
        }

        return $result;
    }

    /**
     * Load an existing override row for the CMS page.
     *
     * @return array<string, mixed>|null
     */
    private function loadOverride(int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::OVERRIDE_TABLE);

        $select = $connection->select()
            ->from($table, ['robots', 'hreflang_identifier'])
            ->where('entity_type = ?', self::ENTITY_TYPE)
            ->where('entity_id = ?', $entityId)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('store_id DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row !== false ? $row : null;
    }
}
