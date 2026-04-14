<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Robots;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Computes the page-level `<meta name="robots">` value.
 *
 * Resolution order (first non-empty wins):
 *  1) Per-entity override in `panth_seo_override.robots`.
 *  2) Rule-engine actions recorded in `panth_seo_resolved.robots`.
 *  3) URL pattern policy: filtered layered nav, search results, pagination.
 *  4) `panth_seo/robots/default_meta` system config.
 */
class MetaResolver
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(string $entityType, int $entityId, int $storeId): string
    {
        // URL pattern overrides (layered-nav filters, on-site search results)
        // have to win over any per-entity stored value because the entity row
        // usually holds the plain `index,follow` for the un-filtered category.
        // If we applied the stored value first, noindex_layered_nav_pages and
        // noindex_search_results would be dead toggles on every category that
        // already has a resolved robots row.
        if ($this->isNoindexByUrlPattern($storeId)) {
            return 'noindex,follow';
        }

        $stored = $this->fetchStored($entityType, $entityId, $storeId);
        if ($stored !== '') {
            return $this->normalize($stored);
        }

        return $this->normalize($this->config->getDefaultMetaRobots($storeId));
    }

    private function fetchStored(string $entityType, int $entityId, int $storeId): string
    {
        if ($entityId <= 0) {
            return '';
        }
        $connection = $this->resource->getConnection();

        $overrideTable = $this->resource->getTableName('panth_seo_override');
        $value = (string) $connection->fetchOne(
            $connection->select()
                ->from($overrideTable, ['robots'])
                ->where('entity_type = ?', $entityType)
                ->where('entity_id = ?', $entityId)
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id DESC')
                ->limit(1)
        );
        if ($value !== '') {
            return $value;
        }

        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');
        $value = (string) $connection->fetchOne(
            $connection->select()
                ->from($resolvedTable, ['robots'])
                ->where('entity_type = ?', $entityType)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->limit(1)
        );
        return $value;
    }

    private function isNoindexByUrlPattern(int $storeId): bool
    {
        $params = (array) $this->request->getParams();
        if ($params === []) {
            return false;
        }

        // Layered nav filter params => noindex when enabled.
        if ($this->config->isEnabled($storeId) && $this->hasFilterParams($params)
            && $this->flag('panth_seo/robots/noindex_filtered', $storeId)) {
            return true;
        }

        // On-site search results => noindex when enabled.
        $path = (string) $this->request->getPathInfo();
        if (str_contains($path, 'catalogsearch/result')
            && $this->flag('panth_seo/robots/noindex_search_results', $storeId)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function hasFilterParams(array $params): bool
    {
        // Layered nav typically adds attribute filter params; sort/dir/limit also trigger.
        $filterKeys = ['product_list_order', 'product_list_dir', 'product_list_limit', 'product_list_mode'];
        foreach ($filterKeys as $k) {
            if (isset($params[$k])) {
                return true;
            }
        }
        // Any param not in the safe set we treat as a filter candidate.
        $safe = ['p', 'id', 'category', '___store', '___from_store'];
        foreach ($params as $key => $_) {
            if (!in_array($key, $safe, true) && !in_array($key, $filterKeys, true)) {
                return true;
            }
        }
        return false;
    }

    private function flag(string $path, int $storeId): bool
    {
        return (bool) $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'index,follow';
        }
        return $value;
    }

    /**
     * Append Google-specific robots directives (max-image-preview, max-snippet)
     * to the base robots value when configured.
     */
    public function appendAdvancedDirectives(string $baseRobots, int $storeId): string
    {
        $directives = [];

        $maxImagePreview = $this->config->getMaxImagePreview($storeId);
        if ($maxImagePreview !== 'none') {
            $directives[] = 'max-image-preview:' . $maxImagePreview;
        }

        $maxSnippet = $this->config->getMaxSnippet($storeId);
        $directives[] = 'max-snippet:' . $maxSnippet;

        if ($directives === []) {
            return $baseRobots;
        }

        return $baseRobots . ',' . implode(',', $directives);
    }

    /**
     * Resolve robots value with advanced directives appended.
     */
    public function resolveWithDirectives(string $entityType, int $entityId, int $storeId): string
    {
        $base = $this->resolve($entityType, $entityId, $storeId);

        return $this->appendAdvancedDirectives($base, $storeId);
    }
}
