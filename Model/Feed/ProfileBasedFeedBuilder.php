<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Profile-based feed generator that reads field mapping from panth_seo_feed_field
 * and generates XML or CSV product feeds.
 *
 * Products are loaded in batches of 500 for constant memory usage.
 */
class ProfileBasedFeedBuilder
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ConfigurableResource $configurableResource,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly FieldResolver $fieldResolver,
        private readonly XmlFeedWriter $xmlFeedWriter,
        private readonly CsvFeedWriter $csvFeedWriter,
        private readonly LoggerInterface $logger,
        private readonly FtpDelivery $ftpDelivery
    ) {
    }

    /**
     * Generate a feed from a profile ID.
     *
     * @param int $feedId Feed profile ID
     * @return array{product_count: int, file_size: int, generation_time: float, file_path: string}
     */
    public function generateById(int $feedId): array
    {
        $profile = $this->loadProfile($feedId);
        if ($profile === null) {
            throw new \RuntimeException(sprintf('Feed profile #%d not found.', $feedId));
        }

        return $this->generate($profile);
    }

    /**
     * Generate a feed from a profile array.
     *
     * @param array $profile Profile data row
     * @return array{product_count: int, file_size: int, generation_time: float, file_path: string}
     */
    public function generate(array $profile): array
    {
        $startTime = microtime(true);

        $feedId = (int) $profile['feed_id'];
        $storeId = (int) $profile['store_id'];
        $format = $profile['output_format'] ?? 'xml';
        $filename = $profile['filename'] ?? ('feed_' . $feedId . '.' . $format);

        // Load field mappings
        $fields = $this->loadFieldMappings($feedId);
        if (empty($fields)) {
            throw new \RuntimeException(sprintf('No field mappings found for feed profile #%d.', $feedId));
        }

        // Determine output path
        $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $feedDir = 'panth_seo/feeds';
        $mediaDir->create($feedDir);
        $filePath = $mediaDir->getAbsolutePath($feedDir . '/' . $filename);

        // Collect all attribute codes we need to load
        $attributeCodes = $this->collectAttributeCodes($fields);

        // Open writer
        $store = $this->storeManager->getStore($storeId);
        $writer = ($format === 'csv') ? $this->csvFeedWriter : $this->xmlFeedWriter;
        $writer->open($filePath, $store);

        $productCount = 0;
        $page = 1;

        // Build parent cache for configurable children
        $needsParent = $this->fieldsNeedParent($fields);

        do {
            $collection = $this->buildProductCollection($profile, $storeId, $attributeCodes, $page);
            $products = $collection->getItems();

            foreach ($products as $product) {
                try {
                    $parent = null;
                    if ($needsParent) {
                        $parent = $this->loadParentProduct($product, $storeId, $attributeCodes);
                    }

                    $resolvedFields = $this->resolveProductFields($fields, $product, $storeId, $parent);

                    // Apply UTM parameters to URL fields
                    $resolvedFields = $this->applyUtmParameters($resolvedFields, $profile);

                    // Check required fields - skip product if any required field is empty
                    if ($this->hasMissingRequiredFields($fields, $resolvedFields)) {
                        continue;
                    }

                    $writer->writeItem($resolvedFields);
                    $productCount++;
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        'Panth SEO Feed: failed to process product SKU "%s" for feed #%d: %s',
                        $product->getSku(),
                        $feedId,
                        $e->getMessage()
                    ));
                }
            }

            $writer->flush();
            $page++;
        } while (count($products) >= self::BATCH_SIZE);

        $writer->close();

        // Apply compression if configured
        $compress = trim((string) ($profile['compress'] ?? ''));
        if ($compress !== '' && file_exists($filePath)) {
            $filePath = $this->compressFile($filePath, $compress);
            $filename = basename($filePath);
        }

        $generationTime = round(microtime(true) - $startTime, 2);
        $fileSize = file_exists($filePath) ? (int) filesize($filePath) : 0;

        // Build file URL
        $baseMediaUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        $fileUrl = $baseMediaUrl . '/' . $feedDir . '/' . $filename;

        // Update profile stats
        $this->updateProfileStats($feedId, $productCount, $fileSize, $generationTime, $fileUrl);

        // Deliver via FTP/SFTP if enabled
        if (!empty($profile['delivery_enabled']) && file_exists($filePath)) {
            try {
                $this->ftpDelivery->deliver($profile, $filePath);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO Feed: FTP/SFTP delivery failed for profile #%d: %s',
                    $feedId,
                    $e->getMessage()
                ));
            }
        }

        return [
            'product_count'   => $productCount,
            'file_size'       => $fileSize,
            'generation_time' => $generationTime,
            'file_path'       => $filePath,
            'file_url'        => $fileUrl,
        ];
    }

    /**
     * Generate all active feed profiles (optionally filtered by store).
     *
     * @return array<int, array> Array of results keyed by feed_id
     */
    public function generateAllActive(?int $storeId = null, bool $cronOnly = false): array
    {
        $profiles = $this->loadActiveProfiles($storeId, $cronOnly);
        $results = [];

        foreach ($profiles as $profile) {
            $feedId = (int) $profile['feed_id'];
            try {
                $results[$feedId] = $this->generate($profile);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Panth SEO Feed: generation failed for profile #%d "%s": %s',
                    $feedId,
                    $profile['name'] ?? '',
                    $e->getMessage()
                ));
                $results[$feedId] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Load a feed profile by ID from the database.
     */
    public function loadProfile(int $feedId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('panth_seo_feed_profile');

        if (!$connection->isTableExists($tableName)) {
            return null;
        }

        $select = $connection->select()
            ->from($tableName)
            ->where('feed_id = ?', $feedId);

        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Load all active feed profiles.
     */
    public function loadActiveProfiles(?int $storeId = null, bool $cronOnly = false): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('panth_seo_feed_profile');

        if (!$connection->isTableExists($tableName)) {
            return [];
        }

        $select = $connection->select()
            ->from($tableName)
            ->where('is_active = ?', 1);

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        if ($cronOnly) {
            $select->where('cron_enabled = ?', 1);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Load field mappings for a feed profile, ordered by sort_order.
     */
    private function loadFieldMappings(int $feedId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('panth_seo_feed_field');

        if (!$connection->isTableExists($tableName)) {
            return [];
        }

        $select = $connection->select()
            ->from($tableName)
            ->where('feed_id = ?', $feedId)
            ->order('sort_order ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Collect all product attribute codes needed from field mappings.
     */
    private function collectAttributeCodes(array $fields): array
    {
        $codes = [
            'name', 'sku', 'url_key', 'image', 'price', 'special_price',
            'special_from_date', 'special_to_date', 'status', 'visibility',
            'weight', 'manufacturer', 'description', 'short_description',
        ];

        foreach ($fields as $field) {
            $sourceType = $field['source_type'] ?? '';
            $sourceValue = $field['source_value'] ?? '';

            if (in_array($sourceType, ['attribute', 'parent_attribute'], true) && $sourceValue !== '') {
                $codes[] = $sourceValue;
            }
        }

        return array_unique($codes);
    }

    /**
     * Check if any field mapping requires parent_attribute resolution.
     */
    private function fieldsNeedParent(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['source_type'] ?? '') === 'parent_attribute') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a paged product collection with filters from the profile.
     */
    private function buildProductCollection(
        array $profile,
        int $storeId,
        array $attributeCodes,
        int $page
    ): \Magento\Catalog\Model\ResourceModel\Product\Collection {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);

        // Status filter
        if (empty($profile['include_disabled'])) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        }

        // Visibility filter
        if (empty($profile['include_not_visible'])) {
            $collection->addAttributeToFilter('visibility', ['in' => [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_BOTH,
            ]]);
        }

        // Add required attributes
        $collection->addAttributeToSelect($attributeCodes);
        $collection->addUrlRewrite();
        $collection->addFinalPrice();

        // Category filter
        $categoryFilter = trim((string) ($profile['category_filter'] ?? ''));
        if ($categoryFilter !== '') {
            $categoryIds = array_filter(array_map('intval', explode(',', $categoryFilter)));
            if (!empty($categoryIds)) {
                $collection->addCategoriesFilter(['in' => $categoryIds]);
            }
        }

        // Attribute set filter
        $attrSetFilter = trim((string) ($profile['attribute_set_filter'] ?? ''));
        if ($attrSetFilter !== '') {
            $attrSetIds = array_filter(array_map('intval', explode(',', $attrSetFilter)));
            if (!empty($attrSetIds)) {
                $collection->addFieldToFilter('attribute_set_id', ['in' => $attrSetIds]);
            }
        }

        // Stock filter
        if (empty($profile['include_out_of_stock'])) {
            $collection->joinField(
                'is_in_stock',
                'cataloginventory_stock_item',
                'is_in_stock',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            );
            $collection->addFieldToFilter('is_in_stock', ['eq' => 1]);
        }

        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    /**
     * Load the configurable parent product for a simple child.
     */
    private function loadParentProduct(Product $product, int $storeId, array $attributeCodes): ?Product
    {
        if ($product->getTypeId() !== \Magento\Catalog\Model\Product\Type::DEFAULT_TYPE) {
            return null;
        }

        try {
            $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
            if (empty($parentIds)) {
                return null;
            }

            $parentId = (int) reset($parentIds);
            $parentCollection = $this->productCollectionFactory->create();
            $parentCollection->setStoreId($storeId);
            $parentCollection->addAttributeToSelect($attributeCodes);
            $parentCollection->addIdFilter($parentId);
            $parentCollection->addUrlRewrite();
            $parentCollection->setPageSize(1);

            $parent = $parentCollection->getFirstItem();
            return ($parent && $parent->getId()) ? $parent : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve all field values for a single product.
     *
     * @return array<string, string> feed_field => resolved_value
     */
    private function resolveProductFields(
        array $fields,
        Product $product,
        int $storeId,
        ?Product $parent
    ): array {
        $resolved = [];

        foreach ($fields as $fieldConfig) {
            $feedField = $fieldConfig['feed_field'] ?? '';
            if ($feedField === '') {
                continue;
            }

            $value = $this->fieldResolver->resolve($fieldConfig, $product, $storeId, $parent);
            $resolved[$feedField] = $value;
        }

        return $resolved;
    }

    /**
     * Check if any required field is empty in the resolved output.
     */
    private function hasMissingRequiredFields(array $fields, array $resolved): bool
    {
        foreach ($fields as $fieldConfig) {
            $feedField = $fieldConfig['feed_field'] ?? '';
            $isRequired = !empty($fieldConfig['is_required']);

            if ($isRequired && ($resolved[$feedField] ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Update profile record with generation stats.
     */
    private function updateProfileStats(
        int $feedId,
        int $productCount,
        int $fileSize,
        float $generationTime,
        string $fileUrl
    ): void {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('panth_seo_feed_profile');

            $connection->update(
                $tableName,
                [
                    'product_count'   => $productCount,
                    'file_size'       => $fileSize,
                    'generation_time' => round($generationTime, 2),
                    'generated_at'    => (new \DateTime())->format('Y-m-d H:i:s'),
                    'updated_at'      => (new \DateTime())->format('Y-m-d H:i:s'),
                ],
                ['feed_id = ?' => $feedId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Panth SEO Feed: failed to update stats for profile #%d: %s',
                $feedId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Apply UTM parameters to URL fields in resolved data.
     */
    private function applyUtmParameters(array $resolvedFields, array $profile): array
    {
        $utmSource = trim((string) ($profile['utm_source'] ?? ''));
        if ($utmSource === '') {
            return $resolvedFields;
        }

        $utmParams = ['utm_source' => $utmSource];
        $utmMedium = trim((string) ($profile['utm_medium'] ?? ''));
        if ($utmMedium !== '') {
            $utmParams['utm_medium'] = $utmMedium;
        }
        $utmCampaign = trim((string) ($profile['utm_campaign'] ?? ''));
        if ($utmCampaign !== '') {
            $utmParams['utm_campaign'] = $utmCampaign;
        }

        $queryString = http_build_query($utmParams);

        // Apply to common URL field names
        $urlFields = ['link', 'g:link', 'url', 'product_url', 'canonical_link'];
        foreach ($urlFields as $urlField) {
            if (isset($resolvedFields[$urlField]) && $resolvedFields[$urlField] !== '') {
                $separator = str_contains($resolvedFields[$urlField], '?') ? '&' : '?';
                $resolvedFields[$urlField] .= $separator . $queryString;
            }
        }

        return $resolvedFields;
    }

    /**
     * Compress a feed file using the specified method.
     *
     * @return string Path to the compressed file
     */
    private function compressFile(string $filePath, string $method): string
    {
        if ($method === 'gzip') {
            $gzPath = $filePath . '.gz';
            $fp = fopen($filePath, 'rb');
            $gz = gzopen($gzPath, 'wb9');
            if ($fp && $gz) {
                while (!feof($fp)) {
                    gzwrite($gz, fread($fp, 8192));
                }
                gzclose($gz);
                fclose($fp);
                unlink($filePath);
                return $gzPath;
            }
        } elseif ($method === 'zip') {
            $zipPath = $filePath . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $zip->addFile($filePath, basename($filePath));
                $zip->close();
                unlink($filePath);
                return $zipPath;
            }
        }

        return $filePath;
    }
}
