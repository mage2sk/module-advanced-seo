<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\AdvancedSEO\Model\Meta\Token\CategoryToken;
use PHPUnit\Framework\TestCase;

class CategoryTokenTest extends TestCase
{
    private function category(string $name, int $level): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn($name);
        $category->method('getLevel')->willReturn($level);

        return $category;
    }

    private function product(mixed $ids): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getCategoryIds')->willReturn($ids);

        return $product;
    }

    public function testCategoryEntityReturnsItsName(): void
    {
        $token = new CategoryToken($this->createStub(CategoryRepositoryInterface::class));
        $this->assertSame('Bags', $token->getValue($this->category('Bags', 2), []));
    }

    public function testProductUsesFirstCategoryAtLevelTwoOrDeeper(): void
    {
        $root = $this->category('Root', 1);
        $leaf = $this->category('Jackets', 3);
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->expects($this->exactly(3))->method('get')->willReturnCallback(
            function (int $id, ?int $storeId) use ($root, $leaf) {
                $this->assertSame(3, $storeId);
                if ($id === 1) {
                    throw new NoSuchEntityException();
                }

                return $id === 2 ? $root : $leaf;
            }
        );

        $this->assertSame('Jackets', (new CategoryToken($repo))->getValue($this->product([1, 2, 5]), ['store_id' => 3]));
    }

    public function testStoreZeroIsPassedAsNull(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->expects($this->once())->method('get')->with(4, null)->willReturn($this->category('Root', 1));

        $this->assertSame('', (new CategoryToken($repo))->getValue($this->product(['4']), []));
    }

    public function testProductWithoutCategoriesAndOtherEntitiesYieldEmpty(): void
    {
        $token = new CategoryToken($this->createStub(CategoryRepositoryInterface::class));
        $this->assertSame('', $token->getValue($this->product([]), []));
        $this->assertSame('', $token->getValue($this->product(null), []));
        $this->assertSame('', $token->getValue(['name' => 'x'], []));
    }
}
