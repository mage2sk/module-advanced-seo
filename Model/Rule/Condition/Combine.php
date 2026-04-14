<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Condition;

/**
 * Combine condition: AND/OR of nested conditions or leaf conditions.
 *
 * Node structure:
 * [
 *   'type' => 'combine',
 *   'aggregator' => 'all'|'any',
 *   'value' => true|false,   // true = match, false = negate
 *   'conditions' => [ ... child nodes ... ],
 * ]
 *
 * Leaf nodes have 'type' => 'attribute'|'stock'|... and are delegated.
 */
class Combine
{
    public function __construct(
        private readonly Attribute $attributeCondition,
        private readonly Stock $stockCondition
    ) {
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     */
    public function evaluate(array $node, array $context): bool
    {
        // If node has 'attribute' key, it's a leaf condition — evaluate directly
        if (isset($node['attribute']) && $node['attribute'] !== '') {
            return $this->attributeCondition->evaluate($node, $context);
        }

        $type = strtolower((string)($node['type'] ?? 'combine'));

        // Support both formats: {"type":"combine"} and {"type":"all"/"any"}
        if ($type === 'combine' || $type === 'all' || $type === 'any') {
            // aggregator can come from 'aggregator' field or from 'type' itself (all/any)
            $aggregator = (string)($node['aggregator'] ?? ($type !== 'combine' ? $type : 'all'));
            $expected = (bool)($node['value'] ?? true);
            $children = (array)($node['conditions'] ?? []);

            if ($children === []) {
                return $expected;
            }

            if ($aggregator === 'all') {
                foreach ($children as $child) {
                    if (!$this->evaluate((array)$child, $context)) {
                        return !$expected;
                    }
                }
                return $expected;
            }

            // any
            foreach ($children as $child) {
                if ($this->evaluate((array)$child, $context)) {
                    return $expected;
                }
            }
            return !$expected;
        }

        if ($type === 'attribute') {
            return $this->attributeCondition->evaluate($node, $context);
        }

        if ($type === 'stock') {
            return $this->stockCondition->evaluate($node, $context);
        }

        return false;
    }
}
