<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

interface EntityLookupInterface
{
    public function entityForUrl(string $url, int $storeId): ?array;

    public function resolvedTitle(string $url, int $storeId): ?string;

    public function sourcesLinkingTo(string $url, ?array $entity, int $storeId): array;
}
