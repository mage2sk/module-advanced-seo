<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;

/**
 * Resolves the final meta payload for a given entity at a given store.
 *
 * Resolution precedence (highest to lowest):
 *  1. Per-entity override (`panth_seo_override`)
 *  2. Rule engine result
 *  3. Template (best matching scope)
 *  4. Native Magento fallback (product.meta_title, etc.)
 */
interface MetaResolverInterface
{
    public const ENTITY_PRODUCT  = 'product';
    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_CMS      = 'cms';
    public const ENTITY_OTHER    = 'other';

    /**
     * @param string $entityType One of ENTITY_* constants.
     * @param int    $entityId
     * @param int    $storeId
     */
    public function resolve(string $entityType, int $entityId, int $storeId): ResolvedMetaInterface;

    /**
     * Bulk-resolve for indexer use.
     *
     * @param string $entityType
     * @param int[]  $entityIds
     * @param int    $storeId
     * @return ResolvedMetaInterface[] keyed by entity id
     */
    public function resolveBatch(string $entityType, array $entityIds, int $storeId): array;
}
