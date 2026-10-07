<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule\Condition;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Rule\Condition\Stock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockTest extends TestCase
{
    private function item(bool $inStock, float $qty): StockItemInterface
    {
        $item = $this->createStub(StockItemInterface::class);
        $item->method('getIsInStock')->willReturn($inStock);
        $item->method('getQty')->willReturn($qty);
        return $item;
    }

    public function testNoEntityReturnsFalse(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->expects($this->never())->method('getStockItem');

        $this->assertFalse((new Stock($registry))->evaluate([], []));
        $this->assertFalse((new Stock($registry))->evaluate([], ['entity' => []]));
    }

    public function testObjectEntityUsesSku(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->expects($this->once())->method('getStockItemBySku')->with('ABC')
            ->willReturn($this->item(true, 3));
        $registry->expects($this->never())->method('getStockItem');

        $entity = $this->createStub(Product::class);
        $entity->method('getSku')->willReturn('ABC');
        $entity->method('getId')->willReturn(5);
        $this->assertTrue((new Stock($registry))->evaluate([], ['entity' => $entity]));
    }

    public function testMagicGettersOnPlainDataObjectAreNotDetected(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->expects($this->never())->method('getStockItemBySku');
        $registry->expects($this->never())->method('getStockItem');

        $entity = new DataObject(['sku' => 'ABC', 'id' => 5]);
        $this->assertFalse((new Stock($registry))->evaluate([], ['entity' => $entity]));
    }

    public function testArrayEntityWithoutSkuUsesProductId(): void
    {
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->expects($this->once())->method('getStockItem')->with(12)
            ->willReturn($this->item(false, 0));

        $stock = new Stock($registry);
        $this->assertTrue($stock->evaluate(['check' => 'is_in_stock', 'value' => 0], ['entity' => ['entity_id' => '12']]));
    }

    public function testRegistryFailureReturnsFalse(): void
    {
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willThrowException(new \RuntimeException('x'));

        $this->assertFalse((new Stock($registry))->evaluate([], ['entity' => ['sku' => 'A']]));
    }

    public static function qtyProvider(): array
    {
        return [
            ['==', 5, true],
            ['!=', 5, false],
            ['>', 4, true],
            ['>=', 6, false],
            ['<', 6, true],
            ['<=', 4, false],
            ['~', 5, false],
        ];
    }

    #[DataProvider('qtyProvider')]
    public function testQtyOperators(string $operator, float $value, bool $expected): void
    {
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($this->item(true, 5));

        $node = ['check' => 'qty', 'operator' => $operator, 'value' => $value];
        $this->assertSame($expected, (new Stock($registry))->evaluate($node, ['entity' => ['sku' => 'A']]));
    }

    public function testQtyDefaultOperatorIsGreaterOrEqual(): void
    {
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($this->item(true, 0));

        $this->assertTrue((new Stock($registry))->evaluate(['check' => 'qty'], ['entity' => ['sku' => 'A']]));
    }

    public function testUnknownCheckReturnsFalse(): void
    {
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturn($this->item(true, 1));

        $this->assertFalse((new Stock($registry))->evaluate(['check' => 'salable'], ['entity' => ['sku' => 'A']]));
    }
}
