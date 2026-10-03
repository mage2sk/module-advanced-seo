<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Catalog\Category;

use Magento\Catalog\Block\Category\View as CategoryView;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Plugin\Catalog\Category\MetadataPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataPluginTest extends TestCase
{
    use PageConfigRecorder;

    private array $resolved = [];

    private array $warnings = [];

    private int $attributeLoads = 0;

    private function plugin(
        ?DataObject $category,
        array|\Throwable $meta,
        array $params = [],
        bool $enabled = true,
        bool $attributesFail = false
    ): MetadataPlugin {
        $this->resolved = [];
        $this->warnings = [];
        $this->attributeLoads = 0;

        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $type, int $id, int $store) use ($meta) {
            $this->resolved[] = [$type, $id, $store];
            if ($meta instanceof \Throwable) {
                throw $meta;
            }
            return new ResolvedMeta($meta);
        });
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(
            static fn(string $key) => $key === 'current_category' ? $category : null
        );
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParams')->willReturn($params);

        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($attributesFail) {
            $this->attributeLoads++;
            if ($attributesFail) {
                throw new \RuntimeException('eav');
            }
            $collection = $this->createStub(AttributeCollection::class);
            $collection->method('getIterator')->willReturn(new \ArrayIterator([
                new DataObject(['attribute_code' => 'color']),
                new DataObject(['attribute_code' => 'size']),
            ]));
            return $collection;
        });

        return new MetadataPlugin(
            $resolver,
            $this->recordingPageConfig(),
            $registry,
            $storeManager,
            $config,
            $logger,
            $request,
            $factory
        );
    }

    private function setLayout(MetadataPlugin $plugin): mixed
    {
        return $plugin->aroundSetLayout(
            $this->createStub(CategoryView::class),
            static fn($layout) => 'layout-set',
            $this->createStub(LayoutInterface::class)
        );
    }

    public function testResolvedCategoryMetaIsApplied(): void
    {
        $plugin = $this->plugin(new DataObject(['id' => 3]), [
            'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'robots' => 'INDEX',
        ], ['p' => '2', 'color' => '']);

        $this->assertSame('layout-set', $this->setLayout($plugin));
        $this->assertSame([['category', 3, 1]], $this->resolved);
        $this->assertSame(['title' => 'T', 'description' => 'D', 'keywords' => 'K', 'robots' => 'INDEX'], $this->applied);
    }

    public function testLayeredNavigationFiltersSkipTheOverride(): void
    {
        $plugin = $this->plugin(new DataObject(['id' => 3]), ['meta_title' => 'T'], ['color' => '12']);

        $this->setLayout($plugin);
        $this->setLayout($plugin);

        $this->assertSame([], $this->resolved);
        $this->assertSame(1, $this->attributeLoads);
    }

    public function testAttributeLookupFailureMeansNoFilters(): void
    {
        $plugin = $this->plugin(new DataObject(['id' => 3]), ['meta_title' => 'T'], ['color' => '12'], true, true);

        $this->setLayout($plugin);

        $this->assertSame(['title' => 'T'], $this->applied);
    }

    public function testDisabledOrMissingCategoryDoesNothing(): void
    {
        $this->setLayout($this->plugin(new DataObject(['id' => 3]), [], [], false));
        $this->assertSame([], $this->resolved);

        $this->setLayout($this->plugin(null, []));
        $this->assertSame([], $this->resolved);
    }

    public function testFailuresAreLogged(): void
    {
        $this->setLayout($this->plugin(new DataObject(['id' => 3]), new \RuntimeException('resolver')));

        $this->assertSame(['Panth SEO category metadata plugin failed', ['error' => 'resolver']], $this->warnings[0]);
    }
}
