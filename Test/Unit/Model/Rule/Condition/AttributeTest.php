<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule\Condition;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Rule\Condition\Attribute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeTest extends TestCase
{
    public function testEmptyAttributeReturnsFalse(): void
    {
        $this->assertFalse((new Attribute())->evaluate(['attribute' => '', 'value' => ''], ['x' => '']));
    }

    public static function operatorProvider(): array
    {
        return [
            'eq true' => ['==', 'red', 'red', true],
            'eq false' => ['==', 'red', 'blue', false],
            'eq bool' => ['==', true, '1', true],
            'eq array' => ['==', ['a', 'b'], 'a,b', true],
            'neq' => ['!=', 'red', 'blue', true],
            'gt' => ['>', '10', '5', true],
            'gt non numeric' => ['>', 'abc', '5', false],
            'gte' => ['>=', 5, 5, true],
            'lt' => ['<', 2, 5, true],
            'lte' => ['<=', 6, 5, false],
            'contains' => ['contains', 'red shoes', 'shoe', true],
            'contains empty needle' => ['contains', 'red', '', false],
            'ncontains' => ['ncontains', 'red shoes', 'blue', true],
            'ncontains hit' => ['ncontains', 'red shoes', 'red', false],
            'in csv' => ['in', 'b', 'a, b ,c', true],
            'in array actual' => ['in', [3, 4], [4], true],
            'in miss' => ['in', 'z', 'a,b', false],
            'nin miss' => ['nin', 'z', 'a,b', true],
            'nin hit' => ['nin', 'a', ['a'], false],
            'empty null' => ['empty', null, null, true],
            'empty zero string' => ['empty', '0', null, true],
            'empty value' => ['empty', 'x', null, false],
            'nempty' => ['nempty', 'x', null, true],
            'nempty empty array' => ['nempty', [], null, false],
            'regex' => ['regex', 'SKU-123', '/^SKU-\d+$/', true],
            'regex empty pattern' => ['regex', 'SKU', '', false],
            'regex non string actual' => ['regex', 5, '/5/', false],
            'unknown' => ['between', 1, 1, false],
        ];
    }

    #[DataProvider('operatorProvider')]
    public function testOperators(string $operator, mixed $actual, mixed $expected, bool $result): void
    {
        $node = ['attribute' => 'color', 'operator' => $operator, 'value' => $expected];
        $context = ['entity' => ['color' => $actual]];

        $this->assertSame($result, (new Attribute())->evaluate($node, $context));
    }

    public function testInvalidRegexReturnsFalse(): void
    {
        $node = ['attribute' => 'color', 'operator' => 'regex', 'value' => '/[unclosed/'];
        $context = ['entity' => ['color' => 'red']];

        set_error_handler(static fn (): bool => true);
        try {
            $result = (new Attribute())->evaluate($node, $context);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
    }

    public function testDefaultOperatorIsEquality(): void
    {
        $this->assertTrue((new Attribute())->evaluate(
            ['attribute' => 'sku', 'value' => 'A'],
            ['product' => new DataObject(['sku' => 'A'])]
        ));
    }

    public function testCategoryIdsUsesGetterOnEntity(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getCategoryIds')->willReturn([3, 8]);

        $this->assertTrue((new Attribute())->evaluate(
            ['attribute' => 'category_ids', 'operator' => 'in', 'value' => '8'],
            ['entity' => $product]
        ));
    }

    public function testFallsBackToContextKeysWhenNoEntity(): void
    {
        $this->assertTrue((new Attribute())->evaluate(
            ['attribute' => 'store_id', 'operator' => '==', 'value' => '2'],
            ['store_id' => 2]
        ));
    }

    public function testCategoryAndPageKeysUsedAsEntity(): void
    {
        $attribute = new Attribute();
        $this->assertTrue($attribute->evaluate(
            ['attribute' => 'name', 'value' => 'Men'],
            ['category' => new DataObject(['name' => 'Men'])]
        ));
        $this->assertTrue($attribute->evaluate(
            ['attribute' => 'title', 'value' => 'About'],
            ['page' => ['title' => 'About']]
        ));
    }

    public function testZeroStringIsEmptyAndNotNotEmpty(): void
    {
        $attribute = new Attribute();
        $context = ['entity' => ['flag' => '0']];

        $this->assertTrue($attribute->evaluate(['attribute' => 'flag', 'operator' => 'empty'], $context));
        $this->assertFalse($attribute->evaluate(['attribute' => 'flag', 'operator' => 'nempty'], $context));
    }
}
