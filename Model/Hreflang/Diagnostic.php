<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Hreflang;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Self-test diagnostic for hreflang configuration.
 *
 * Checks for common misconfigurations and data inconsistencies:
 *  - Missing x-default across groups
 *  - Orphan groups (fewer than 2 members)
 *  - Stores without hreflang locale configuration
 *  - Conflicting locales (same locale assigned to multiple stores in a group)
 */
class Diagnostic
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * Run all diagnostic checks for a given store context.
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     *         type: check category (e.g. 'x_default', 'orphan_group', 'locale', 'conflict')
     *         severity: 'error' | 'warning'
     *         message: Human-readable description
     */
    public function runDiagnostics(int $storeId): array
    {
        $issues = [];

        $issues = array_merge($issues, $this->checkStoresWithoutLocale($storeId));
        $issues = array_merge($issues, $this->checkMissingXDefault());
        $issues = array_merge($issues, $this->checkOrphanGroups());
        $issues = array_merge($issues, $this->checkConflictingLocales());
        $issues = array_merge($issues, $this->checkStoresWithoutHreflangConfig());

        return $issues;
    }

    /**
     * Check for stores that do not have a locale configured (general/locale/code).
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    private function checkStoresWithoutLocale(int $storeId): array
    {
        $issues = [];

        try {
            $stores = $this->storeManager->getStores(false);
        } catch (\Throwable) {
            return [];
        }

        foreach ($stores as $store) {
            $sid = (int) $store->getId();
            $locale = (string) $this->config->getValue('general/locale/code', $sid);
            if ($locale === '') {
                $issues[] = [
                    'type'     => 'locale',
                    'severity' => 'error',
                    'message'  => sprintf(
                        'Store "%s" (ID %d) has no locale configured. Hreflang tags cannot be generated.',
                        $store->getName(),
                        $sid
                    ),
                ];
            }
        }

        return $issues;
    }

    /**
     * Check for active groups that have no x-default member.
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    private function checkMissingXDefault(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        // Active groups where no member has is_default = 1.
        $select = $connection->select()
            ->from(['g' => $groupTable], ['group_id', 'code'])
            ->joinLeft(
                ['m' => $memberTable],
                'g.group_id = m.group_id AND m.is_default = 1',
                []
            )
            ->where('g.is_active = ?', 1)
            ->where('m.member_id IS NULL')
            ->group('g.group_id');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $issues[] = [
                'type'     => 'x_default',
                'severity' => 'warning',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has no x-default member. Search engines may choose an arbitrary default.',
                    (string) $row['code'],
                    (int) $row['group_id']
                ),
            ];
        }

        return $issues;
    }

    /**
     * Check for orphan groups: active groups with fewer than 2 members.
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    private function checkOrphanGroups(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['g' => $groupTable], ['group_id', 'code'])
            ->joinLeft(
                ['m' => $memberTable],
                'g.group_id = m.group_id',
                ['member_count' => new \Zend_Db_Expr('COUNT(m.member_id)')]
            )
            ->where('g.is_active = ?', 1)
            ->group('g.group_id')
            ->having('member_count < 2');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $count = (int) $row['member_count'];
            $issues[] = [
                'type'     => 'orphan_group',
                'severity' => 'warning',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has only %d member(s). At least 2 are required for hreflang to be meaningful.',
                    (string) $row['code'],
                    (int) $row['group_id'],
                    $count
                ),
            ];
        }

        return $issues;
    }

    /**
     * Check for conflicting locales within the same group (duplicate locale codes).
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    private function checkConflictingLocales(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        // Find groups with duplicate locales among their members.
        $select = $connection->select()
            ->from(['m' => $memberTable], [
                'group_id',
                'locale',
                'dup_count' => new \Zend_Db_Expr('COUNT(m.member_id)'),
            ])
            ->join(
                ['g' => $groupTable],
                'g.group_id = m.group_id',
                ['code']
            )
            ->where('g.is_active = ?', 1)
            ->group(['m.group_id', 'm.locale'])
            ->having('dup_count > 1');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $issues[] = [
                'type'     => 'conflict',
                'severity' => 'error',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has %d members with locale "%s". Each locale must be unique within a group.',
                    (string) $row['code'],
                    (int) $row['group_id'],
                    (int) $row['dup_count'],
                    (string) $row['locale']
                ),
            ];
        }

        return $issues;
    }

    /**
     * Check for stores that have hreflang disabled while being members of active groups.
     *
     * @return array<int, array{type: string, severity: string, message: string}>
     */
    private function checkStoresWithoutHreflangConfig(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');

        if (!$connection->isTableExists($memberTable) || !$connection->isTableExists($groupTable)) {
            return [];
        }

        // Get all unique store IDs that are members of active groups.
        $select = $connection->select()
            ->from(['m' => $memberTable], ['store_id'])
            ->join(
                ['g' => $groupTable],
                'g.group_id = m.group_id',
                []
            )
            ->where('g.is_active = ?', 1)
            ->where('m.store_id != 0')
            ->distinct();

        $storeIds = array_map('intval', $connection->fetchCol($select));

        foreach ($storeIds as $sid) {
            if (!$this->config->isHreflangEnabled($sid)) {
                $storeName = $this->getStoreName($sid);
                $issues[] = [
                    'type'     => 'config',
                    'severity' => 'warning',
                    'message'  => sprintf(
                        'Store "%s" (ID %d) is a member of active hreflang groups but has hreflang disabled in config.',
                        $storeName,
                        $sid
                    ),
                ];
            }
        }

        return $issues;
    }

    /**
     * Safely resolve a store name by ID.
     */
    private function getStoreName(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable) {
            return 'Unknown';
        }
    }
}
