<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap\Contributor;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\SitemapContributorInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Streams visible, enabled product URLs via unbuffered PDO query.
 * Uses the url_rewrite table to get canonical paths.
 *
 * Supports:
 * - Homepage optimisation (priority 1.0 / daily) when enabled in config
 * - Configurable image source for image sitemap entries (base_image, small_image, thumbnail)
 */
class ProductContributor implements SitemapContributorInterface
{
    /** @var array<string, int>|null Lazy-loaded attribute-code => attribute_id map */
    private ?array $imageAttributeIds = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly MediaConfig $mediaConfig
    ) {
    }

    public function getCode(): string
    {
        return 'product';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        $store   = $this->storeManager->getStore($storeId);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/') . '/';

        // Yield homepage entry first when optimisation is enabled
        if ($this->config->isSitemapHomepageOptimization($storeId)) {
            $defaultChangefreq = $config['changefreq'] ?? 'daily';
            yield [
                'loc'        => rtrim($baseUrl, '/') . '/',
                'changefreq' => $defaultChangefreq,
                'priority'   => 1.0,
            ];
        }

        $conn = $this->resource->getConnection();
        /** @var \PDO|null $pdo */
        $pdo = $conn->getConnection();
        $urlTable = $this->resource->getTableName('url_rewrite');

        // Unbuffered query to avoid buffering million-row result sets.
        if ($pdo instanceof \PDO) {
            try {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            } catch (\Throwable) {
                // driver may not allow mid-connection toggle
            }
        }

        $stockTable    = $this->resource->getTableName('cataloginventory_stock_status');
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');

        // Profile config overrides store-level config
        $excludeOos     = isset($config['exclude_out_of_stock'])
            ? (bool) $config['exclude_out_of_stock']
            : $this->config->sitemapExcludeOutOfStock($storeId);
        $excludeNoindex = isset($config['exclude_noindex'])
            ? (bool) $config['exclude_noindex']
            : $this->config->sitemapExcludeNoindex($storeId);
        $includeImages  = isset($config['include_images'])
            ? (bool) $config['include_images']
            : $this->config->sitemapIncludeImages($storeId);
        $imageSource    = $this->config->getSitemapProductImageSource($storeId);

        // Resolve the image attribute to join for image sitemap entries
        $imageAttributeId = $includeImages ? $this->resolveImageAttributeId($imageSource) : 0;
        $mediaBaseUrl     = $includeImages ? $this->getMediaBaseUrl($store) : '';

        $selects = 'ur.request_path, ur.metadata, ur.entity_id';

        $joins = '';
        $wheres = '';

        if ($excludeOos) {
            $joins .= sprintf(
                ' INNER JOIN %s AS stock ON stock.product_id = ur.entity_id AND stock.website_id = 0 AND stock.stock_status = 1',
                $conn->quoteIdentifier($stockTable)
            );
        }

        if ($excludeNoindex) {
            $joins .= sprintf(
                ' LEFT JOIN %s AS seo ON seo.entity_type = \'product\' AND seo.entity_id = ur.entity_id AND seo.store_id IN (0, %d)',
                $conn->quoteIdentifier($resolvedTable),
                $storeId
            );
            $wheres .= ' AND (seo.robots IS NULL OR seo.robots NOT LIKE \'%%noindex%%\')';
        }

        // Left-join product image attribute value (store-scoped with global fallback)
        if ($includeImages && $imageAttributeId > 0) {
            $varcharTable = $this->resource->getTableName('catalog_product_entity_varchar');
            $selects .= ', COALESCE(img_store.value, img_default.value) AS product_image';
            $joins .= sprintf(
                ' LEFT JOIN %1$s AS img_default'
                . ' ON img_default.entity_id = ur.entity_id'
                . ' AND img_default.attribute_id = %2$d'
                . ' AND img_default.store_id = 0'
                . ' LEFT JOIN %1$s AS img_store'
                . ' ON img_store.entity_id = ur.entity_id'
                . ' AND img_store.attribute_id = %2$d'
                . ' AND img_store.store_id = %3$d',
                $conn->quoteIdentifier($varcharTable),
                $imageAttributeId,
                $storeId
            );
        }

        $sql = sprintf(
            'SELECT %s FROM %s AS ur'
            . '%s'
            . ' WHERE ur.entity_type = %s AND ur.store_id = %d AND ur.redirect_type = 0 AND ur.is_autogenerated = 1'
            . '%s',
            $selects,
            $conn->quoteIdentifier($urlTable),
            $joins,
            $conn->quote('product'),
            $storeId,
            $wheres
        );
        $stmt = $pdo instanceof \PDO ? $pdo->query($sql) : $conn->query($sql);
        if (!$stmt) {
            return;
        }

        try {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $path = (string) ($row['request_path'] ?? '');
                if ($path === '') {
                    continue;
                }

                $entry = [
                    'loc'        => $baseUrl . ltrim($path, '/'),
                    'changefreq' => $config['changefreq'] ?? 'weekly',
                    'priority'   => isset($config['priority']) ? (float) $config['priority'] : 0.8,
                ];

                // Homepage optimisation: boost priority/changefreq for root or "home" path
                if ($this->config->isSitemapHomepageOptimization($storeId) && $this->isHomepage($path)) {
                    $entry['changefreq'] = 'daily';
                    $entry['priority']   = 1.0;
                }

                // Attach image data when available
                if ($includeImages && $imageAttributeId > 0) {
                    $imagePath = $this->sanitizeImageValue((string) ($row['product_image'] ?? ''));
                    if ($imagePath !== '') {
                        $entry['images'] = [
                            ['loc' => $mediaBaseUrl . $imagePath],
                        ];
                    }
                }

                yield $entry;
            }
        } finally {
            if ($pdo instanceof \PDO) {
                try {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

    }

    /**
     * Determine whether a URL path represents the homepage.
     */
    private function isHomepage(string $path): bool
    {
        $normalised = trim(strtolower($path), '/');
        return $normalised === '' || $normalised === 'home';
    }

    /**
     * Map an image source config value to the corresponding EAV attribute ID.
     */
    private function resolveImageAttributeId(string $imageSource): int
    {
        if ($this->imageAttributeIds === null) {
            $this->imageAttributeIds = [];
            $conn = $this->resource->getConnection();
            $eavTable = $this->resource->getTableName('eav_attribute');
            $entityTypeTable = $this->resource->getTableName('eav_entity_type');

            $sql = sprintf(
                'SELECT ea.attribute_code, ea.attribute_id FROM %s AS ea'
                . ' INNER JOIN %s AS et ON et.entity_type_id = ea.entity_type_id AND et.entity_type_code = %s'
                . ' WHERE ea.attribute_code IN (%s, %s, %s)',
                $conn->quoteIdentifier($eavTable),
                $conn->quoteIdentifier($entityTypeTable),
                $conn->quote('catalog_product'),
                $conn->quote('image'),
                $conn->quote('small_image'),
                $conn->quote('thumbnail')
            );

            $rows = $conn->fetchAll($sql);
            foreach ($rows as $row) {
                $this->imageAttributeIds[(string) $row['attribute_code']] = (int) $row['attribute_id'];
            }
        }

        // Map config value to the actual EAV attribute code
        $attributeCode = match ($imageSource) {
            'small_image' => 'small_image',
            'thumbnail'   => 'thumbnail',
            default       => 'image', // base_image maps to "image" attribute
        };

        return $this->imageAttributeIds[$attributeCode] ?? 0;
    }

    /**
     * Build the catalog media base URL for a given store.
     */
    private function getMediaBaseUrl(mixed $store): string
    {
        $baseMediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        return $baseMediaUrl . '/catalog/product';
    }

    /**
     * Sanitise the raw image value from EAV storage.
     *
     * Filters out the Magento "no_selection" placeholder and ensures the value
     * starts with a forward slash.
     */
    private function sanitizeImageValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'no_selection') {
            return '';
        }
        return str_starts_with($value, '/') ? $value : '/' . $value;
    }
}
