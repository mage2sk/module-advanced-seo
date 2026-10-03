<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\LandingPage;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Controller\Page\View as CmsPageView;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory;
use Panth\AdvancedSEO\Plugin\LandingPage\CmsPageMetaPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CmsPageMetaPluginTest extends TestCase
{
    use PageConfigRecorder;

    private array $filters = [];

    private array $rendered = [];

    private array $warnings = [];

    private function plugin(
        array $template,
        int $pageId = 9,
        bool $landing = true,
        bool $useTemplates = true,
        bool $enabled = true,
        bool $repositoryFails = false
    ): CmsPageMetaPlugin {
        $this->filters = [];
        $this->rendered = [];
        $this->warnings = [];

        $detector = $this->createStub(LandingPageDetector::class);
        $detector->method('isLandingPage')->willReturn($landing);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($template) {
            $collection = $this->createStub(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
                $this->filters[] = [$field, $value];
                return $collection;
            });
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getFirstItem')->willReturn(new DataObject($template));
            return $collection;
        });

        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(function (string $pattern, $page, array $context): string {
            $this->rendered[] = [$pattern, $context];
            return $pattern === 'EMPTY' ? '' : strtoupper($pattern);
        });
        $repository = $this->createStub(PageRepositoryInterface::class);
        if ($repositoryFails) {
            $repository->method('getById')->willThrowException(new \RuntimeException('no page'));
        } else {
            $repository->method('getById')->willReturn($this->createStub(PageInterface::class));
        }
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($k, $d = null) => $k === 'page_id' ? $pageId : $d);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('useTemplates')->willReturn($useTemplates);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        return new CmsPageMetaPlugin($detector, $factory, $renderer, $repository, $storeManager, $request, $config, $logger);
    }

    private function execute(CmsPageMetaPlugin $plugin, mixed $result = null): mixed
    {
        if ($result === null) {
            $result = $this->createStub(ResultPage::class);
            $result->method('getConfig')->willReturn($this->recordingPageConfig());
        }
        return $plugin->afterExecute($this->createStub(CmsPageView::class), $result);
    }

    public function testLandingPageTemplateIsApplied(): void
    {
        $this->execute($this->plugin([
            'id' => 1, 'meta_title' => 'title', 'meta_description' => 'desc', 'meta_keywords' => 'kw', 'robots' => 'NOINDEX',
        ]));

        $this->assertSame(['title' => 'TITLE', 'description' => 'DESC', 'keywords' => 'KW', 'robots' => 'NOINDEX'], $this->applied);
        $this->assertSame(['store_id' => 2, 'entity_type' => 'landing_page', 'entity_id' => 9], $this->rendered[0][1]);
        $this->assertContains(['entity_type', 'landing_page'], $this->filters);
        $this->assertContains(['store_id', ['in' => [2, 0]]], $this->filters);
    }

    public function testEmptyRenderingsAreNotApplied(): void
    {
        $this->execute($this->plugin(['id' => 1, 'meta_title' => 'EMPTY', 'meta_description' => 'EMPTY', 'meta_keywords' => 'EMPTY']));

        $this->assertSame([], $this->applied);
    }

    public function testNothingIsAppliedWhenPreconditionsFail(): void
    {
        $this->execute($this->plugin(['meta_title' => 't']));
        $this->execute($this->plugin(['id' => 1, 'meta_title' => 't'], 0));
        $this->execute($this->plugin(['id' => 1, 'meta_title' => 't'], 9, false));
        $this->execute($this->plugin(['id' => 1, 'meta_title' => 't'], 9, true, false));
        $this->execute($this->plugin(['id' => 1, 'meta_title' => 't'], 9, true, true, false));

        $this->assertSame([], $this->applied);
        $this->assertSame('raw', $this->execute($this->plugin(['id' => 1]), 'raw'));
    }

    public function testFailuresAreLogged(): void
    {
        $this->execute($this->plugin(['id' => 1], 9, true, true, true, true));

        $this->assertSame(['Panth SEO landing page meta plugin failed', ['error' => 'no page']], $this->warnings[0]);
    }
}
