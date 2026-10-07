<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Brand;

use Magento\Catalog\Controller\Category\View as CategoryViewController;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Plugin\Brand\BrandMetaPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BrandMetaPluginTest extends TestCase
{
    use ConnectionRecorder;
    use PageConfigRecorder;

    private array $rendered = [];

    private array $warnings = [];

    private function plugin(
        array|false $template,
        ?string $brand = 'Acme',
        bool $isBrandPage = true,
        ?DataObject $category = null,
        bool $enabled = true,
        ?\Throwable $renderFails = null
    ): BrandMetaPlugin {
        $this->rendered = [];
        $this->warnings = [];

        $detector = $this->createStub(BrandDetector::class);
        $detector->method('isBrandPage')->willReturn($isBrandPage);
        $detector->method('getCurrentBrand')->willReturn($brand);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(function (string $pattern, $entity, array $context) use ($renderFails): string {
            if ($renderFails !== null) {
                throw $renderFails;
            }
            $this->rendered[] = [$pattern, $entity, $context];
            return str_replace('{{brand}}', $context['brand_name'], $pattern);
        });
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturn($category ?? new DataObject(['id' => 4]));
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        return new BrandMetaPlugin(
            $detector,
            $config,
            $renderer,
            $this->recordingPageConfig(),
            $registry,
            $storeManager,
            $this->recordingResource([], [], null, [$template]),
            $logger
        );
    }

    private function execute(BrandMetaPlugin $plugin): ?ResultInterface
    {
        $controller = $this->createStub(CategoryViewController::class);
        $controller->method('getRequest')->willReturn($this->createStub(RequestInterface::class));
        $result = $this->createStub(ResultInterface::class);
        $this->assertSame($result, $plugin->afterExecute($controller, $result));
        return $result;
    }

    public function testBrandTemplateIsRenderedOntoThePage(): void
    {
        $this->execute($this->plugin([
            'meta_title' => '{{brand}} products', 'meta_description' => 'All {{brand}}',
            'meta_keywords' => '{{brand}}', 'robots' => ' INDEX,FOLLOW ',
        ]));

        $this->assertSame(
            ['title' => 'Acme products', 'description' => 'All Acme', 'keywords' => 'Acme', 'robots' => 'INDEX,FOLLOW'],
            $this->applied
        );
        $this->assertSame(['store_id' => 1, 'brand_name' => 'Acme'], $this->rendered[0][2]);
        $this->assertContains(['entity_type = ?', 'brand'], $this->db['where']);
    }

    public function testBlankTemplateFieldsAreSkipped(): void
    {
        $this->execute($this->plugin(['meta_title' => '  ', 'meta_description' => 'D']));

        $this->assertSame(['description' => 'D'], $this->applied);
    }

    public function testNothingIsAppliedWhenPreconditionsFail(): void
    {
        $this->execute($this->plugin(false));
        $this->execute($this->plugin(['meta_title' => 'T'], null));
        $this->execute($this->plugin(['meta_title' => 'T'], ''));
        $this->execute($this->plugin(['meta_title' => 'T'], 'Acme', false));
        $this->execute($this->plugin(['meta_title' => 'T'], 'Acme', true, new DataObject([])));
        $this->execute($this->plugin(['meta_title' => 'T'], 'Acme', true, null, false));

        $this->assertSame([], $this->applied);
    }

    public function testFailuresAreLogged(): void
    {
        $this->execute($this->plugin(['meta_title' => 'T'], 'Acme', true, null, true, new \RuntimeException('render')));

        $this->assertSame(['Panth SEO BrandMetaPlugin failed', ['error' => 'render']], $this->warnings[0]);
    }
}
