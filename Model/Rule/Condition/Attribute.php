<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Condition;

/**
 * Evaluates attribute conditions against a product/category context.
 *
 * Node:
 * [
 *   'type' => 'attribute',
 *   'attribute' => 'sku'|'price'|'category_ids'|'custom',
 *   'operator' => '=='|'!='|'>'|'>='|'<'|'<='|'contains'|'ncontains'|'in'|'nin'|'empty'|'nempty'|'regex',
 *   'value' => mixed,
 * ]
 */
class Attribute
{
    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     */
    public function evaluate(array $node, array $context): bool
    {
        $attribute = (string)($node['attribute'] ?? '');
        $operator = (string)($node['operator'] ?? '==');
        $expected = $node['value'] ?? null;

        if ($attribute === '') {
            return false;
        }

        // Resolve entity from context: try 'entity', 'product', 'category', 'page'
        $entity = $context['entity'] ?? $context['product'] ?? $context['category'] ?? $context['page'] ?? null;

        // Special handling for category_ids on products
        if ($attribute === 'category_ids' && is_object($entity) && method_exists($entity, 'getCategoryIds')) {
            $actual = $entity->getCategoryIds();
        } elseif (is_object($entity) && method_exists($entity, 'getData')) {
            $actual = $entity->getData($attribute);
        } elseif (is_array($entity)) {
            $actual = $entity[$attribute] ?? null;
        } else {
            $actual = $context[$attribute] ?? null;
        }

        return $this->compare($actual, $operator, $expected);
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        switch ($operator) {
            case '==':
                return $this->scalar($actual) === $this->scalar($expected);
            case '!=':
                return $this->scalar($actual) !== $this->scalar($expected);
            case '>':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected;
            case '>=':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual >= (float)$expected;
            case '<':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected;
            case '<=':
                return is_numeric($actual) && is_numeric($expected) && (float)$actual <= (float)$expected;
            case 'contains':
                return is_string($actual) && is_string($expected) && $expected !== '' && str_contains($actual, $expected);
            case 'ncontains':
                return !(is_string($actual) && is_string($expected) && $expected !== '' && str_contains($actual, $expected));
            case 'in':
                $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
                $haystack = is_array($actual) ? $actual : [$actual];
                foreach ($haystack as $h) {
                    if (in_array((string)$h, array_map('strval', $list), true)) {
                        return true;
                    }
                }
                return false;
            case 'nin':
                $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
                $haystack = is_array($actual) ? $actual : [$actual];
                foreach ($haystack as $h) {
                    if (in_array((string)$h, array_map('strval', $list), true)) {
                        return false;
                    }
                }
                return true;
            case 'empty':
                return $actual === null || $actual === '' || $actual === [] || $actual === '0';
            case 'nempty':
                return !($actual === null || $actual === '' || $actual === []);
            case 'regex':
                if (!is_string($expected) || $expected === '') {
                    return false;
                }
                if (!is_string($actual)) {
                    return false;
                }
                try {
                    return preg_match($expected, $actual) === 1;
                } catch (\Throwable) {
                    return false;
                }
        }
        return false;
    }

    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v)) {
            return implode(',', array_map('strval', $v));
        }
        return (string)$v;
    }
}
