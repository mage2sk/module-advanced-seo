<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule\Condition;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Panth\AdvancedSEO\Model\Rule\Condition\Attribute;
use Panth\AdvancedSEO\Model\Rule\Condition\Combine;
use Panth\AdvancedSEO\Model\Rule\Condition\Stock;
use PHPUnit\Framework\TestCase;

class CombineTest extends TestCase
{
    private function combine(?Stock $stock = null): Combine
    {
        return new Combine(
            new Attribute(),
            $stock ?? new Stock($this->createStub(StockRegistryInterface::class))
        );
    }

    private function cond(string $value): array
    {
        return ['attribute' => 'color', 'operator' => '==', 'value' => $value];
    }

    public function testEmptyCombineReturnsExpectedValue(): void
    {
        $this->assertTrue($this->combine()->evaluate([], []));
        $this->assertFalse($this->combine()->evaluate(['type' => 'combine', 'value' => 0], []));
    }

    public function testAttributeNodeDelegatesToAttribute(): void
    {
        $context = ['entity' => ['color' => 'red']];
        $this->assertTrue($this->combine()->evaluate($this->cond('red'), $context));
        $this->assertTrue($this->combine()->evaluate(
            ['type' => 'attribute', 'attribute' => '', 'value' => 'x'],
            $context
        ) === false);
    }

    public function testAllAggregator(): void
    {
        $context = ['entity' => ['color' => 'red']];
        $node = ['type' => 'combine', 'aggregator' => 'all', 'conditions' => [$this->cond('red'), $this->cond('red')]];
        $this->assertTrue($this->combine()->evaluate($node, $context));

        $node['conditions'][] = $this->cond('blue');
        $this->assertFalse($this->combine()->evaluate($node, $context));

        $node['value'] = false;
        $this->assertTrue($this->combine()->evaluate($node, $context));
    }

    public function testAnyAggregator(): void
    {
        $context = ['entity' => ['color' => 'red']];
        $node = ['type' => 'any', 'conditions' => [$this->cond('blue'), $this->cond('red')]];
        $this->assertTrue($this->combine()->evaluate($node, $context));

        $node['conditions'] = [$this->cond('blue')];
        $this->assertFalse($this->combine()->evaluate($node, $context));

        $node['value'] = false;
        $this->assertTrue($this->combine()->evaluate($node, $context));
    }

    public function testTypeAllUsesAllAggregatorByDefault(): void
    {
        $context = ['entity' => ['color' => 'red']];
        $node = ['type' => 'ALL', 'conditions' => [$this->cond('red'), $this->cond('blue')]];
        $this->assertFalse($this->combine()->evaluate($node, $context));
    }

    public function testNestedCombine(): void
    {
        $context = ['entity' => ['color' => 'red']];
        $node = [
            'aggregator' => 'all',
            'conditions' => [
                ['type' => 'combine', 'aggregator' => 'any', 'conditions' => [$this->cond('x'), $this->cond('red')]],
            ],
        ];
        $this->assertTrue($this->combine()->evaluate($node, $context));
    }

    public function testStockNodeDelegatesToStock(): void
    {
        $stock = $this->createMock(Stock::class);
        $stock->expects($this->once())->method('evaluate')->willReturn(true);

        $this->assertTrue($this->combine($stock)->evaluate(['type' => 'stock'], []));
    }

    public function testUnknownTypeReturnsFalse(): void
    {
        $this->assertFalse($this->combine()->evaluate(['type' => 'mystery'], []));
    }
}
