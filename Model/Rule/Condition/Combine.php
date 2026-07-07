<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Condition;

class Combine
{
    public function __construct(
        private readonly Attribute $attributeCondition,
        private readonly Stock $stockCondition
    ) {
    }

    public function evaluate(array $node, array $context): bool
    {
        if (isset($node['attribute']) && $node['attribute'] !== '') {
            return $this->attributeCondition->evaluate($node, $context);
        }

        $type = strtolower((string)($node['type'] ?? 'combine'));

        if ($type === 'combine' || $type === 'all' || $type === 'any') {
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
