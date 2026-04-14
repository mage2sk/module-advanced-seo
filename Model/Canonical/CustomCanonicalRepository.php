<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Repository for custom canonical URL overrides.
 *
 * Queries the panth_seo_custom_canonical table for an active mapping that
 * matches the given entity.  If target_url is set it is returned directly;
 * otherwise the target_entity_type + target_entity_id pair is resolved to
 * a URL.  Store-specific rows take priority over store_id = 0 (global).
 */
class CustomCanonicalRepository
{
    private const TABLE = 'panth_seo_custom_canonical';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Find a custom canonical URL for the given entity.
     *
     * @return string|null The resolved canonical URL, or null when no override exists.
     */
    public function find(string $entityType, int $entityId, int $storeId): ?string
    {
        try {
            $row = $this->loadRow($entityType, $entityId, $storeId);
            if ($row === null) {
                return null;
            }

            $targetUrl = (string) ($row['target_url'] ?? '');
            if ($targetUrl !== '') {
                return $targetUrl;
            }

            $targetEntityType = (string) ($row['target_entity_type'] ?? '');
            $targetEntityId   = (int) ($row['target_entity_id'] ?? 0);
            if ($targetEntityType !== '' && $targetEntityId > 0) {
                return $this->resolveEntityUrl($targetEntityType, $targetEntityId, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO custom canonical lookup failed', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Save (insert or update) a custom canonical override.
     *
     * @param array<string, mixed> $data Row data keyed by column name.
     */
    public function save(array $data): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);
        $id         = (int) ($data['canonical_id'] ?? 0);

        unset($data['canonical_id']);

        if ($id > 0) {
            $connection->update($table, $data, ['canonical_id = ?' => $id]);
        } else {
            $connection->insert($table, $data);
            $id = (int) $connection->lastInsertId($table);
        }

        return $id;
    }

    /**
     * Delete a custom canonical row by its primary key.
     */
    public function deleteById(int $canonicalId): void
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['canonical_id = ?' => $canonicalId]);
    }

    /**
     * Find a row matching the entity for a given store, falling back to store 0.
     *
     * @return array<string, mixed>|null
     */
    private function loadRow(string $entityType, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table)
            ->where('source_entity_type = ?', $entityType)
            ->where('source_entity_id = ?', $entityId)
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('store_id DESC') // store-specific wins over global
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row !== false ? $row : null;
    }

    /**
     * Resolve a target entity reference to its frontend URL.
     */
    private function resolveEntityUrl(string $entityType, int $entityId, int $storeId): ?string
    {
        try {
            return match ($entityType) {
                MetaResolverInterface::ENTITY_PRODUCT  => $this->resolveProductUrl($entityId, $storeId),
                MetaResolverInterface::ENTITY_CATEGORY => $this->resolveCategoryUrl($entityId, $storeId),
                MetaResolverInterface::ENTITY_CMS      => $this->resolveCmsUrl($entityId, $storeId),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO custom canonical entity resolution failed', [
                'target_entity_type' => $entityType,
                'target_entity_id'   => $entityId,
                'error'              => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function resolveProductUrl(int $productId, int $storeId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
            return (string) $product->getUrlModel()->getUrl($product, [
                '_ignore_category' => true,
                '_scope'           => $storeId,
            ]);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function resolveCategoryUrl(int $categoryId, int $storeId): ?string
    {
        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
            return (string) $category->getUrl();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function resolveCmsUrl(int $pageId, int $storeId): ?string
    {
        try {
            $page  = $this->pageRepository->getById($pageId);
            $store = $this->storeManager->getStore($storeId);
            $identifier = (string) $page->getIdentifier();
            return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/')
                . '/' . ltrim($identifier, '/');
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
