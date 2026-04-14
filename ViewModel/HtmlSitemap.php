<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Theme-agnostic HTML sitemap data source.
 *
 * Returns lightweight arrays for template rendering -- no block-specific
 * logic, no JavaScript. All section visibility and sort options are driven
 * by admin configuration under panth_seo/html_sitemap/*.
 */
class HtmlSitemap implements ArgumentInterface
{
    /** @var int Hard cap on the number of products returned. */
    private const PRODUCT_LIMIT = 1000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    // ------------------------------------------------------------------
    //  Config-driven accessors
    // ------------------------------------------------------------------

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMaxCategoryDepth(): int
    {
        return $this->config->getHtmlSitemapMaxCategoryDepth();
    }

    public function getProductSortOrder(): string
    {
        return $this->config->getHtmlSitemapProductSortOrder();
    }

    public function getProductUrlStructure(): string
    {
        return $this->config->getHtmlSitemapProductUrlStructure();
    }

    public function isShowStores(): bool
    {
        return $this->config->isHtmlSitemapShowStores();
    }

    public function isShowProducts(): bool
    {
        return $this->config->isHtmlSitemapShowProducts();
    }

    public function isShowCmsPages(): bool
    {
        return $this->config->isHtmlSitemapShowCmsPages();
    }

    public function isShowCategories(): bool
    {
        return $this->config->isHtmlSitemapShowCategories();
    }

    public function isShowCustomLinks(): bool
    {
        return $this->config->isHtmlSitemapShowCustomLinks();
    }

    public function isShowSearchField(): bool
    {
        return $this->config->isHtmlSitemapShowSearchField();
    }

    /**
     * Parse the custom_links textarea config.
     *
     * Each line follows the format:  URL | Label
     * If no pipe is present the URL is used as the label.
     *
     * @return array<int, array{url: string, label: string}>
     */
    public function getCustomLinks(): array
    {
        $raw = trim($this->config->getHtmlSitemapCustomLinks());
        if ($raw === '') {
            return [];
        }

        $links = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                [$url, $label] = array_map('trim', explode('|', $line, 2));
            } else {
                $url   = $line;
                $label = $line;
            }

            if ($url === '') {
                continue;
            }

            $links[] = [
                'url'   => $url,
                'label' => $label !== '' ? $label : $url,
            ];
        }

        return $links;
    }

    // ------------------------------------------------------------------
    //  Data loaders
    // ------------------------------------------------------------------

    /**
     * Build a nested category tree, optionally limited by depth and
     * respecting the `exclude_from_html_sitemap` attribute when present.
     *
     * @return array<int, array{id: int, name: string, url: string, level: int, children: array}>
     */
    public function getCategories(): array
    {
        try {
            $store     = $this->storeManager->getStore();
            $storeId   = (int) $store->getId();
            $rootCatId = (int) $store->getRootCategoryId();
            $baseUrl   = rtrim((string) $store->getBaseUrl(), '/') . '/';

            $conn       = $this->resource->getConnection();
            $catEntity  = $this->resource->getTableName('catalog_category_entity');
            $catVarchar = $this->resource->getTableName('catalog_category_entity_varchar');
            $catInt     = $this->resource->getTableName('catalog_category_entity_int');
            $eavAttr    = $this->resource->getTableName('eav_attribute');
            $urlTable   = $this->resource->getTableName('url_rewrite');

            $entityTypeId = $this->getCategoryEntityTypeId();

            // Resolve attribute IDs
            $nameAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'name')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            $isActiveAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'is_active')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            // Optional: exclude_from_html_sitemap attribute
            $excludeAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'exclude_from_html_sitemap')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            // Build main select
            $select = $conn->select()
                ->from(['e' => $catEntity], ['entity_id', 'parent_id', 'level', 'path'])
                ->joinLeft(
                    ['v' => $catVarchar],
                    'v.entity_id = e.entity_id AND v.attribute_id = ' . $nameAttrId
                    . ' AND v.store_id IN (0, ' . $storeId . ')',
                    ['name' => 'v.value']
                )
                ->where('e.path LIKE ?', '1/' . $rootCatId . '/%')
                ->order('e.level ASC')
                ->order('e.position ASC');

            // Depth limit
            $maxDepth = $this->getMaxCategoryDepth();
            if ($maxDepth > 0) {
                // Root category level = 1, its children = 2, so max absolute level
                // is root level + maxDepth.
                $rootLevel  = (int) $conn->fetchOne(
                    $conn->select()->from($catEntity, 'level')->where('entity_id = ?', $rootCatId)
                );
                $maxLevel = $rootLevel + $maxDepth;
                $select->where('e.level <= ?', $maxLevel);
            }

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return [];
            }

            $ids = array_map(static fn($r) => (int) $r['entity_id'], $rows);

            // Filter: is_active = 1
            $activeIds = $this->fetchIntAttributeSet($conn, $catInt, $isActiveAttrId, $storeId, $ids, 1);

            // Filter: exclude_from_html_sitemap = 1 (exclude those)
            $excludedIds = [];
            if ($excludeAttrId > 0) {
                $excludedIds = $this->fetchIntAttributeSet($conn, $catInt, $excludeAttrId, $storeId, $ids, 1);
            }

            // URL paths
            $pathMap = [];
            $sel = $conn->select()
                ->from($urlTable, ['entity_id', 'request_path'])
                ->where('entity_type = ?', 'category')
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_id IN (?)', $ids);
            foreach ($conn->fetchAll($sel) as $r) {
                $pathMap[(int) $r['entity_id']] = (string) $r['request_path'];
            }

            // Build indexed nodes
            $nodes = [];
            foreach ($rows as $r) {
                $id   = (int) $r['entity_id'];
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                // Skip inactive categories
                if (!in_array($id, $activeIds, true)) {
                    continue;
                }
                // Skip excluded categories
                if (in_array($id, $excludedIds, true)) {
                    continue;
                }

                $nodes[$id] = [
                    'id'       => $id,
                    'name'     => $name,
                    'url'      => isset($pathMap[$id]) ? $baseUrl . ltrim($pathMap[$id], '/') : '#',
                    'level'    => (int) $r['level'],
                    'parent'   => (int) $r['parent_id'],
                    'children' => [],
                ];
            }

            // Nest into tree
            $roots = [];
            foreach ($nodes as $id => &$node) {
                $pid = $node['parent'];
                if ($pid === $rootCatId || !isset($nodes[$pid])) {
                    $roots[] = &$node;
                } else {
                    $nodes[$pid]['children'][] = &$node;
                }
            }
            unset($node);

            return $roots;
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] html sitemap categories failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load visible products for the current store, sorted by config,
     * limited to {@see PRODUCT_LIMIT}.
     *
     * @return array<int, array{name: string, url: string, image: string, price: string}>
     */
    public function getProducts(): array
    {
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $mediaBaseUrl = $this->getProductMediaBaseUrl();
            $currencySymbol = $this->getCurrencySymbol();

            $conn       = $this->resource->getConnection();
            $prodEntity = $this->resource->getTableName('catalog_product_entity');
            $prodVarchar = $this->resource->getTableName('catalog_product_entity_varchar');
            $prodInt    = $this->resource->getTableName('catalog_product_entity_int');
            $prodDecimal = $this->resource->getTableName('catalog_product_entity_decimal');
            $eavAttr    = $this->resource->getTableName('eav_attribute');
            $urlTable   = $this->resource->getTableName('url_rewrite');
            $prodWebsite = $this->resource->getTableName('catalog_product_website');

            $entityTypeId = $this->getProductEntityTypeId();

            // Name attribute
            $nameAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'name')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            // Visibility attribute
            $visAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'visibility')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            // Status attribute
            $statusAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'status')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );

            $websiteId = (int) $store->getWebsiteId();

            // Build product query: enabled, visible in catalog/both, in website
            $select = $conn->select()
                ->from(['e' => $prodEntity], ['entity_id'])
                ->join(
                    ['pw' => $prodWebsite],
                    'pw.product_id = e.entity_id AND pw.website_id = ' . $websiteId,
                    []
                )
                ->joinLeft(
                    ['n' => $prodVarchar],
                    'n.entity_id = e.entity_id AND n.attribute_id = ' . $nameAttrId
                    . ' AND n.store_id IN (0, ' . $storeId . ')',
                    ['name' => 'n.value']
                )
                ->join(
                    ['vis' => $prodInt],
                    'vis.entity_id = e.entity_id AND vis.attribute_id = ' . $visAttrId
                    . ' AND vis.store_id IN (0, ' . $storeId . ')',
                    []
                )
                ->join(
                    ['st' => $prodInt],
                    'st.entity_id = e.entity_id AND st.attribute_id = ' . $statusAttrId
                    . ' AND st.store_id IN (0, ' . $storeId . ')',
                    []
                )
                // Visibility: 2 = Catalog, 4 = Catalog/Search
                ->where('vis.value IN (?)', [2, 4])
                // Status: 1 = Enabled
                ->where('st.value = ?', 1)
                ->group('e.entity_id')
                ->limit(self::PRODUCT_LIMIT);

            // Sort order
            $sortOrder = $this->getProductSortOrder();
            switch ($sortOrder) {
                case 'name_desc':
                    $select->order('n.value DESC');
                    break;
                case 'newest':
                    $select->order('e.created_at DESC');
                    break;
                case 'oldest':
                    $select->order('e.created_at ASC');
                    break;
                case 'price':
                    $priceAttrId = (int) $conn->fetchOne(
                        $conn->select()
                            ->from($eavAttr, 'attribute_id')
                            ->where('attribute_code = ?', 'price')
                            ->where('entity_type_id = ?', $entityTypeId)
                            ->limit(1)
                    );
                    if ($priceAttrId > 0) {
                        $select->joinLeft(
                            ['pr' => $prodDecimal],
                            'pr.entity_id = e.entity_id AND pr.attribute_id = ' . $priceAttrId
                            . ' AND pr.store_id IN (0, ' . $storeId . ')',
                            []
                        )->order('pr.value ASC');
                    } else {
                        $select->order('n.value ASC');
                    }
                    break;
                case 'position':
                    $select->order('e.entity_id ASC');
                    break;
                case 'name':
                default:
                    $select->order('n.value ASC');
                    break;
            }

            $rows = $conn->fetchAll($select);
            if (empty($rows)) {
                return [];
            }

            $ids = array_map(static fn($r) => (int) $r['entity_id'], $rows);
            $nameMap = [];
            foreach ($rows as $r) {
                $nameMap[(int) $r['entity_id']] = trim((string) ($r['name'] ?? ''));
            }

            // Fetch small_image values (single pass, not joined to avoid skewing main query)
            $imageMap = [];
            $smallImageAttrId = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'small_image')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );
            if ($smallImageAttrId > 0) {
                $imgSelect = $conn->select()
                    ->from($prodVarchar, ['entity_id', 'store_id', 'value'])
                    ->where('attribute_id = ?', $smallImageAttrId)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('entity_id IN (?)', $ids);
                // Prefer store-specific (higher store_id) value over admin default
                foreach ($conn->fetchAll($imgSelect) as $r) {
                    $eid = (int) $r['entity_id'];
                    $val = trim((string) ($r['value'] ?? ''));
                    if ($val === '' || $val === 'no_selection') {
                        continue;
                    }
                    if (!isset($imageMap[$eid]) || (int) $r['store_id'] > 0) {
                        $imageMap[$eid] = str_starts_with($val, '/') ? $val : '/' . $val;
                    }
                }
            }

            // Fetch prices for display
            $priceMap = [];
            $priceAttrIdForDisplay = (int) $conn->fetchOne(
                $conn->select()
                    ->from($eavAttr, 'attribute_id')
                    ->where('attribute_code = ?', 'price')
                    ->where('entity_type_id = ?', $entityTypeId)
                    ->limit(1)
            );
            if ($priceAttrIdForDisplay > 0) {
                $prSelect = $conn->select()
                    ->from($prodDecimal, ['entity_id', 'store_id', 'value'])
                    ->where('attribute_id = ?', $priceAttrIdForDisplay)
                    ->where('store_id IN (?)', [0, $storeId])
                    ->where('entity_id IN (?)', $ids);
                foreach ($conn->fetchAll($prSelect) as $r) {
                    $eid = (int) $r['entity_id'];
                    $val = $r['value'];
                    if ($val === null || $val === '') {
                        continue;
                    }
                    if (!isset($priceMap[$eid]) || (int) $r['store_id'] > 0) {
                        $priceMap[$eid] = (float) $val;
                    }
                }
            }

            // URL rewrites
            $urlStructure = $this->getProductUrlStructure();
            $pathMap = [];
            $urlSelect = $conn->select()
                ->from($urlTable, ['entity_id', 'request_path'])
                ->where('entity_type = ?', 'product')
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0)
                ->where('entity_id IN (?)', $ids);

            if ($urlStructure === 'short') {
                // Prefer short URLs (no category path): metadata IS NULL
                $urlSelect->where('metadata IS NULL');
            } else {
                // Prefer with-category URLs: metadata IS NOT NULL, fall back to short
                $urlSelect->order(new \Zend_Db_Expr('CASE WHEN metadata IS NOT NULL THEN 0 ELSE 1 END ASC'));
            }

            foreach ($conn->fetchAll($urlSelect) as $r) {
                $eid = (int) $r['entity_id'];
                // First match wins per entity
                if (!isset($pathMap[$eid])) {
                    $pathMap[$eid] = (string) $r['request_path'];
                }
            }

            // If "short" filter removed some, try fallback without the metadata filter
            if ($urlStructure === 'short') {
                $missingIds = array_diff($ids, array_keys($pathMap));
                if (!empty($missingIds)) {
                    $fallback = $conn->select()
                        ->from($urlTable, ['entity_id', 'request_path'])
                        ->where('entity_type = ?', 'product')
                        ->where('store_id = ?', $storeId)
                        ->where('redirect_type = ?', 0)
                        ->where('entity_id IN (?)', $missingIds);
                    foreach ($conn->fetchAll($fallback) as $r) {
                        $eid = (int) $r['entity_id'];
                        if (!isset($pathMap[$eid])) {
                            $pathMap[$eid] = (string) $r['request_path'];
                        }
                    }
                }
            }

            // Assemble output preserving query order
            $out = [];
            foreach ($ids as $id) {
                $name = $nameMap[$id] ?? '';
                if ($name === '' || !isset($pathMap[$id])) {
                    continue;
                }

                $image = '';
                if (isset($imageMap[$id]) && $mediaBaseUrl !== '') {
                    $image = $mediaBaseUrl . $imageMap[$id];
                }

                $price = '';
                if (isset($priceMap[$id]) && $priceMap[$id] > 0) {
                    $price = $currencySymbol . number_format($priceMap[$id], 2);
                }

                $out[] = [
                    'name'  => $name,
                    'url'   => $baseUrl . ltrim($pathMap[$id], '/'),
                    'image' => $image,
                    'price' => $price,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] html sitemap products failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load active CMS pages for the current store, excluding the
     * homepage identifier and no-route page.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function getCmsPages(): array
    {
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

            $conn   = $this->resource->getConnection();
            $page   = $this->resource->getTableName('cms_page');
            $pstore = $this->resource->getTableName('cms_page_store');

            // Resolve the homepage identifier for this store so we can exclude it
            $homeIdentifier = (string) $this->config->getValue(
                'web/default/cms_home_page'
            );

            // Admin-configured CMS page identifiers to exclude
            $excludedIdentifiers = $this->config->getHtmlSitemapExcludeCmsPages();

            $select = $conn->select()
                ->from(['p' => $page], ['identifier', 'title'])
                ->join(['ps' => $pstore], 'ps.page_id = p.page_id', [])
                ->where('p.is_active = ?', 1)
                ->where('ps.store_id IN (?)', [0, $storeId])
                ->group('p.page_id')
                ->order('p.title ASC');

            $out = [];
            foreach ($conn->fetchAll($select) as $r) {
                $ident = (string) $r['identifier'];
                if ($ident === '' || $ident === 'no-route' || $ident === $homeIdentifier) {
                    continue;
                }
                if ($excludedIdentifiers !== [] && in_array($ident, $excludedIdentifiers, true)) {
                    continue;
                }
                $out[] = [
                    'title' => (string) $r['title'],
                    'url'   => $baseUrl . ltrim($ident, '/'),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] html sitemap cms failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return all active stores. Useful for a store-switcher section.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public function getStores(): array
    {
        try {
            $stores = [];
            foreach ($this->storeManager->getStores() as $store) {
                if (!$store->isActive()) {
                    continue;
                }
                $stores[] = [
                    'name' => (string) $store->getName(),
                    'url'  => rtrim((string) $store->getBaseUrl(), '/') . '/',
                ];
            }

            return $stores;
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] html sitemap stores failed: ' . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------
    //  Counts + meta helpers for header/summary UI
    // ------------------------------------------------------------------

    /**
     * Count top-level categories plus descendants currently rendered.
     */
    public function getCategoryCount(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $count++;
            if (!empty($node['children']) && is_array($node['children'])) {
                $count += $this->getCategoryCount($node['children']);
            }
        }
        return $count;
    }

    /**
     * Return server-local formatted timestamp used in the footer.
     */
    public function getLastUpdatedTimestamp(): string
    {
        return date('Y-m-d H:i');
    }

    /**
     * Build the catalog media base URL for the current store's product images.
     */
    public function getProductMediaBaseUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $base  = rtrim(
                (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );
            return $base === '' ? '' : $base . '/catalog/product';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve the current store's currency symbol with safe fallbacks.
     */
    public function getCurrencySymbol(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $code  = (string) $store->getCurrentCurrencyCode();
            return match ($code) {
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'INR' => '₹',
                'JPY' => '¥',
                default => $code !== '' ? $code . ' ' : '',
            };
        } catch (\Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    //  Legacy alias — kept for backward compatibility
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{id: int, name: string, url: string, level: int, children: array}>
     * @deprecated Use getCategories() instead.
     */
    public function getCategoryTree(): array
    {
        return $this->getCategories();
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    private function getCategoryEntityTypeId(): int
    {
        $conn = $this->resource->getConnection();
        $tbl  = $this->resource->getTableName('eav_entity_type');
        return (int) $conn->fetchOne(
            $conn->select()->from($tbl, 'entity_type_id')->where('entity_type_code = ?', 'catalog_category')
        );
    }

    private function getProductEntityTypeId(): int
    {
        $conn = $this->resource->getConnection();
        $tbl  = $this->resource->getTableName('eav_entity_type');
        return (int) $conn->fetchOne(
            $conn->select()->from($tbl, 'entity_type_id')->where('entity_type_code = ?', 'catalog_product')
        );
    }

    /**
     * Return entity IDs that have a specific integer attribute value.
     *
     * @param  \Magento\Framework\DB\Adapter\AdapterInterface $conn
     * @param  string $table  The EAV int table name
     * @param  int    $attrId
     * @param  int    $storeId
     * @param  int[]  $entityIds
     * @param  int    $value
     * @return int[]
     */
    private function fetchIntAttributeSet(
        $conn,
        string $table,
        int $attrId,
        int $storeId,
        array $entityIds,
        int $value
    ): array {
        if ($attrId === 0 || empty($entityIds)) {
            return [];
        }

        $select = $conn->select()
            ->from($table, ['entity_id'])
            ->where('attribute_id = ?', $attrId)
            ->where('store_id IN (?)', [0, $storeId])
            ->where('entity_id IN (?)', $entityIds)
            ->where('value = ?', $value);

        return array_map('intval', $conn->fetchCol($select));
    }
}
