<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Manager as ModuleManager;

class Dashboard extends Template
{
    protected $_template = 'Panth_AdvancedSEO::dashboard.phtml';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly ModuleManager $moduleManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCatalogOverview(): array
    {
        $conn = $this->getConnection();
        $result = [
            'total_products'           => 0,
            'products_missing_title'   => 0,
            'products_missing_desc'    => 0,
            'total_categories'         => 0,
            'categories_missing_title' => 0,
            'categories_missing_desc'  => 0,
            'total_cms'                => 0,
        ];

        try {
            $productTable = $this->resource->getTableName('catalog_product_entity');
            if ($conn->isTableExists($productTable)) {
                $result['total_products'] = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM {$productTable}"
                );
            }

            $metaTitleAttrId = $this->getAttributeId('catalog_product', 'meta_title');
            if ($metaTitleAttrId && $result['total_products'] > 0) {
                $varcharTable = $this->resource->getTableName('catalog_product_entity_varchar');
                if ($conn->isTableExists($varcharTable)) {
                    $withTitle = (int) $conn->fetchOne(
                        "SELECT COUNT(DISTINCT entity_id) FROM {$varcharTable}"
                        . " WHERE attribute_id = ? AND value IS NOT NULL AND value != ''",
                        [$metaTitleAttrId]
                    );
                    $result['products_missing_title'] = max(0, $result['total_products'] - $withTitle);
                }
            } else {
                $result['products_missing_title'] = $result['total_products'];
            }

            $metaDescAttrId = $this->getAttributeId('catalog_product', 'meta_description');
            if ($metaDescAttrId && $result['total_products'] > 0) {
                $backendType = $this->getAttributeBackendType('catalog_product', 'meta_description');
                $descTable = $this->resource->getTableName(
                    'catalog_product_entity_' . ($backendType ?: 'varchar')
                );
                if ($conn->isTableExists($descTable)) {
                    $withDesc = (int) $conn->fetchOne(
                        "SELECT COUNT(DISTINCT entity_id) FROM {$descTable}"
                        . " WHERE attribute_id = ? AND value IS NOT NULL AND value != ''",
                        [$metaDescAttrId]
                    );
                    $result['products_missing_desc'] = max(0, $result['total_products'] - $withDesc);
                }
            } else {
                $result['products_missing_desc'] = $result['total_products'];
            }

            $catTable = $this->resource->getTableName('catalog_category_entity');
            if ($conn->isTableExists($catTable)) {
                $result['total_categories'] = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM {$catTable} WHERE level > 1"
                );
            }

            $catTitleAttrId = $this->getAttributeId('catalog_category', 'meta_title');
            if ($catTitleAttrId && $result['total_categories'] > 0) {
                $catVarcharTable = $this->resource->getTableName('catalog_category_entity_varchar');
                if ($conn->isTableExists($catVarcharTable)) {
                    $catEntityTable = $this->resource->getTableName('catalog_category_entity');
                    $withCatTitle = (int) $conn->fetchOne(
                        "SELECT COUNT(DISTINCT v.entity_id) FROM {$catVarcharTable} v"
                        . " INNER JOIN {$catEntityTable} e ON v.entity_id = e.entity_id"
                        . " WHERE v.attribute_id = ? AND v.value IS NOT NULL AND v.value != '' AND e.level > 1",
                        [$catTitleAttrId]
                    );
                    $result['categories_missing_title'] = max(0, $result['total_categories'] - $withCatTitle);
                }
            } else {
                $result['categories_missing_title'] = $result['total_categories'];
            }

            $catDescAttrId = $this->getAttributeId('catalog_category', 'meta_description');
            if ($catDescAttrId && $result['total_categories'] > 0) {
                $catBackendType = $this->getAttributeBackendType('catalog_category', 'meta_description');
                $catDescTable = $this->resource->getTableName(
                    'catalog_category_entity_' . ($catBackendType ?: 'varchar')
                );
                $catEntityTable = $this->resource->getTableName('catalog_category_entity');
                if ($conn->isTableExists($catDescTable)) {
                    $withCatDesc = (int) $conn->fetchOne(
                        "SELECT COUNT(DISTINCT v.entity_id) FROM {$catDescTable} v"
                        . " INNER JOIN {$catEntityTable} e ON v.entity_id = e.entity_id"
                        . " WHERE v.attribute_id = ? AND v.value IS NOT NULL AND v.value != '' AND e.level > 1",
                        [$catDescAttrId]
                    );
                    $result['categories_missing_desc'] = max(0, $result['total_categories'] - $withCatDesc);
                }
            } else {
                $result['categories_missing_desc'] = $result['total_categories'];
            }

            $cmsTable = $this->resource->getTableName('cms_page');
            if ($conn->isTableExists($cmsTable)) {
                $result['total_cms'] = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM {$cmsTable} WHERE is_active = 1"
                );
            }
        } catch (\Throwable $e) {
        }

        return $result;
    }

    public function getModuleStats(): array
    {
        $conn = $this->getConnection();
        $stats = [
            'templates'         => 0,
            'rules'             => 0,
            'filter_rewrites'   => 0,
            'custom_canonicals' => 0,
            'hreflang_groups'   => 0,
        ];

        $tables = [
            'templates'         => ['panth_seo_template',         null],
            'rules'             => ['panth_seo_rule',             'is_active = 1'],
            'filter_rewrites'   => ['panth_seo_filter_rewrite',   null],
            'custom_canonicals' => ['panth_seo_custom_canonical',  null],
            'hreflang_groups'   => ['panth_seo_hreflang_group',   null],
        ];

        try {
            foreach ($tables as $key => [$table, $where]) {
                $tableName = $this->resource->getTableName($table);
                if ($conn->isTableExists($tableName)) {
                    $sql = "SELECT COUNT(*) FROM {$tableName}";
                    if ($where) {
                        $sql .= " WHERE {$where}";
                    }
                    $stats[$key] = (int) $conn->fetchOne($sql);
                }
            }
        } catch (\Throwable $e) {
        }

        return $stats;
    }

    public function getQuickActions(): array
    {
        $actions = [
            [
                'label' => 'Manage Templates',
                'url'   => $this->getUrl('panth_seo/template/index'),
                'icon'  => 'file-text',
            ],
            [
                'label' => 'Bulk Meta Editor',
                'url'   => $this->getUrl('panth_seo/bulkeditor/index'),
                'icon'  => 'edit',
            ],
            [
                'label' => 'SEO Audit',
                'url'   => $this->getUrl('panth_seo/audit/index'),
                'icon'  => 'search',
            ],
            [
                'label' => 'Manage Feeds',
                'url'   => $this->getUrl('panth_seo/feed/index'),
                'icon'  => 'rss',
            ],
            [
                'label' => 'SEO Rules',
                'url'   => $this->getUrl('panth_seo/rule/index'),
                'icon'  => 'gears',
            ],
            [
                'label' => 'Missing Meta Report',
                'url'   => $this->getUrl('panth_seo/report/missingMeta'),
                'icon'  => 'warning',
            ],
            [
                'label' => 'Configuration',
                'url'   => $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_seo']),
                'icon'  => 'cog',
            ],
        ];

        foreach ($this->getSiblingModuleActions() as $action) {
            $actions[] = $action;
        }

        return $actions;
    }

    public function getSiblingModuleActions(): array
    {
        $candidates = [
            ['module' => 'Panth_FilterSeo', 'label' => 'Filter URL Rewrites', 'route' => 'panth_filterseo/filterrewrite/index', 'icon' => 'filter'],
            ['module' => 'Panth_XmlSitemap', 'label' => 'Manage Sitemaps', 'route' => 'panth_xml_sitemap/profile/index', 'icon' => 'sitemap'],
            ['module' => 'Panth_Hreflang', 'label' => 'Hreflang Groups', 'route' => 'panth_hreflang/hreflang/index', 'icon' => 'globe'],
        ];

        $actions = [];
        foreach ($candidates as $candidate) {
            if (!$this->isModuleAvailable($candidate['module'])) {
                continue;
            }
            $actions[] = [
                'label' => $candidate['label'],
                'url'   => $this->getUrl($candidate['route']),
                'icon'  => $candidate['icon'],
            ];
        }

        return $actions;
    }

    public function isModuleAvailable(string $moduleName): bool
    {
        try {
            return $this->moduleManager->isEnabled($moduleName);
        } catch (\Throwable) {
            return false;
        }
    }

    public function pct(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
    }

    public function healthColor(float $pct): string
    {
        if ($pct > 20.0) {
            return 'panth-card--red';
        }
        if ($pct >= 5.0) {
            return 'panth-card--yellow';
        }

        return 'panth-card--green';
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    public function getSitemapFeedStats(): array
    {
        $stats = [
            'sitemap_profiles' => 0,
            'sitemap_last_generated' => '',
            'sitemap_total_urls' => 0,
            'feed_profiles' => 0,
            'feed_last_generated' => '',
            'feed_total_products' => 0,
        ];

        try {
            $conn = $this->getConnection();
            $sitemapTable = $this->resource->getTableName('panth_seo_sitemap_profile');
            if ($conn->isTableExists($sitemapTable)) {
                $stats['sitemap_profiles'] = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$sitemapTable} WHERE is_active = 1");
                $stats['sitemap_last_generated'] = (string) $conn->fetchOne("SELECT MAX(last_generated_at) FROM {$sitemapTable}");
                $stats['sitemap_total_urls'] = (int) $conn->fetchOne("SELECT COALESCE(SUM(url_count), 0) FROM {$sitemapTable}");
            }
        } catch (\Throwable) {
        }

        try {
            $conn = $this->getConnection();
            $feedTable = $this->resource->getTableName('panth_seo_feed_profile');
            if ($conn->isTableExists($feedTable)) {
                $stats['feed_profiles'] = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$feedTable} WHERE is_active = 1");
                $stats['feed_last_generated'] = (string) $conn->fetchOne("SELECT MAX(last_generated_at) FROM {$feedTable}");
                $stats['feed_total_products'] = (int) $conn->fetchOne("SELECT COALESCE(SUM(product_count), 0) FROM {$feedTable}");
            }
        } catch (\Throwable) {
        }

        return $stats;
    }

    private function getAttributeBackendType(string $entityType, string $attributeCode): ?string
    {
        try {
            $conn = $this->getConnection();
            $table = $this->resource->getTableName('eav_attribute');
            $entityTypeTable = $this->resource->getTableName('eav_entity_type');

            $type = $conn->fetchOne(
                "SELECT a.backend_type FROM {$table} a"
                . " JOIN {$entityTypeTable} t ON a.entity_type_id = t.entity_type_id"
                . " WHERE t.entity_type_code = ? AND a.attribute_code = ?",
                [$entityType, $attributeCode]
            );

            return $type ? (string) $type : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getAttributeId(string $entityType, string $attributeCode): ?int
    {
        try {
            $conn           = $this->getConnection();
            $table          = $this->resource->getTableName('eav_attribute');
            $entityTypeTable = $this->resource->getTableName('eav_entity_type');

            $id = $conn->fetchOne(
                "SELECT a.attribute_id FROM {$table} a"
                . " JOIN {$entityTypeTable} t ON a.entity_type_id = t.entity_type_id"
                . " WHERE t.entity_type_code = ? AND a.attribute_code = ?",
                [$entityType, $attributeCode]
            );

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
