<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\PageConfig;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\SeoTitleHolder;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Text\Truncator;
use Panth\AdvancedSEO\Plugin\PageConfig\HeadPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;

class HeadPluginCoverageTest extends TestCase
{
    use PageConfigRecorder;

    private array $resolved = [];

    private ?SeoTitleHolder $holder = null;

    private function plugin(
        array $registry,
        array $meta,
        array $options = []
    ): HeadPlugin {
        $this->resolved = [];
        $this->holder = new SeoTitleHolder();

        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $type, int $id, int $store) use ($meta) {
            $this->resolved[] = [$type, $id, $store];
            return new ResolvedMeta($meta);
        });

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn($options['store_name'] ?? 'Acme');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static function ($id = null) use ($store, $options) {
            if ($id !== null && ($options['store_fails'] ?? false)) {
                throw new \RuntimeException('store');
            }
            return $store;
        });

        $registryStub = $this->createStub(Registry::class);
        $registryStub->method('registry')->willReturnCallback(static fn(string $key) => $registry[$key] ?? null);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($options['enabled'] ?? true);
        $config->method('appendStoreName')->willReturn($options['append'] ?? false);
        $config->method('getTitleMaxLength')->willReturn(60);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParams')->willReturn($options['params'] ?? []);

        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($options) {
            if ($options['attributes_fail'] ?? false) {
                throw new \RuntimeException('eav');
            }
            $collection = $this->createStub(AttributeCollection::class);
            $collection->method('getIterator')->willReturn(new \ArrayIterator([new DataObject(['attribute_code' => 'color'])]));
            return $collection;
        });

        return new HeadPlugin($resolver, $storeManager, $registryStub, $config, $request, $factory, new Truncator(), $this->holder);
    }

    public function testProductMetaIsAppliedAndTheTitleIsRemembered(): void
    {
        $plugin = $this->plugin(
            ['current_product' => new DataObject(['id' => 7]), 'current_category' => new DataObject(['id' => 2])],
            ['meta_title' => 'Bottle', 'meta_description' => 'D', 'meta_keywords' => 'K']
        );

        $this->assertSame([], $plugin->beforePublicBuild($this->recordingPageConfig()));
        $this->assertSame([['product', 7, 1]], $this->resolved);
        $this->assertSame(['title' => 'Bottle', 'description' => 'D', 'keywords' => 'K'], $this->applied);
        $this->assertSame('Bottle', $this->holder->get());
    }

    public function testResolvedTitleClearsTheNativeMetaTitleSoBothTagsMatch(): void
    {
        $plugin = $this->plugin(
            ['current_product' => new DataObject(['id' => 7])],
            ['meta_title' => 'Rule Title']
        );
        $metaTitles = [];
        $title = $this->createStub(\Magento\Framework\View\Page\Title::class);
        $pageConfig = $this->createStub(\Magento\Framework\View\Page\Config::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $pageConfig->method('setMetaTitle')->willReturnCallback(function ($value) use (&$metaTitles): void {
            $metaTitles[] = $value;
        });

        $plugin->beforePublicBuild($pageConfig);

        $this->assertSame([''], $metaTitles);
    }

    public function testCategoryAndCmsPagesAreDetected(): void
    {
        $this->plugin(['current_category' => new DataObject(['id' => 2])], [])->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame([['category', 2, 1]], $this->resolved);

        $this->plugin(['cms_page' => new DataObject(['id' => 9])], [])->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame([['cms', 9, 1]], $this->resolved);

        $this->plugin(['current_product' => new DataObject([])], ['meta_title' => 'X'])->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame([], $this->resolved);
        $this->assertSame([], $this->applied);
    }

    public function testStoreNameIsAppendedUnlessAlreadyPresentOrUnavailable(): void
    {
        $this->plugin(['current_product' => new DataObject(['id' => 1])], ['meta_title' => 'Bottle'], ['append' => true])
            ->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame('Bottle - Acme', $this->applied['title']);

        $this->plugin(['current_product' => new DataObject(['id' => 1])], ['meta_title' => 'Acme Bottle'], ['append' => true])
            ->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame('Acme Bottle', $this->applied['title']);

        $this->plugin(
            ['current_product' => new DataObject(['id' => 1])],
            ['meta_title' => 'Bottle'],
            ['append' => true, 'store_fails' => true]
        )->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame('Bottle', $this->applied['title']);
    }

    public function testActiveLayeredFiltersOrDisabledModuleSkipEverything(): void
    {
        $this->plugin(['current_product' => new DataObject(['id' => 1])], ['meta_title' => 'T'], ['params' => ['color' => '5']])
            ->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame([], $this->resolved);

        $this->plugin(['current_product' => new DataObject(['id' => 1])], ['meta_title' => 'T'], ['enabled' => false])
            ->beforePublicBuild($this->recordingPageConfig());
        $this->assertSame([], $this->resolved);
    }

    public function testAttributeLookupFailureStillAppliesMeta(): void
    {
        $this->plugin(
            ['current_product' => new DataObject(['id' => 1])],
            ['meta_title' => 'T'],
            ['params' => ['color' => '5'], 'attributes_fail' => true]
        )->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame(['title' => 'T'], $this->applied);
    }
}
