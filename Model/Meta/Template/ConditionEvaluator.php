<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Template;

/**
 * Evaluates JSON condition trees against catalog/CMS entities.
 *
 * Condition format (stored in `panth_seo_template.conditions_serialized`):
 *
 *   {
 *     "type": "all",            // "all" = AND, "any" = OR
 *     "conditions": [
 *       {"attribute": "attribute_set_id", "operator": "in", "value": "4,9"},
 *       {"attribute": "category_ids",     "operator": "in", "value": "3,5,10"},
 *       {"attribute": "store_id",         "operator": "eq", "value": "1"},
 *       {"attribute": "type_id",          "operator": "eq", "value": "simple"},
 *       {"attribute": "visibility",       "operator": "in", "value": "2,4"}
 *     ]
 *   }
 *
 * Empty/null conditions match everything (returns true).
 */
class ConditionEvaluator
{
    /**
     * Evaluate a condition tree against the given entity.
     *
     * @param array<string,mixed> $conditions Decoded JSON condition tree
     * @param mixed               $entity     Product, category, CMS page, or DataObject
     * @param int                  $storeId    Current store context
     */
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

        // "all" mode: every condition passed => true
        // "any" mode: no condition passed   => false
        return $type === 'all';
    }

    /**
     * Evaluate a single leaf condition.
     *
     * @param array<string,mixed> $condition
     */
    private function evaluateSingle(array $condition, mixed $entity, int $storeId): bool
    {
        $attribute = (string) ($condition['attribute'] ?? '');
        $operator  = strtolower((string) ($condition['operator'] ?? 'eq'));
        $value     = (string) ($condition['value'] ?? '');

        if ($attribute === '' || $value === '') {
            return true;
        }

        // For category_ids on a category entity, match by entity_id instead
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

    /**
     * Extract the comparable value from the entity for a given attribute.
     *
     * @return string|list<string>
     */
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

        // Generic DataObject / AbstractModel resolution
        if (is_object($entity) && method_exists($entity, 'getData')) {
            $val = $entity->getData($attribute);
            return $val === null ? '' : (string) $val;
        }

        if (is_array($entity) && isset($entity[$attribute])) {
            return (string) $entity[$attribute];
        }

        return '';
    }

    /**
     * Extract category IDs from a product entity.
     *
     * @return list<string>
     */
    private function extractCategoryIds(mixed $entity): array
    {
        if (!is_object($entity)) {
            return [];
        }

        // Magento\Catalog\Model\Product::getCategoryIds() returns int[]
        if (method_exists($entity, 'getCategoryIds')) {
            $ids = $entity->getCategoryIds();
            return is_array($ids) ? array_map('strval', $ids) : [];
        }

        // Fallback: some models store it as comma-separated in data
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

    /**
     * Compare a scalar entity value against the condition value.
     */
    private function compareScalar(string|array $entityValue, string $operator, string $conditionValue): bool
    {
        if (is_array($entityValue)) {
            $entityValue = implode(',', $entityValue);
        }

        if ($operator === 'in') {
            $allowed = array_map('trim', explode(',', $conditionValue));
            return in_array($entityValue, $allowed, true);
        }

        // "eq" / default equality
        return $entityValue === $conditionValue;
    }

    /**
     * Evaluate category_ids: entity must belong to at least one of the listed categories.
     *
     * @param string|list<string> $entityCategoryIds
     */
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

        // "in" / "eq": entity is in at least one of the listed categories
        return array_intersect($entityCategoryIds, $required) !== [];
    }
}
