<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Catalog\Product;

use Magento\Catalog\Block\Product\View as ProductView;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Plugin\Catalog\Product\MetadataPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataPluginTest extends TestCase
{
    use PageConfigRecorder;

    private array $resolved = [];

    private array $warnings = [];

    private function plugin(?DataObject $product, array|\Throwable $meta, bool $enabled = true): MetadataPlugin
    {
        $this->resolved = [];
        $this->warnings = [];

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
            static fn(string $key) => $key === 'current_product' ? $product : null
        );
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        return new MetadataPlugin($resolver, $this->recordingPageConfig(), $registry, $storeManager, $config, $logger);
    }

    private function setLayout(MetadataPlugin $plugin): mixed
    {
        return $plugin->aroundSetLayout(
            $this->createStub(ProductView::class),
            static fn($layout) => 'proceeded',
            $this->createStub(LayoutInterface::class)
        );
    }

    public function testResolvedMetaIsAppliedAfterTheLayoutIsSet(): void
    {
        $plugin = $this->plugin(new DataObject(['id' => 8]), [
            'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'robots' => 'NOINDEX',
        ]);

        $this->assertSame('proceeded', $this->setLayout($plugin));
        $this->assertSame([['product', 8, 2]], $this->resolved);
        $this->assertSame(['title' => 'T', 'description' => 'D', 'keywords' => 'K', 'robots' => 'NOINDEX'], $this->applied);
    }

    public function testEmptyValuesAreNotApplied(): void
    {
        $this->setLayout($this->plugin(new DataObject(['id' => 8]), ['meta_title' => '', 'meta_description' => 'D']));

        $this->assertSame(['description' => 'D'], $this->applied);
    }

    public function testNothingIsResolvedWithoutAProductOrWhenDisabled(): void
    {
        $this->setLayout($this->plugin(null, []));
        $this->assertSame([], $this->resolved);

        $this->setLayout($this->plugin(new DataObject([]), []));
        $this->assertSame([], $this->resolved);

        $this->setLayout($this->plugin(new DataObject(['id' => 1]), [], false));
        $this->assertSame([], $this->resolved);
    }

    public function testResolverFailuresAreLogged(): void
    {
        $this->assertSame('proceeded', $this->setLayout($this->plugin(new DataObject(['id' => 8]), new \RuntimeException('x'))));
        $this->assertSame(['Panth SEO product metadata plugin failed', ['error' => 'x']], $this->warnings[0]);
    }

    public function testAfterToHtmlReturnsTheHtmlUnchanged(): void
    {
        $plugin = $this->plugin(null, []);

        $this->assertSame('<div/>', $plugin->afterToHtml($this->createStub(ProductView::class), '<div/>'));
    }
}
