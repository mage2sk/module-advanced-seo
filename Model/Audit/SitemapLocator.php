<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class SitemapLocator
{
    public const DEFAULT_FILENAME = 'sitemap.xml';

    private const PROFILE_TABLE = 'panth_seo_sitemap_profile';

    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.xml$/';

    private const SEGMENT_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getSitemapUrls(int $storeId, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/') . '/';
        $urls = [];
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::PROFILE_TABLE);
            if ($connection->isTableExists($table)) {
                $columns = ['output_path'];
                if ($connection->tableColumnExists($table, 'sitemap_filename')) {
                    $columns[] = 'sitemap_filename';
                }
                $rows = $connection->fetchAll(
                    $connection->select()
                        ->from($table, $columns)
                        ->where('is_active = ?', 1)
                        ->where('store_id IN (?)', [0, $storeId])
                        ->order('store_id DESC')
                );
                $storeCode = (string) $this->storeManager->getStore($storeId)->getCode();
                foreach ($rows as $row) {
                    $urls[] = $baseUrl . $this->relativePath(
                        (string) ($row['output_path'] ?? ''),
                        (string) ($row['sitemap_filename'] ?? ''),
                        $storeCode
                    );
                }
            }
        } catch (\Throwable) {
            $urls = [];
        }

        $urls = array_values(array_unique($urls));

        return $urls !== [] ? $urls : [$baseUrl . self::DEFAULT_FILENAME];
    }

    public function relativePath(string $outputPath, string $filename, string $storeCode): string
    {
        $path = strtr(trim($outputPath), ['{store_code}' => $storeCode]);
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match(self::SEGMENT_PATTERN, $segment) !== 1
            ) {
                continue;
            }
            $segments[] = $segment;
        }
        $filename = trim($filename);
        if ($filename === '' || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            $filename = self::DEFAULT_FILENAME;
        }

        return ($segments !== [] ? implode('/', $segments) . '/' : '') . $filename;
    }
}
