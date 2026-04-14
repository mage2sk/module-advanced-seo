<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Hreflang;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\HreflangResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Config\Source\CmsRelationMethod;
use Panth\AdvancedSEO\Model\Config\Source\HreflangScope;

/**
 * Group-based hreflang resolver.
 *
 * For a given (entity_type, entity_id, store_id) it resolves:
 *  1) The hreflang group that the entity belongs to (panth_seo_hreflang_member).
 *  2) Every member of that group across applicable stores (respecting scope config).
 *  3) Returns deduplicated alternates keyed by locale. Guarantees x-default
 *     presence (either from an explicit is_default row or derived from the
 *     first entry) when `panth_seo/hreflang/emit_x_default` is set.
 *
 * CMS pages support three relation methods (configurable):
 *  - by_identifier: Manual grouping via panth_seo_hreflang_group table.
 *  - by_id:         Matches CMS pages with the same entity_id across stores.
 *  - by_url_key:    Matches CMS pages with the same identifier (URL key) across stores.
 */
class Resolver implements HreflangResolverInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly Config $config
    ) {
    }

    public function getAlternates(string $entityType, int $entityId, int $storeId): array
    {
        if (!$this->config->isHreflangEnabled($storeId)) {
            return [];
        }

        // For CMS entities, check the relation method; non-identifier methods bypass the group table.
        if ($entityType === MetaResolverInterface::ENTITY_CMS) {
            $method = $this->config->getCmsRelationMethod($storeId);
            if ($method !== CmsRelationMethod::BY_IDENTIFIER) {
                return $this->resolveCmsByRelation($method, $entityId, $storeId);
            }
        }

        return $this->resolveByGroup($entityType, $entityId, $storeId);
    }

    public function validateGroup(int $groupId): array
    {
        $errors = [];
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($memberTable, ['member_id', 'store_id', 'entity_type', 'entity_id', 'locale', 'url', 'is_default'])
                ->where('group_id = ?', $groupId)
        );
        if ($rows === []) {
            return [sprintf('Group %d has no members.', $groupId)];
        }

        $locales = [];
        $defaults = 0;
        $entityType = null;
        foreach ($rows as $row) {
            $locale = strtolower((string) $row['locale']);
            if (isset($locales[$locale])) {
                $errors[] = sprintf('Duplicate locale "%s" in group %d.', $locale, $groupId);
            }
            $locales[$locale] = true;
            if ((bool) $row['is_default']) {
                $defaults++;
            }
            $entityType = $entityType ?? (string) $row['entity_type'];
            if ($entityType !== (string) $row['entity_type']) {
                $errors[] = sprintf('Mixed entity types in group %d.', $groupId);
            }
            if (!filter_var($row['url'], FILTER_VALIDATE_URL)) {
                $errors[] = sprintf('Invalid URL for member %d: %s', (int) $row['member_id'], (string) $row['url']);
            }
        }
        if ($defaults > 1) {
            $errors[] = sprintf('Group %d has %d x-default rows (max 1).', $groupId, $defaults);
        }
        if (count($locales) < 2) {
            $errors[] = sprintf('Group %d must contain at least 2 locales for hreflang to be meaningful.', $groupId);
        }

        return $errors;
    }

    /**
     * Resolve hreflang alternates using the group table (existing/identifier-based behavior).
     *
     * @return array<int, array{locale: string, url: string, is_default: bool}>
     */
    private function resolveByGroup(string $entityType, int $entityId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        // Find the group the entity belongs to.
        $groupId = (int) $connection->fetchOne(
            $connection->select()
                ->from($memberTable, ['group_id'])
                ->where('entity_type = ?', $entityType)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->limit(1)
        );
        if ($groupId <= 0) {
            return [];
        }

        $select = $connection->select()
            ->from($memberTable, ['store_id', 'locale', 'url', 'is_default'])
            ->where('group_id = ?', $groupId);

        // Apply scope filtering.
        $allowedStoreIds = $this->getAllowedStoreIds($storeId);
        if ($allowedStoreIds !== null) {
            $select->where('store_id IN (?)', $allowedStoreIds);
        }

        $rows = $connection->fetchAll($select);

        return $this->buildAlternates($rows, $storeId);
    }

    /**
     * Resolve CMS page hreflang using by_id or by_url_key matching.
     *
     * @return array<int, array{locale: string, url: string, is_default: bool}>
     */
    private function resolveCmsByRelation(string $method, int $entityId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $cmsPageTable = $this->resource->getTableName('cms_page');
        $cmsStoreTable = $this->resource->getTableName('cms_page_store');

        // Determine the matching criterion for the current page.
        if ($method === CmsRelationMethod::BY_ID) {
            $matchField = 'page_id';
            $matchValue = $entityId;
        } else {
            // BY_URL_KEY: look up the identifier for the current page.
            $identifier = (string) $connection->fetchOne(
                $connection->select()
                    ->from($cmsPageTable, ['identifier'])
                    ->where('page_id = ?', $entityId)
                    ->limit(1)
            );
            if ($identifier === '') {
                return [];
            }
            $matchField = 'identifier';
            $matchValue = $identifier;
        }

        // Find all active CMS pages matching the criterion, joined with their store assignments.
        $select = $connection->select()
            ->from(['p' => $cmsPageTable], ['page_id', 'identifier'])
            ->join(
                ['ps' => $cmsStoreTable],
                'p.page_id = ps.page_id',
                ['store_id']
            )
            ->where('p.is_active = ?', 1)
            ->where("p.{$matchField} = ?", $matchValue)
            ->where('ps.store_id != 0'); // Exclude "all store views" placeholder.

        // Apply scope filtering.
        $allowedStoreIds = $this->getAllowedStoreIds($storeId);
        if ($allowedStoreIds !== null) {
            $select->where('ps.store_id IN (?)', $allowedStoreIds);
        }

        $rows = $connection->fetchAll($select);
        if ($rows === []) {
            return [];
        }

        // Build alternates from matched CMS pages.
        $alternates = [];
        $seen = [];
        $hasDefault = false;

        foreach ($rows as $row) {
            $relatedStoreId = (int) $row['store_id'];
            $store = $this->getStoreById($relatedStoreId);
            if ($store === null) {
                continue;
            }

            $locale = $this->getStoreLocale($relatedStoreId);
            if ($locale === '') {
                continue;
            }

            $key = strtolower($locale);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $url = rtrim((string) $store->getBaseUrl(), '/') . '/' . ltrim((string) $row['identifier'], '/');

            $alternates[] = [
                'locale'     => $locale,
                'url'        => $url,
                'is_default' => false,
            ];
        }

        if ($alternates === []) {
            return [];
        }

        // Emit x-default if configured.
        if ($this->config->emitHreflangXDefault($storeId)) {
            $hasDefault = false;
            foreach ($alternates as $alt) {
                if ($alt['locale'] === 'x-default') {
                    $hasDefault = true;
                    break;
                }
            }
            if (!$hasDefault) {
                $alternates[] = [
                    'locale'     => 'x-default',
                    'url'        => $alternates[0]['url'],
                    'is_default' => true,
                ];
            }
        }

        return count($alternates) >= 2 ? $alternates : [];
    }

    /**
     * Build deduplicated alternates array from member rows.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array{locale: string, url: string, is_default: bool}>
     */
    private function buildAlternates(array $rows, int $storeId): array
    {
        if ($rows === []) {
            return [];
        }

        $alternates = [];
        $seen = [];
        $hasDefault = false;

        foreach ($rows as $row) {
            $locale = (string) $row['locale'];
            $url = (string) $row['url'];
            $isDefault = (bool) $row['is_default'];
            if ($locale === '' || $url === '') {
                continue;
            }
            $key = strtolower($locale);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($isDefault || $locale === 'x-default') {
                $hasDefault = true;
            }
            $alternates[] = [
                'locale'     => $locale,
                'url'        => $url,
                'is_default' => $isDefault,
            ];
        }

        if (!$hasDefault && $this->config->emitHreflangXDefault($storeId) && $alternates !== []) {
            $alternates[] = [
                'locale'     => 'x-default',
                'url'        => $alternates[0]['url'],
                'is_default' => true,
            ];
        }

        return $alternates;
    }

    /**
     * Return the list of store IDs allowed by the hreflang scope configuration.
     *
     * - `website`: Only stores within the same website as $storeId.
     * - `global`:  All stores (returns null to skip filtering).
     *
     * @return int[]|null  Null means no filtering (global scope).
     */
    private function getAllowedStoreIds(int $storeId): ?array
    {
        $scope = $this->config->getHreflangScope($storeId);
        if ($scope !== HreflangScope::SCOPE_WEBSITE) {
            return null;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $websiteId = (int) $store->getWebsiteId();
            $website = $this->websiteRepository->getById($websiteId);
            return array_map('intval', $website->getStoreIds());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the hreflang locale for a given store (from Magento general/locale/code).
     */
    private function getStoreLocale(int $storeId): string
    {
        $locale = (string) $this->config->getValue('general/locale/code', $storeId);
        if ($locale === '') {
            return '';
        }
        // Convert Magento locale (en_US) to BCP 47 (en-US).
        return str_replace('_', '-', $locale);
    }

    /**
     * Safe store lookup that does not throw on invalid IDs.
     */
    private function getStoreById(int $storeId): ?\Magento\Store\Api\Data\StoreInterface
    {
        try {
            return $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return null;
        }
    }
}
