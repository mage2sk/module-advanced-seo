<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Template;

class ConditionEvaluator
{
    public function evaluate(array $conditions, mixed $entity, int $storeId): bool
    {
        if ($conditions === []) {
            return true;
        }

        $type = strtolower((string) ($conditions['type'] ?? 'all'));
        $childConditions = $conditions['conditions'] ?? [];

        if (!is_array($childConditions) || $childConditions === []) {
            return true;
        }

        foreach ($childConditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $result = $this->evaluateSingle($condition, $entity, $storeId);

            if ($type === 'any' && $result) {
                return true;
            }
            if ($type === 'all' && !$result) {
                return false;
            }
        }

        return $type === 'all';
    }

    private function evaluateSingle(array $condition, mixed $entity, int $storeId): bool
    {
        $attribute = (string) ($condition['attribute'] ?? '');
        $operator  = strtolower((string) ($condition['operator'] ?? 'eq'));
        $value     = (string) ($condition['value'] ?? '');

        if ($attribute === '' || $value === '') {
            return true;
        }

        if ($attribute === 'category_ids' && $entity instanceof \Magento\Catalog\Api\Data\CategoryInterface) {
            $entityValue = (string) $entity->getId();
            return $this->compareScalar($entityValue, 'in', $value);
        }

        $entityValue = $this->resolveEntityValue($attribute, $entity, $storeId);

        return match ($attribute) {
            'category_ids' => $this->evaluateCategoryIds($entityValue, $operator, $value),
            default        => $this->compareScalar($entityValue, $operator, $value),
        };
    }

    private function resolveEntityValue(string $attribute, mixed $entity, int $storeId): string|array
    {
        if ($attribute === 'store_id') {
            return (string) $storeId;
        }

        if ($entity === null) {
            return '';
        }

        if ($attribute === 'category_ids') {
            return $this->extractCategoryIds($entity);
        }

        if (is_object($entity) && method_exists($entity, 'getData')) {
            $val = $entity->getData($attribute);
            return $val === null ? '' : (string) $val;
        }

        if (is_array($entity) && isset($entity[$attribute])) {
            return (string) $entity[$attribute];
        }

        return '';
    }

    private function extractCategoryIds(mixed $entity): array
    {
        if (!is_object($entity)) {
            return [];
        }

        if (method_exists($entity, 'getCategoryIds')) {
            $ids = $entity->getCategoryIds();
            return is_array($ids) ? array_map('strval', $ids) : [];
        }

        if (method_exists($entity, 'getData')) {
            $raw = $entity->getData('category_ids');
            if (is_array($raw)) {
                return array_map('strval', $raw);
            }
            if (is_string($raw) && $raw !== '') {
                return array_map('trim', explode(',', $raw));
            }
        }

        return [];
    }

    private function compareScalar(string|array $entityValue, string $operator, string $conditionValue): bool
    {
        if (is_array($entityValue)) {
            $entityValue = implode(',', $entityValue);
        }

        if ($operator === 'in') {
            $allowed = array_map('trim', explode(',', $conditionValue));
            return in_array($entityValue, $allowed, true);
        }

        return $entityValue === $conditionValue;
    }

    private function evaluateCategoryIds(string|array $entityCategoryIds, string $operator, string $conditionValue): bool
    {
        $required = array_map('trim', explode(',', $conditionValue));

        if (is_string($entityCategoryIds)) {
            $entityCategoryIds = $entityCategoryIds !== ''
                ? array_map('trim', explode(',', $entityCategoryIds))
                : [];
        }

        if ($entityCategoryIds === []) {
            return false;
        }

        return array_intersect($entityCategoryIds, $required) !== [];
    }
}
