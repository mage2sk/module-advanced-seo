<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;
use Panth\AdvancedSEO\Plugin\Admin\CategorySeoFieldsPlugin;
use PHPUnit\Framework\TestCase;

class CategorySeoFieldsPluginTest extends TestCase
{
    private function plugin(bool $enabled = true): CategorySeoFieldsPlugin
    {
        $source = $this->createStub(MetaRobots::class);
        $source->method('toOptionArray')->willReturn([['value' => 'NOINDEX', 'label' => 'No index']]);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new CategorySeoFieldsPlugin($source, $config);
    }

    public function testSeoFieldsAreAddedToTheDashedGroupByDefault(): void
    {
        $meta = $this->plugin()->afterGetMeta($this->createStub(CategoryDataProvider::class), []);
        $children = $meta['search-engine-optimization']['children'];

        $this->assertSame(['meta_robots', 'og_title', 'og_description', 'og_image'], array_keys($children));
        $robots = $children['meta_robots']['arguments']['data']['config'];
        $this->assertSame('select', $robots['formElement']);
        $this->assertSame([['value' => 'NOINDEX', 'label' => 'No index']], $robots['options']);
        $this->assertSame('textarea', $children['og_description']['arguments']['data']['config']['formElement']);
    }

    public function testTheUnderscoredGroupIsUsedWhenPresent(): void
    {
        $meta = $this->plugin()->afterGetMeta(
            $this->createStub(CategoryDataProvider::class),
            ['search_engine_optimization' => ['children' => ['url_key' => []]]]
        );

        $this->assertArrayNotHasKey('search-engine-optimization', $meta);
        $this->assertArrayHasKey('url_key', $meta['search_engine_optimization']['children']);
        $this->assertArrayHasKey('og_image', $meta['search_engine_optimization']['children']);
    }

    public function testDisabledModuleLeavesTheFormAlone(): void
    {
        $this->assertSame(['a' => 1], $this->plugin(false)->afterGetMeta($this->createStub(CategoryDataProvider::class), ['a' => 1]));
    }
}
