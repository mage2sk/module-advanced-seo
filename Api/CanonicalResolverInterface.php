<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Computes the canonical URL for the current request or a given entity.
 */
interface CanonicalResolverInterface
{
    /**
     * @param string              $entityType
     * @param int                 $entityId
     * @param int                 $storeId
     * @param array<string,mixed> $params Optional query params that may or may not be stripped.
     */
    public function getCanonicalUrl(
        string $entityType,
        int $entityId,
        int $storeId,
        array $params = []
    ): string;

    /**
     * Normalize an arbitrary URL by applying canonical rules
     * (strip query, lowercase host, remove trailing slash, drop pagination params, etc.).
     */
    public function normalize(string $url, int $storeId): string;
}
