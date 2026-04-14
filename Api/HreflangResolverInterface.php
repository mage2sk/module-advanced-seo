<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Resolves hreflang alternates for a given entity.
 */
interface HreflangResolverInterface
{
    /**
     * @return array<int,array{locale:string,url:string,is_default:bool}>
     */
    public function getAlternates(string $entityType, int $entityId, int $storeId): array;

    /**
     * Validates reciprocity of a hreflang group.
     *
     * @return array<int,string> Error messages (empty if valid)
     */
    public function validateGroup(int $groupId): array;
}
