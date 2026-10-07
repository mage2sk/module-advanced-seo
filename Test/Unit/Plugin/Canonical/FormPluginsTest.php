<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Canonical;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Cms\Model\Page\DataProvider as CmsPageDataProvider;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Canonical\CategoryFormPlugin;
use Panth\AdvancedSEO\Plugin\Canonical\CmsPageFormPlugin;
use Panth\AdvancedSEO\Plugin\Canonical\ProductFormPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormPluginsTest extends TestCase
{
    use ConnectionRecorder;

    private function config(bool $enabled = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    public static function plugins(): array
    {
        return [
            'category' => [CategoryFormPlugin::class, CategoryDataProvider::class],
            'product'  => [ProductFormPlugin::class, ProductDataProvider::class],
            'cms'      => [CmsPageFormPlugin::class, CmsPageDataProvider::class],
        ];
    }

    #[DataProvider('plugins')]
    public function testTheCanonicalFieldsetIsAddedWhenEnabled(string $pluginClass, string $subjectClass): void
    {
        $plugin = new $pluginClass($this->recordingResource(), $this->config());
        $meta = $plugin->afterGetMeta($this->createStub($subjectClass), ['general' => []]);

        $this->assertArrayHasKey('general', $meta);
        $field = $meta['seo_canonical']['children']['custom_canonical_url']['arguments']['data']['config'];
        $this->assertSame('custom_canonical_url', $field['dataScope']);
        $this->assertSame(['validate-url' => true], $field['validation']);
        $this->assertSame('fieldset', $meta['seo_canonical']['arguments']['data']['config']['componentType']);

        $disabled = new $pluginClass($this->recordingResource(), $this->config(false));
        $this->assertSame(['general' => []], $disabled->afterGetMeta($this->createStub($subjectClass), ['general' => []]));
    }

    #[DataProvider('plugins')]
    public function testEmptyDataIsReturnedUntouched(string $pluginClass, string $subjectClass): void
    {
        $plugin = new $pluginClass($this->recordingResource(['x']), $this->config());

        $this->assertSame([], $plugin->afterGetData($this->createStub($subjectClass), []));
        $this->assertSame([], $this->db['where']);
    }

    public function testCategoryDataGetsTheStoredCanonical(): void
    {
        $plugin = new CategoryFormPlugin($this->recordingResource(['https://c.test/x', false]), $this->config());

        $result = $plugin->afterGetData($this->createStub(CategoryDataProvider::class), [
            5   => ['entity_id' => 5, 'store_id' => 2],
            6   => ['name' => 'no canonical'],
            'x' => 'not-an-array',
        ]);

        $this->assertSame('https://c.test/x', $result[5]['custom_canonical_url']);
        $this->assertArrayNotHasKey('custom_canonical_url', $result[6]);
        $this->assertContains(['source_entity_type = ?', 'category'], $this->db['where']);
        $this->assertContains(['source_entity_id = ?', 5], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 2]], $this->db['where']);
        $this->assertContains(['source_entity_id = ?', 6], $this->db['where']);
    }

    public function testProductDataGetsTheStoredCanonical(): void
    {
        $plugin = new ProductFormPlugin($this->recordingResource(['https://p.test/', '']), $this->config());

        $result = $plugin->afterGetData($this->createStub(ProductDataProvider::class), [
            9  => ['product' => ['store_id' => 1]],
            10 => ['product' => ['entity_id' => 10]],
            11 => ['no-product-key' => true],
        ]);

        $this->assertSame('https://p.test/', $result[9]['product']['custom_canonical_url']);
        $this->assertArrayNotHasKey('custom_canonical_url', $result[10]['product']);
        $this->assertContains(['source_entity_id = ?', 9], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 1]], $this->db['where']);
        $this->assertNotContains(['source_entity_id = ?', 11], $this->db['where']);
    }

    public function testCmsDataUsesTheFirstAssignedStore(): void
    {
        $plugin = new CmsPageFormPlugin($this->recordingResource(['https://cms.test/', false]), $this->config());

        $result = $plugin->afterGetData($this->createStub(CmsPageDataProvider::class), [
            3 => ['page_id' => 3, 'store_id' => [4, 5]],
            7 => ['store_id' => '2'],
        ]);

        $this->assertSame('https://cms.test/', $result[3]['custom_canonical_url']);
        $this->assertContains(['store_id IN (?)', [0, 4]], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 2]], $this->db['where']);
        $this->assertContains(['source_entity_type = ?', 'cms'], $this->db['where']);
    }
}
