<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Text\Truncator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoogleMerchantFeedBuilderTest extends TestCase
{
    private const NS = 'http://base.google.com/ns/1.0';

    private array $collectionCalls = [];

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

    private function gallery(array $files): DataCollection
    {
        $collection = new DataCollection($this->createStub(EntityFactoryInterface::class));
        foreach ($files as $i => $file) {
            $collection->addItem(new DataObject(['id' => $i + 1] + $file));
        }
        return $collection;
    }

    private function config(array $values = []): Config
    {
        $values += [
            'getBrandAttribute' => '',
            'getGtinAttribute' => '',
            'getMpnAttribute' => '',
            'getMerchantFeedGoogleCategoryAttribute' => '',
            'isMerchantFeedIncludeOutOfStock' => true,
            'getMerchantFeedDefaultCondition' => 'new',
            'getDefaultBrand' => '',
            'getMerchantFeedShippingCountry' => '',
            'getMerchantFeedShippingPrice' => '',
        ];
        $config = $this->createStub(Config::class);
        foreach ($values as $method => $value) {
            $config->method($method)->willReturn($value);
        }
        return $config;
    }

    private function builder(
        array $products,
        Config $config,
        ?StockRegistryInterface $stock = null,
        ?LoggerInterface $logger = null,
        ?GalleryReadHandler $gallery = null,
        ?ConfigurableResource $configurable = null,
        ?CategoryRepositoryInterface $categories = null,
        mixed $parentSku = false
    ): GoogleMerchantFeedBuilder {
        $collection = $this->createStub(ProductCollection::class);
        foreach ([
            'setStoreId', 'addStoreFilter', 'addAttributeToFilter', 'addAttributeToSelect', 'addUrlRewrite',
            'addFinalPrice', 'setPageSize', 'setCurPage', 'joinField', 'addFieldToFilter',
        ] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($method, $collection) {
                $this->collectionCalls[] = [$method, $args];
                return $collection;
            });
        }
        $collection->method('getItems')->willReturn($products);

        $factory = $this->createStub(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('Main');
        $store->method('getBaseUrl')->willReturnCallback(
            static fn ($type = 'link') => $type === 'media' ? 'https://shop.test/media/' : 'https://shop.test/'
        );
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            static fn ($date = null) => new \DateTime($date ?? '2026-06-15 12:00:00', new \DateTimeZone('UTC'))
        );

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchOne')->willReturn($parentSku);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        if ($stock === null) {
            $item = $this->createStub(StockItemInterface::class);
            $item->method('getIsInStock')->willReturn(true);
            $stock = $this->createStub(StockRegistryInterface::class);
            $stock->method('getStockItemBySku')->willReturn($item);
        }

        return new GoogleMerchantFeedBuilder(
            $factory,
            $stock,
            $storeManager,
            $categories ?? $this->createStub(CategoryRepositoryInterface::class),
            $gallery ?? $this->createStub(GalleryReadHandler::class),
            $configurable ?? $this->createStub(ConfigurableResource::class),
            $this->createStub(CurrencyFactory::class),
            $resource,
            $timezone,
            $config,
            $logger ?? $this->createStub(LoggerInterface::class),
            new Truncator()
        );
    }

    private function parse(string $xml): \SimpleXMLElement
    {
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        return $doc;
    }

    public function testFullProductItem(): void
    {
        $stockItem = $this->createStub(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockItem->method('getBackorders')->willReturn(1);
        $stockItem->method('getQty')->willReturn(0.0);
        $stock = $this->createStub(StockRegistryInterface::class);
        $stock->method('getStockItemBySku')->willReturn($stockItem);

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturnCallback(function (int $id) {
            $map = [10 => ['1/2/10', 'Men'], 11 => ['1/2/10/11', 'Boots']];
            $category = $this->createStub(Category::class);
            $category->method('getPath')->willReturn($map[$id][0]);
            $category->method('getName')->willReturn($map[$id][1]);
            return $category;
        });

        $configurable = $this->createStub(ConfigurableResource::class);
        $configurable->method('getParentIdsByChild')->willReturn([55]);

        $images = [['file' => '/b/base.jpg'], ['file' => ''], ['value' => '/v/alt.jpg']];
        for ($i = 0; $i < 12; $i++) {
            $images[] = ['file' => '/g/' . $i . '.jpg'];
        }

        $product = $this->product(
            [
                'sku' => 'BOOT-1',
                'description' => '<p>Strong &amp; <b>warm</b></p>',
                'image' => '/b/base.jpg',
                'price' => '100',
                'special_price' => '80',
                'special_from_date' => '2026-01-01 00:00:00',
                'special_to_date' => '2026-12-31 00:00:00',
                'brand_code' => '7',
                'ean' => '1234567890123',
                'mpn_code' => 'MP-1',
                'gcat' => 'Apparel > Shoes',
            ],
            [
                'getName' => 'Boot',
                'getProductUrl' => 'https://shop.test/boot.html',
                'getFinalPrice' => 80.0,
                'getMediaGalleryImages' => $this->gallery($images),
                'getAttributeText' => null,
                'getCategoryIds' => [11, 10],
                'getTypeId' => 'simple',
                'getId' => 5,
            ]
        );

        $config = $this->config([
            'getBrandAttribute' => 'brand_code',
            'getGtinAttribute' => 'ean',
            'getMpnAttribute' => 'mpn_code',
            'getMerchantFeedGoogleCategoryAttribute' => 'gcat',
            'isMerchantFeedIncludeOutOfStock' => false,
            'getMerchantFeedDefaultCondition' => 'refurbished',
            'getMerchantFeedShippingCountry' => 'US',
            'getMerchantFeedShippingPrice' => '4.5',
        ]);

        $xml = $this->parse(
            $this->builder([$product], $config, $stock, null, null, $configurable, $categories, 'PARENT-SKU')->build(1)
        );

        $this->assertSame('Main', (string) $xml->channel->title);
        $this->assertSame('Google Shopping product feed for Main', (string) $xml->channel->description);
        $g = $xml->channel->item[0]->children(self::NS);

        $this->assertSame('BOOT-1', (string) $g->id);
        $this->assertSame('Boot', (string) $g->title);
        $this->assertSame('Strong & warm', (string) $g->description);
        $this->assertSame('https://shop.test/boot.html', (string) $g->link);
        $this->assertSame('https://shop.test/media/catalog/product/b/base.jpg', (string) $g->image_link);
        $this->assertCount(10, $g->additional_image_link);
        $this->assertSame('https://shop.test/media/catalog/product/v/alt.jpg', (string) $g->additional_image_link[0]);
        $this->assertSame('100.00 USD', (string) $g->price);
        $this->assertSame('80.00 USD', (string) $g->sale_price);
        $this->assertSame('2026-01-01T00:00+0000/2026-12-31T00:00+0000', (string) $g->sale_price_effective_date);
        $this->assertSame('backorder', (string) $g->availability);
        $this->assertSame('7', (string) $g->brand);
        $this->assertSame('1234567890123', (string) $g->gtin);
        $this->assertSame('MP-1', (string) $g->mpn);
        $this->assertSame('refurbished', (string) $g->condition);
        $this->assertSame('Men > Boots', (string) $g->product_type);
        $this->assertSame('Apparel > Shoes', (string) $g->google_product_category);
        $this->assertSame('US', (string) $g->shipping->country);
        $this->assertSame('4.50 USD', (string) $g->shipping->price);
        $this->assertSame('PARENT-SKU', (string) $g->item_group_id);
        $this->assertSame('true', (string) $g->identifier_exists);

        $methods = array_column($this->collectionCalls, 0);
        $this->assertSame(2, count(array_keys($methods, 'joinField', true)));
        $this->assertContains('addFieldToFilter', $methods);
        $selected = array_values(array_filter($this->collectionCalls, static fn ($c) => $c[0] === 'addAttributeToSelect'));
        $this->assertSame(['brand_code', 'ean', 'mpn_code', 'gcat'], array_map(static fn ($c) => $c[1][0], array_slice($selected, 1)));
    }

    public function testMinimalProductItem(): void
    {
        $stock = $this->createStub(StockRegistryInterface::class);
        $stock->method('getStockItemBySku')->willThrowException(new \RuntimeException('no stock'));

        $gallery = $this->createStub(GalleryReadHandler::class);
        $gallery->method('execute')->willThrowException(new \RuntimeException('gallery'));

        $product = $this->product(
            ['sku' => 'MIN', 'short_description' => '', 'image' => 'no_selection', 'price' => '10', 'special_price' => '12'],
            ['getName' => 'Minimal', 'getProductUrl' => '', 'getFinalPrice' => 10.0, 'getCategoryIds' => [], 'getTypeId' => 'configurable']
        );

        $xml = $this->parse($this->builder([$product], $this->config(), $stock, null, $gallery)->build(1));
        $g = $xml->channel->item[0]->children(self::NS);

        $this->assertSame('MIN', (string) $g->id);
        $this->assertCount(0, $g->description);
        $this->assertCount(0, $g->link);
        $this->assertCount(0, $g->image_link);
        $this->assertCount(0, $g->additional_image_link);
        $this->assertSame('10.00 USD', (string) $g->price);
        $this->assertCount(0, $g->sale_price);
        $this->assertSame('out_of_stock', (string) $g->availability);
        $this->assertCount(0, $g->brand);
        $this->assertCount(0, $g->shipping);
        $this->assertCount(0, $g->item_group_id);
        $this->assertSame('new', (string) $g->condition);
        $this->assertSame('false', (string) $g->identifier_exists);
        $this->assertNotContains('joinField', array_column($this->collectionCalls, 0));
    }

    public function testDefaultBrandAndInStockAndExpiredSale(): void
    {
        $stockItem = $this->createStub(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockItem->method('getBackorders')->willReturn(0);
        $stock = $this->createStub(StockRegistryInterface::class);
        $stock->method('getStockItemBySku')->willReturn($stockItem);

        $product = $this->product(
            [
                'sku' => 'S',
                'short_description' => 'Short text',
                'description' => 'Long text',
                'manufacturer' => '3',
                'price' => '20',
                'special_price' => '15',
                'special_to_date' => '2026-01-01',
            ],
            [
                'getName' => 'S',
                'getFinalPrice' => 20.0,
                'getAttributeText' => ['Acme', ''],
                'getMediaGalleryImages' => $this->gallery([]),
                'getTypeId' => 'simple',
                'getId' => 9,
            ]
        );

        $xml = $this->parse($this->builder([$product], $this->config(['getDefaultBrand' => 'House']), $stock)->build(1));
        $g = $xml->channel->item[0]->children(self::NS);

        $this->assertSame('Short text', (string) $g->description);
        $this->assertSame('in_stock', (string) $g->availability);
        $this->assertSame('Acme', (string) $g->brand);
        $this->assertSame('20.00 USD', (string) $g->price);
        $this->assertCount(0, $g->sale_price);
        $this->assertCount(0, $g->item_group_id);

        $noBrand = $this->product(['sku' => 'NB', 'price' => '5', 'special_price' => '4', 'special_from_date' => '2026-01-01'], [
            'getName' => 'NB',
            'getFinalPrice' => 4.0,
        ]);
        $xml = $this->parse($this->builder([$noBrand], $this->config(['getDefaultBrand' => 'House']), $stock)->build(1));
        $g = $xml->channel->item[0]->children(self::NS);
        $this->assertSame('House', (string) $g->brand);
        $this->assertSame('4.00 USD', (string) $g->sale_price);
        $this->assertCount(0, $g->sale_price_effective_date);
        $this->assertSame('true', (string) $g->identifier_exists);
    }

    public function testFailingProductIsSkippedAndFeedStaysWellFormed(): void
    {
        $bad = $this->product(['sku' => 'BAD'], ['getName' => new \RuntimeException('name broke')]);
        $good = $this->product(['sku' => 'GOOD', 'price' => '1'], ['getName' => 'Good', 'getFinalPrice' => 1.0]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO Google Feed: failed to write product SKU "BAD": name broke');

        $output = $this->builder([$bad, $good], $this->config(), null, $logger)->build(1);

        $this->assertStringNotContainsString('<g:id>BAD</g:id>', $output);
        $xml = $this->parse($output);
        $this->assertCount(1, $xml->channel->item);
        $this->assertCount(0, $xml->channel->item[0]->item);
        $this->assertSame('GOOD', (string) $xml->channel->item[0]->children(self::NS)->id);
    }

    public function testSalePriceUsesTheSpecialPrice(): void
    {
        $product = $this->product(
            ['sku' => 'SP', 'price' => '100', 'special_price' => '80'],
            ['getName' => 'SP', 'getFinalPrice' => 70.0]
        );

        $xml = $this->parse($this->builder([$product], $this->config())->build(1));
        $g = $xml->channel->item[0]->children(self::NS);

        $this->assertSame('100.00 USD', (string) $g->price);
        $this->assertSame('80.00 USD', (string) $g->sale_price);
    }

    public function testBuildToFileWritesFeed(): void
    {
        $dir = sys_get_temp_dir() . '/panth_seo_gmc_' . uniqid('', true);
        $path = $dir . '/sub/google.xml';

        $product = $this->product(['sku' => 'F', 'price' => '3'], ['getName' => 'File', 'getFinalPrice' => 3.0]);

        try {
            $result = $this->builder([$product], $this->config())->buildToFile(1, $path);
            $this->assertSame($path, $result);
            $xml = simplexml_load_file($path);
            $this->assertNotFalse($xml);
            $this->assertSame('F', (string) $xml->channel->item[0]->children(self::NS)->id);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($dir . '/sub')) {
                rmdir($dir . '/sub');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
