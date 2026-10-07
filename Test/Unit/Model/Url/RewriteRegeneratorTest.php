<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Url;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Panth\AdvancedSEO\Model\Url\RewriteRegenerator;
use PHPUnit\Framework\TestCase;

class RewriteRegeneratorTest extends TestCase
{
    private function product(int $id): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    private function category(int $id): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        return $category;
    }

    public function testRegenerateProductsForSingleStoreWithIds(): void
    {
        $items = [$this->product(1), $this->product(2)];

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects($this->once())->method('setStoreId')->with(3);
        $collection->expects($this->once())->method('addStoreFilter')->with(3);
        $collection->expects($this->once())->method('addIdFilter')->with([1, 2]);
        $collection->expects($this->once())->method('setPageSize')->with(500);
        $collection->method('getLastPageNumber')->willReturn(1);
        $collection->expects($this->once())->method('setCurPage')->with(1);
        $collection->expects($this->once())->method('clear');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $generator = $this->createStub(ProductUrlRewriteGenerator::class);
        $generator->method('generate')->willReturnOnConsecutiveCalls(['u1', 'u2'], []);

        $persist = $this->createMock(UrlPersistInterface::class);
        $persist->expects($this->exactly(2))->method('deleteByData');
        $persist->expects($this->once())->method('replace')->with(['u1', 'u2']);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('setCurrentStore')->with(3);
        $storeManager->expects($this->never())->method('getStores');

        $regenerator = new RewriteRegenerator(
            $generator,
            $this->createStub(CategoryUrlRewriteGenerator::class),
            $persist,
            $factory,
            $this->createStub(CategoryCollectionFactory::class),
            $storeManager
        );

        $this->assertSame(2, $regenerator->regenerateProducts(3, [1, 2]));
    }

    public function testRegenerateProductsAllStoresWithoutIds(): void
    {
        $store1 = $this->createStub(StoreInterface::class);
        $store1->method('getId')->willReturn(1);
        $store2 = $this->createStub(StoreInterface::class);
        $store2->method('getId')->willReturn(2);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store1, $store2]);
        $storeManager->expects($this->exactly(2))->method('setCurrentStore');

        $collection = $this->createMock(ProductCollection::class);
        $collection->expects($this->never())->method('addIdFilter');
        $collection->method('getLastPageNumber')->willReturn(2);
        $collection->expects($this->exactly(4))->method('setCurPage');
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator([$this->product(5)]));

        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $generator = $this->createStub(ProductUrlRewriteGenerator::class);
        $generator->method('generate')->willReturn(['u']);

        $regenerator = new RewriteRegenerator(
            $generator,
            $this->createStub(CategoryUrlRewriteGenerator::class),
            $this->createStub(UrlPersistInterface::class),
            $factory,
            $this->createStub(CategoryCollectionFactory::class),
            $storeManager
        );

        $this->assertSame(4, $regenerator->regenerateProducts(0));
    }

    public function testRegenerateCategories(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->expects($this->once())->method('addAttributeToFilter')->with('level', ['gt' => 1]);
        $collection->expects($this->once())->method('addIdFilter')->with([9]);
        $collection->method('getLastPageNumber')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$this->category(9)]));

        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $generator = $this->createStub(CategoryUrlRewriteGenerator::class);
        $generator->method('generate')->willReturn(['a', 'b', 'c']);

        $persist = $this->createMock(UrlPersistInterface::class);
        $persist->expects($this->once())->method('deleteByData')->with([
            'entity_id' => 9,
            'entity_type' => 'category',
            'store_id' => 1,
        ]);
        $persist->expects($this->once())->method('replace')->with(['a', 'b', 'c']);

        $regenerator = new RewriteRegenerator(
            $this->createStub(ProductUrlRewriteGenerator::class),
            $generator,
            $persist,
            $this->createStub(ProductCollectionFactory::class),
            $factory,
            $this->createStub(StoreManagerInterface::class)
        );

        $this->assertSame(3, $regenerator->regenerateCategories(1, [9]));
    }

    public function testRegenerateCategoriesNoResults(): void
    {
        $collection = $this->createStub(CategoryCollection::class);
        $collection->method('getLastPageNumber')->willReturn(1);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $persist = $this->createMock(UrlPersistInterface::class);
        $persist->expects($this->never())->method('replace');

        $regenerator = new RewriteRegenerator(
            $this->createStub(ProductUrlRewriteGenerator::class),
            $this->createStub(CategoryUrlRewriteGenerator::class),
            $persist,
            $this->createStub(ProductCollectionFactory::class),
            $factory,
            $this->createStub(StoreManagerInterface::class)
        );

        $this->assertSame(0, $regenerator->regenerateCategories(2));
    }
}
