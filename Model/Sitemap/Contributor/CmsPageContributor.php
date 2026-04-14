<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap\Contributor;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\SitemapContributorInterface;

class CmsPageContributor implements SitemapContributorInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getCode(): string
    {
        return 'cms_page';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

        $conn   = $this->resource->getConnection();
        $page   = $this->resource->getTableName('cms_page');
        $store2 = $this->resource->getTableName('cms_page_store');

        $excludeNoindex = isset($config['exclude_noindex'])
            ? (bool) $config['exclude_noindex']
            : false;

        $changefreq = $config['changefreq'] ?? 'monthly';
        $priority   = isset($config['priority']) ? (float) $config['priority'] : 0.5;

        $select = $conn->select()
            ->from(['p' => $page], ['identifier', 'update_time'])
            ->join(['ps' => $store2], 'ps.page_id = p.page_id', [])
            ->where('p.is_active = ?', 1)
            ->where('ps.store_id IN (?)', [0, $storeId]);

        if ($excludeNoindex) {
            $resolvedTable = $this->resource->getTableName('panth_seo_resolved');
            $select->joinLeft(
                ['seo' => $resolvedTable],
                "seo.entity_type = 'cms_page' AND seo.entity_id = p.page_id AND seo.store_id IN (0, {$storeId})",
                []
            );
            $select->where('seo.robots IS NULL OR seo.robots NOT LIKE ?', '%noindex%');
        }

        $stmt = $conn->query($select);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $ident = (string) ($row['identifier'] ?? '');
            if ($ident === '' || $ident === 'no-route') {
                continue;
            }
            $lastmod = null;
            if (!empty($row['update_time'])) {
                try {
                    $lastmod = (new \DateTimeImmutable((string) $row['update_time']))->format('Y-m-d\TH:i:sP');
                } catch (\Throwable) {
                    $lastmod = null;
                }
            }
            $entry = [
                'loc'        => $baseUrl . ltrim($ident, '/'),
                'changefreq' => $changefreq,
                'priority'   => $priority,
            ];
            if ($lastmod !== null) {
                $entry['lastmod'] = $lastmod;
            }
            yield $entry;
        }
    }
}
