<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Feed\FieldResolver;
use Panth\AdvancedSEO\Model\Text\Truncator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FieldResolverTest extends TestCase
{
    private function product(array $data, array $methods = []): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $data[$key] ?? null
        );
        $product->method('getSku')->willReturn($data['sku'] ?? 'SKU');
        foreach ($methods as $name => $value) {
            if ($value instanceof \Throwable) {
                $product->method($name)->willThrowException($value);
            } else {
                $product->method($name)->willReturn($value);
            }
        }
        return $product;
    }

    private function resolver(
        ?StockRegistryInterface $stock = null,
        ?StoreManagerInterface $storeManager = null,
        ?CategoryRepositoryInterface $categories = null,
        ?LoggerInterface $logger = null
    ): FieldResolver {
        if ($storeManager === null) {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn('https://shop.test/media/');
            $store->method('getCurrentCurrencyCode')->willReturn('EUR');
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store);
        }

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            static fn ($date = null) => new \DateTime($date ?? '2026-06-15 12:00:00')
        );

        return new FieldResolver(
            $stock ?? $this->createStub(StockRegistryInterface::class),
            $storeManager,
            $categories ?? $this->createStub(CategoryRepositoryInterface::class),
            $timezone,
            $logger ?? $this->createStub(LoggerInterface::class),
            new Truncator()
        );
    }

    private function resolve(array $config, Product $product, ?FieldResolver $resolver = null, ?Product $parent = null): string
    {
        return ($resolver ?? $this->resolver())->resolve($config, $product, 1, $parent);
    }

    public function testStaticAndUnknownSourceTypes(): void
    {
        $product = $this->product([]);
        $this->assertSame('new', $this->resolve(['source_type' => 'static', 'source_value' => 'new'], $product));
        $this->assertSame('', $this->resolve(['source_type' => 'mystery', 'source_value' => 'x'], $product));
        $this->assertSame('fallback', $this->resolve(
            ['source_type' => 'mystery', 'default_value' => 'fallback'],
            $product
        ));
    }

    public function testAttributeResolution(): void
    {
        $product = $this->product(
            ['name' => 'Boot', 'color' => '5', 'size' => '1,2', 'empty' => '', 'flag' => false, 'mpn' => 'M1'],
            ['getAttributeText' => null]
        );
        $this->assertSame('Boot', $this->resolve(['source_type' => 'attribute', 'source_value' => 'name'], $product));
        $this->assertSame('', $this->resolve(['source_type' => 'attribute', 'source_value' => ''], $product));
        $this->assertSame('', $this->resolve(['source_type' => 'attribute', 'source_value' => 'empty'], $product));
        $this->assertSame('', $this->resolve(['source_type' => 'attribute', 'source_value' => 'flag'], $product));
        $this->assertSame('d', $this->resolve(
            ['source_type' => 'attribute', 'source_value' => 'missing', 'default_value' => 'd'],
            $product
        ));
    }

    public function testAttributeOptionTextUsed(): void
    {
        $product = $this->createStub(Product::class);
        $values = ['color' => '5', 'size' => '1,2', 'other' => '9'];
        $product->method('getData')->willReturnCallback(static fn ($key = '') => $values[$key] ?? null);
        $texts = ['color' => 'Red', 'size' => ['S', '', 'M'], 'other' => ['', null]];
        $product->method('getAttributeText')->willReturnCallback(static fn ($code) => $texts[$code]);

        $this->assertSame('Red', $this->resolve(['source_type' => 'attribute', 'source_value' => 'color'], $product));
        $this->assertSame('S, M', $this->resolve(['source_type' => 'attribute', 'source_value' => 'size'], $product));
        $this->assertSame('9', $this->resolve(['source_type' => 'attribute', 'source_value' => 'other'], $product));
    }

    public function testAttributeTextFailureFallsBackToRawValue(): void
    {
        $product = $this->product(['code' => 'X1'], ['getAttributeText' => new \RuntimeException('no source')]);
        $this->assertSame('X1', $this->resolve(['source_type' => 'attribute', 'source_value' => 'code'], $product));
    }

    public function testDescriptionIsStrippedAndDecoded(): void
    {
        $product = $this->product(['description' => "<p>Big &amp; <b>bold</b></p>\n\n<br>&quot;boot&quot;</p>"]);
        $this->assertSame(
            'Big & bold "boot"',
            $this->resolve(['source_type' => 'attribute', 'source_value' => 'description'], $product)
        );
    }

    public function testParentAttributePrefersParentValue(): void
    {
        $child = $this->product(['brand' => 'ChildBrand'], ['getAttributeText' => null]);
        $parent = $this->product(['brand' => 'ParentBrand'], ['getAttributeText' => null]);
        $emptyParent = $this->product([], ['getAttributeText' => null]);

        $config = ['source_type' => 'parent_attribute', 'source_value' => 'brand'];
        $this->assertSame('ParentBrand', $this->resolve($config, $child, null, $parent));
        $this->assertSame('ChildBrand', $this->resolve($config, $child, null, $emptyParent));
        $this->assertSame('ChildBrand', $this->resolve($config, $child));
    }

    public function testTemplateProductUrl(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'product_url'];
        $this->assertSame('https://shop.test/p.html', $this->resolve(
            $config,
            $this->product([], ['getProductUrl' => 'https://shop.test/p.html'])
        ));
        $this->assertSame('', $this->resolve($config, $this->product([], ['getProductUrl' => new \RuntimeException()])));
        $this->assertSame('', $this->resolve(['source_type' => 'template', 'source_value' => 'nope'], $this->product([])));
    }

    public function testTemplateImageUrl(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'product_image_url'];
        $this->assertSame(
            'https://shop.test/media/catalog/product/a/b/ab.jpg',
            $this->resolve($config, $this->product(['image' => '/a/b/ab.jpg']))
        );
        $this->assertSame('', $this->resolve($config, $this->product(['image' => 'no_selection'])));
        $this->assertSame('', $this->resolve($config, $this->product([])));

        $failingStores = $this->createStub(StoreManagerInterface::class);
        $failingStores->method('getStore')->willThrowException(new \RuntimeException());
        $this->assertSame('', $this->resolve($config, $this->product(['image' => '/a.jpg']), $this->resolver(null, $failingStores)));
    }

    public function testTemplatePrice(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'product_price'];
        $this->assertSame('19.50 EUR', $this->resolve($config, $this->product([], ['getFinalPrice' => '19.5'])));

        $failingStores = $this->createStub(StoreManagerInterface::class);
        $failingStores->method('getStore')->willThrowException(new \RuntimeException());
        $this->assertSame('', $this->resolve($config, $this->product([]), $this->resolver(null, $failingStores)));
    }

    public function testTemplateSpecialPrice(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'product_special_price'];

        $this->assertSame('8.00 EUR', $this->resolve($config, $this->product(['price' => 10, 'special_price' => '8'])));
        $this->assertSame('', $this->resolve($config, $this->product(['price' => 10])));
        $this->assertSame('', $this->resolve($config, $this->product(['price' => 10, 'special_price' => '0'])));
        $this->assertSame('', $this->resolve($config, $this->product(['price' => 10, 'special_price' => '12'])));
        $this->assertSame('7.00 EUR', $this->resolve($config, $this->product([
            'price' => 10,
            'special_price' => '7',
            'special_from_date' => '2026-01-01',
            'special_to_date' => '2026-12-31',
        ])));
        $this->assertSame('', $this->resolve($config, $this->product([
            'price' => 10,
            'special_price' => '7',
            'special_from_date' => '2027-01-01',
        ])));
        $this->assertSame('', $this->resolve($config, $this->product([
            'price' => 10,
            'special_price' => '7',
            'special_to_date' => '2026-01-01',
        ])));
    }

    public function testTemplateStockStatus(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'stock_status'];

        $inStock = $this->createStub(StockItemInterface::class);
        $inStock->method('getIsInStock')->willReturn(true);
        $outStock = $this->createStub(StockItemInterface::class);
        $outStock->method('getIsInStock')->willReturn(false);

        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->willReturnCallback(
            static fn ($sku) => $sku === 'IN' ? $inStock : $outStock
        );
        $resolver = $this->resolver($registry);

        $this->assertSame('in_stock', $this->resolve($config, $this->product(['sku' => 'IN']), $resolver));
        $this->assertSame('out_of_stock', $this->resolve($config, $this->product(['sku' => 'OUT']), $resolver));

        $failing = $this->createStub(StockRegistryInterface::class);
        $failing->method('getStockItemBySku')->willThrowException(new \RuntimeException());
        $this->assertSame('out_of_stock', $this->resolve($config, $this->product([]), $this->resolver($failing)));
    }

    public function testTemplateCategoryPathPicksDeepestCategory(): void
    {
        $cats = [
            10 => ['path' => '1/2/10', 'name' => 'Men'],
            11 => ['path' => '1/2/10/11', 'name' => 'Shoes'],
            12 => ['path' => '1/2', 'name' => 'Default'],
            13 => ['path' => '1/2/10/13', 'name' => ''],
        ];
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturnCallback(function (int $id) use ($cats) {
            if (!isset($cats[$id])) {
                throw new \RuntimeException('missing');
            }
            $category = $this->createStub(Category::class);
            $category->method('getPath')->willReturn($cats[$id]['path']);
            $category->method('getName')->willReturn($cats[$id]['name']);
            return $category;
        });

        $config = ['source_type' => 'template', 'source_value' => 'category_path'];
        $resolver = $this->resolver(null, null, $categories);

        $this->assertSame('Men > Shoes', $this->resolve(
            $config,
            $this->product([], ['getCategoryIds' => [12, 99, 10, 11, 13]]),
            $resolver
        ));
        $this->assertSame('', $this->resolve($config, $this->product([], ['getCategoryIds' => []]), $resolver));
        $this->assertSame('', $this->resolve($config, $this->product([], ['getCategoryIds' => [12]]), $resolver));
    }

    public function testTemplateWeight(): void
    {
        $config = ['source_type' => 'template', 'source_value' => 'product_weight'];
        $this->assertSame('1.25 lbs', $this->resolve($config, $this->product(['weight' => '1.25'])));
        $this->assertSame('', $this->resolve($config, $this->product(['weight' => '0'])));
        $this->assertSame('', $this->resolve($config, $this->product([])));
    }

    public function testResolutionErrorIsLoggedAndDefaultUsed(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willThrowException(new \RuntimeException('broken'));
        $product->method('getSku')->willReturn('ERR');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            'Panth SEO Feed FieldResolver: failed to resolve field "g:title" for SKU "ERR": broken'
        );

        $result = $this->resolver(null, null, null, $logger)->resolve(
            ['feed_field' => 'g:title', 'source_type' => 'attribute', 'source_value' => 'name', 'default_value' => 'N/A'],
            $product,
            1
        );

        $this->assertSame('N/A', $result);
    }
}
