<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Fix;

class HreflangGroupValidator
{
    public function duplicateLocales(array $members, callable $storeLocale): array
    {
        $seen = [];
        foreach ($members as $member) {
            if (!is_array($member) || (int) ($member['is_removed'] ?? 0) === 1) {
                continue;
            }
            $storeId  = (int) ($member['store_id'] ?? 0);
            $entityId = (int) ($member['entity_id'] ?? 0);
            if ($storeId <= 0 || $entityId <= 0) {
                continue;
            }
            $locale = trim((string) ($member['locale'] ?? ''));
            if ($locale === '') {
                $locale = (string) $storeLocale($storeId);
            }
            if ($locale === '') {
                continue;
            }
            $seen[strtolower(str_replace('_', '-', $locale))][] = $storeId . ':' . $entityId;
        }

        return array_filter($seen, static fn (array $owners): bool => count($owners) > 1);
    }
}
