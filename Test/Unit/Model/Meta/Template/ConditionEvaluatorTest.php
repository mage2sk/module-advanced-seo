<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Template;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private function cond(string $attribute, string $value, string $operator = 'eq'): array
    {
        return ['attribute' => $attribute, 'operator' => $operator, 'value' => $value];
    }

    public function testEmptyConditionsAlwaysMatch(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate([], null, 1));
        $this->assertTrue($evaluator->evaluate(['type' => 'all', 'conditions' => []], null, 1));
        $this->assertTrue($evaluator->evaluate(['type' => 'any', 'conditions' => 'oops'], null, 1));
    }

    public function testAllRequiresEveryConditionAndAnyNeedsOne(): void
    {
        $evaluator = new ConditionEvaluator();
        $entity = new DataObject(['type_id' => 'simple', 'color' => 'red']);
        $pass = $this->cond('type_id', 'simple');
        $fail = $this->cond('color', 'blue');

        $this->assertTrue($evaluator->evaluate(['type' => 'ALL', 'conditions' => [$pass, 'junk']], $entity, 1));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$pass, $fail]], $entity, 1));
        $this->assertTrue($evaluator->evaluate(['type' => 'any', 'conditions' => [$fail, $pass]], $entity, 1));
        $this->assertFalse($evaluator->evaluate(['type' => 'any', 'conditions' => [$fail]], $entity, 1));
        $this->assertFalse($evaluator->evaluate(['type' => 'other', 'conditions' => [$pass]], $entity, 1));
    }

    public function testIncompleteConditionsAreIgnored(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('', 'x'), $this->cond('a', '')]], null, 1));
    }

    public function testStoreIdAndInOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('store_id', '2')]], null, 2));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('store_id', '2')]], null, 3));
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('store_id', '1, 3', 'IN')]], null, 3));
    }

    public function testNullEntityAndArrayEntity(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('color', 'red')]], null, 1));
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('color', 'red')]], ['color' => 'red'], 1));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('color', 'red')]], ['size' => 'm'], 1));
        $this->assertFalse(
            $evaluator->evaluate(['conditions' => [$this->cond('color', 'red')]], new DataObject(), 1)
        );
    }

    public function testCategoryEntityMatchesItsOwnIdAgainstCategoryIds(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(5);

        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('category_ids', '3,5')]], $category, 1));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('category_ids', '3,4')]], $category, 1));
    }

    public function testProductCategoryIdsIntersect(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getCategoryIds')->willReturn([7, 9]);
        $none = $this->createStub(Product::class);
        $none->method('getCategoryIds')->willReturn([]);

        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate(['conditions' => [$this->cond('category_ids', '1, 9')]], $product, 1));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('category_ids', '1,2')]], $product, 1));
        $this->assertFalse($evaluator->evaluate(['conditions' => [$this->cond('category_ids', '1')]], $none, 1));
    }

    public function testCategoryIdsFromDataArrayOrCommaString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = ['conditions' => [$this->cond('category_ids', '4')]];

        $this->assertTrue($evaluator->evaluate($condition, new DataObject(['category_ids' => [3, 4]]), 1));
        $this->assertTrue($evaluator->evaluate($condition, new DataObject(['category_ids' => '2, 4']), 1));
        $this->assertFalse($evaluator->evaluate($condition, new DataObject(['category_ids' => '']), 1));
        $this->assertFalse($evaluator->evaluate($condition, ['category_ids' => '4'], 1));
    }
}
