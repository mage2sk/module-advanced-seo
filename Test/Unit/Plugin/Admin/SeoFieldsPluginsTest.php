<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Cms\Model\Page\DataProvider as CmsPageDataProvider;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;
use Panth\AdvancedSEO\Plugin\Admin\CmsPageSeoFieldsPlugin;
use Panth\AdvancedSEO\Plugin\Admin\ProductSeoFieldsPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;

class SeoFieldsPluginsTest extends TestCase
{
    use ConnectionRecorder;

    private function source(): MetaRobots
    {
        $source = $this->createStub(MetaRobots::class);
        $source->method('toOptionArray')->willReturn([['value' => 'INDEX,FOLLOW', 'label' => 'Index']]);
        return $source;
    }

    private function config(bool $enabled = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    public function testCmsFormGetsASeoFieldset(): void
    {
        $plugin = new CmsPageSeoFieldsPlugin($this->source(), $this->recordingResource(), $this->config());
        $meta = $plugin->afterGetMeta($this->createStub(CmsPageDataProvider::class), []);

        $children = $meta['search_engine_optimisation']['children'];
        $this->assertSame(['meta_robots', 'hreflang_identifier'], array_keys($children));
        $this->assertSame([['value' => 'INDEX,FOLLOW', 'label' => 'Index']], $children['meta_robots']['arguments']['data']['config']['options']);

        $disabled = new CmsPageSeoFieldsPlugin($this->source(), $this->recordingResource(), $this->config(false));
        $this->assertSame([], $disabled->afterGetMeta($this->createStub(CmsPageDataProvider::class), []));
    }

    public function testCmsDataIsFilledFromTheOverrideRow(): void
    {
        $resource = $this->recordingResource([], [], null, [
            ['robots' => 'NOINDEX,FOLLOW', 'hreflang_identifier' => 'about'],
            ['robots' => '', 'hreflang_identifier' => null],
            false,
        ]);
        $plugin = new CmsPageSeoFieldsPlugin($this->source(), $resource, $this->config());

        $result = $plugin->afterGetData($this->createStub(CmsPageDataProvider::class), [
            1 => ['page_id' => 1, 'store_id' => [3]],
            2 => ['store_id' => 4],
            5 => ['title' => 'none'],
            9 => 'scalar',
        ]);

        $this->assertSame('NOINDEX,FOLLOW', $result[1]['meta_robots']);
        $this->assertSame('about', $result[1]['hreflang_identifier']);
        $this->assertArrayNotHasKey('meta_robots', $result[2]);
        $this->assertArrayNotHasKey('meta_robots', $result[5]);
        $this->assertContains(['store_id IN (?)', [0, 3]], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 4]], $this->db['where']);
        $this->assertContains(['entity_type = ?', 'cms_page'], $this->db['where']);
    }

    public function testCmsDataIsUntouchedWhenDisabledOrEmpty(): void
    {
        $plugin = new CmsPageSeoFieldsPlugin($this->source(), $this->recordingResource(), $this->config(false));
        $this->assertSame([1 => ['page_id' => 1]], $plugin->afterGetData($this->createStub(CmsPageDataProvider::class), [1 => ['page_id' => 1]]));

        $plugin = new CmsPageSeoFieldsPlugin($this->source(), $this->recordingResource(), $this->config());
        $this->assertSame([], $plugin->afterGetData($this->createStub(CmsPageDataProvider::class), []));
        $this->assertSame([], $this->db['where']);
    }

    private function productPlugin(array $fetchOne = [], bool $enabled = true, int $store = 0): ProductSeoFieldsPlugin
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'store' ? $store : $default);

        return new ProductSeoFieldsPlugin($this->source(), $this->recordingResource($fetchOne), $this->config($enabled), $request);
    }

    public function testProductFormGetsSeoFields(): void
    {
        $meta = $this->productPlugin()->afterGetMeta($this->createStub(ProductDataProvider::class), []);

        $children = $meta['search-engine-optimization']['children'];
        $this->assertSame(['meta_robots', 'custom_canonical_url', 'og_title', 'og_description', 'og_image'], array_keys($children));
        $this->assertSame(['validate-url' => true], $children['custom_canonical_url']['arguments']['data']['config']['validation']);

        $this->assertSame(['x' => 1], $this->productPlugin([], false)->afterGetMeta($this->createStub(ProductDataProvider::class), ['x' => 1]));
    }

    public function testProductDataGetsTheCanonicalUsingTheRequestStoreAsFallback(): void
    {
        $plugin = $this->productPlugin(['https://p.test/', false], true, 6);

        $result = $plugin->afterGetData($this->createStub(ProductDataProvider::class), [
            4 => ['product' => ['entity_id' => 4]],
            5 => ['product' => ['store_id' => 2]],
            6 => ['other' => true],
        ]);

        $this->assertSame('https://p.test/', $result[4]['product']['custom_canonical_url']);
        $this->assertArrayNotHasKey('custom_canonical_url', $result[5]['product']);
        $this->assertContains(['store_id IN (?)', [0, 6]], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 2]], $this->db['where']);

        $this->assertSame([], $this->productPlugin()->afterGetData($this->createStub(ProductDataProvider::class), []));
        $this->assertSame([1 => []], $this->productPlugin([], false)->afterGetData($this->createStub(ProductDataProvider::class), [1 => []]));
    }
}
