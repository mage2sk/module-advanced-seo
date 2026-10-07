<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Head;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfo\Base as PriceInfo;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Block\Head\GoogleAnalytics;
use Panth\AdvancedSEO\Helper\Config;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    private function block(array $options = []): GoogleAnalytics
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('GBP');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($options['store_fails'] ?? false) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($options['list_block'] ?? false);
        $context = $this->createStub(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getLayout')->willReturn($layout);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($options['measurement_id'] ?? 'G-ABC123');
        $flags = [
            GoogleAnalytics::XML_GA4_ENABLED       => $options['ga4'] ?? true,
            GoogleAnalytics::XML_GA4_ENHANCED_ECOM => $options['ecom'] ?? true,
        ];
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn(string $path): bool => $flags[$path] ?? false);

        $registry = $this->createStub(Registry::class);
        $entities = $options['registry'] ?? [];
        $registry->method('registry')->willReturnCallback(static fn(string $key) => $entities[$key] ?? null);

        $catalogHelper = $this->createStub(CatalogHelper::class);
        $catalogHelper->method('getBreadcrumbPath')->willReturn($options['breadcrumbs'] ?? []);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($options['enabled'] ?? true);

        return new GoogleAnalytics($context, $scopeConfig, $registry, $catalogHelper, $config);
    }

    private function product(string $sku, float $finalPrice, float $fallbackPrice = 0.0, string|false $brand = false): Product
    {
        $price = $this->createStub(PriceInterface::class);
        $price->method('getValue')->willReturn($fallbackPrice);
        $priceInfo = $this->createStub(PriceInfo::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn('Name ' . $sku);
        $product->method('getFinalPrice')->willReturn($finalPrice);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getAttributeText')->willReturn($brand);
        $product->method('getData')->willReturn(null);
        return $product;
    }

    private function category(): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn('Bottles');
        $category->method('getId')->willReturn(12);
        return $category;
    }

    private static function payload(string $js): array
    {
        preg_match("/^gtag\\('event', \"([a-z_]+)\", (.*)\\);$/s", $js, $m);
        return [$m[1] ?? '', json_decode($m[2] ?? 'null', true)];
    }

    public function testMeasurementIdValidation(): void
    {
        $this->assertSame('G-ABC123', $this->block()->getMeasurementId());
        $this->assertSame('', $this->block(['measurement_id' => 'G-<script>'])->getMeasurementId());
        $this->assertSame('', $this->block(['measurement_id' => '  '])->getMeasurementId());
    }

    public function testEnabledNeedsModuleFlagAndAValidId(): void
    {
        $this->assertTrue($this->block()->isEnabled());
        $this->assertFalse($this->block(['enabled' => false])->isEnabled());
        $this->assertFalse($this->block(['ga4' => false])->isEnabled());
        $this->assertFalse($this->block(['measurement_id' => 'bad id!'])->isEnabled());
    }

    public function testProductPageEmitsViewItemWithBrandAndBreadcrumbCategory(): void
    {
        $block = $this->block([
            'registry'    => ['current_product' => $this->product('SKU-1', 19.999, 0.0, 'Acme')],
            'breadcrumbs' => [['label' => 'Home'], ['label' => 'Bottles'], ['label' => 'SKU-1']],
        ]);

        [$event, $data] = self::payload($block->getEnhancedEcommerceJs());

        $this->assertSame('view_item', $event);
        $this->assertSame('GBP', $data['currency']);
        $this->assertEquals(20.0, $data['value']);
        $this->assertSame(
            ['item_id' => 'SKU-1', 'item_name' => 'Name SKU-1', 'price' => 20, 'currency' => 'GBP', 'item_brand' => 'Acme', 'item_category' => 'Bottles'],
            $data['items'][0]
        );
    }

    public function testZeroFinalPriceFallsBackToPriceInfoAndCategoryFromRegistry(): void
    {
        $block = $this->block([
            'registry'    => ['current_product' => $this->product('SKU-2', 0.0, 7.5), 'current_category' => $this->category()],
            'store_fails' => true,
        ]);

        [, $data] = self::payload($block->getEnhancedEcommerceJs());

        $this->assertSame('USD', $data['currency']);
        $this->assertEquals(7.5, $data['items'][0]['price']);
        $this->assertSame('Bottles', $data['items'][0]['item_category']);
        $this->assertArrayNotHasKey('item_brand', $data['items'][0]);
    }

    public function testCategoryPageEmitsViewItemList(): void
    {
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getItems')->willReturn([$this->product('A', 5.0), $this->product('B', 6.0, 0.0, 'Brand')]);
        $layer = $this->createStub(Layer::class);
        $layer->method('getProductCollection')->willReturn($collection);
        $listBlock = $this->createStub(ListProduct::class);
        $listBlock->method('getLayer')->willReturn($layer);

        $block = $this->block(['registry' => ['current_category' => $this->category()], 'list_block' => $listBlock]);
        [$event, $data] = self::payload($block->getEnhancedEcommerceJs());

        $this->assertSame('view_item_list', $event);
        $this->assertSame('Bottles', $data['item_list_name']);
        $this->assertSame('category_12', $data['item_list_id']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(1, $data['items'][1]['index']);
        $this->assertSame('Brand', $data['items'][1]['item_brand']);
        $this->assertSame('Bottles', $data['items'][0]['item_category']);
    }

    public function testItemListIsCappedAtFifty(): void
    {
        $products = [];
        for ($i = 0; $i < 55; $i++) {
            $products[] = $this->product('S' . $i, 1.0);
        }
        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getItems')->willReturn($products);
        $layer = $this->createStub(Layer::class);
        $layer->method('getProductCollection')->willReturn($collection);
        $listBlock = $this->createStub(ListProduct::class);
        $listBlock->method('getLayer')->willReturn($layer);

        [, $data] = self::payload($this->block(['registry' => ['current_category' => $this->category()], 'list_block' => $listBlock])->getEnhancedEcommerceJs());

        $this->assertCount(50, $data['items']);
    }

    public function testNoEventWithoutContext(): void
    {
        $this->assertSame('', $this->block(['ecom' => false, 'registry' => ['current_product' => $this->product('X', 1.0)]])->getEnhancedEcommerceJs());
        $this->assertSame('', $this->block()->getEnhancedEcommerceJs());
        $this->assertSame('', $this->block(['registry' => ['current_category' => $this->category()]])->getEnhancedEcommerceJs());

        $collection = $this->createStub(ProductCollection::class);
        $collection->method('getItems')->willReturn([]);
        $layer = $this->createStub(Layer::class);
        $layer->method('getProductCollection')->willReturn($collection);
        $listBlock = $this->createStub(ListProduct::class);
        $listBlock->method('getLayer')->willReturn($layer);
        $this->assertSame('', $this->block(['registry' => ['current_category' => $this->category()], 'list_block' => $listBlock])->getEnhancedEcommerceJs());
    }

    public function testOutputIsSafeForInlineScripts(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('</script><x>');
        $product->method('getName')->willReturn("O'Neil & \"Co\"");
        $product->method('getFinalPrice')->willReturn(1.0);

        $js = $this->block(['registry' => ['current_product' => $product]])->getEnhancedEcommerceJs();

        $this->assertStringNotContainsString('</script>', $js);
        $this->assertStringNotContainsString("O'Neil", $js);
        $this->assertStringContainsString('\u003C', $js);
    }
}
