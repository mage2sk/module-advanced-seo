<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Admin\SerpPreviewPlugin;
use PHPUnit\Framework\TestCase;

class SerpPreviewPluginTest extends TestCase
{
    private function plugin(bool $enabled): SerpPreviewPlugin
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new SerpPreviewPlugin($storeManager, $config);
    }

    public function testThePreviewComponentIsAddedToTheSeoFieldset(): void
    {
        $result = $this->plugin(true)->afterGetMeta($this->createStub(ProductDataProvider::class), ['other' => []]);

        $config = $result['search-engine-optimization']['children']['panth_seo_serp_preview']['arguments']['data']['config'];
        $this->assertSame('https://shop.test', $config['baseUrl']);
        $this->assertSame('product', $config['entityType']);
        $this->assertSame('Panth_AdvancedSEO/js/serp-preview-component', $config['component']);
        $this->assertSame(60, $config['titleMaxChars']);
        $this->assertArrayHasKey('other', $result);
    }

    public function testDisabledModuleLeavesMetaUntouched(): void
    {
        $this->assertSame(['a' => 1], $this->plugin(false)->afterGetMeta($this->createStub(ProductDataProvider::class), ['a' => 1]));
    }
}
