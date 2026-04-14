<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Condition;

use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * Stock condition: matches in-stock / out-of-stock / qty thresholds.
 *
 * Node:
 * [
 *   'type' => 'stock',
 *   'check' => 'is_in_stock'|'qty',
 *   'operator' => '=='|'>'|'<'|...  (for qty only)
 *   'value' => bool|int,
 * ]
 */
class Stock
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     */
    public function evaluate(array $node, array $context): bool
    {
        $sku = null;
        $productId = null;

        $entity = $context['entity'] ?? null;
        if (is_object($entity)) {
            if (method_exists($entity, 'getSku')) {
                $sku = $entity->getSku();
            }
            if (method_exists($entity, 'getId')) {
                $productId = (int)$entity->getId();
            }
        } elseif (is_array($entity)) {
            $sku = $entity['sku'] ?? null;
            $productId = isset($entity['entity_id']) ? (int)$entity['entity_id'] : null;
        }

        if ($sku === null && $productId === null) {
            return false;
        }

        try {
            $stockItem = $sku !== null
                ? $this->stockRegistry->getStockItemBySku((string)$sku)
                : $this->stockRegistry->getStockItem((int)$productId);
        } catch (\Throwable $e) {
            return false;
        }

        $check = (string)($node['check'] ?? 'is_in_stock');
        if ($check === 'is_in_stock') {
            $expected = (bool)($node['value'] ?? true);
            return (bool)$stockItem->getIsInStock() === $expected;
        }

        if ($check === 'qty') {
            $operator = (string)($node['operator'] ?? '>=');
            $expected = (float)($node['value'] ?? 0);
            $actual = (float)$stockItem->getQty();
            return match ($operator) {
                '==' => $actual === $expected,
                '!=' => $actual !== $expected,
                '>' => $actual > $expected,
                '>=' => $actual >= $expected,
                '<' => $actual < $expected,
                '<=' => $actual <= $expected,
                default => false,
            };
        }

        return false;
    }
}
