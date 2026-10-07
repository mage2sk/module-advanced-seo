<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Cms\Page;

use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Result\Page as ResultPage;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Plugin\Cms\Page\MetadataPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MetadataPluginTest extends TestCase
{
    use PageConfigRecorder;

    private array $resolved = [];

    private array $loads = [];

    private array $warnings = [];

    private function plugin(
        array|\Throwable $meta = [],
        bool $enabled = true,
        bool $noindexNoRoute = false,
        array $identifiers = [],
        bool $loadFails = false
    ): MetadataPlugin {
        $this->resolved = [];
        $this->loads = [];
        $this->warnings = [];

        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $type, int $id, int $store) use ($meta) {
            $this->resolved[] = [$type, $id, $store];
            if ($meta instanceof \Throwable) {
                throw $meta;
            }
            return new ResolvedMeta($meta);
        });
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isNoindexNoRoute')->willReturn($noindexNoRoute);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($identifiers, $loadFails) {
            $test = $this;
            return new class ($identifiers, $loadFails, $test) extends Page {
                public function __construct(private array $ids, private bool $fails, private $test)
                {
                }

                public function load($modelId, $field = null)
                {
                    if ($this->fails) {
                        throw new \RuntimeException('db');
                    }
                    $this->test->recordLoad($modelId, $field, (int) $this->getData('store_id'));
                    $this->setData('page_id', $this->ids[$modelId] ?? null);
                    return $this;
                }
            };
        });

        return new MetadataPlugin($resolver, $storeManager, $config, $logger, $factory);
    }

    public function recordLoad($id, $field, int $storeId): void
    {
        $this->loads[] = [$id, $field, $storeId];
    }

    private function resultPage(): ResultPage
    {
        $page = $this->createStub(ResultPage::class);
        $page->method('getConfig')->willReturn($this->recordingPageConfig());
        return $page;
    }

    private function action(string $fullActionName = 'cms_page_view'): Action
    {
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($fullActionName);
        $action = $this->createStub(Action::class);
        $action->method('getRequest')->willReturn($request);
        return $action;
    }

    public function testNumericPageIdsAreResolvedDirectly(): void
    {
        $page = $this->resultPage();
        $plugin = $this->plugin(['meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'robots' => 'R']);

        $result = $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $page, $this->action(), '12');

        $this->assertSame($page, $result);
        $this->assertSame([['cms', 12, 3]], $this->resolved);
        $this->assertSame(['title' => 'T', 'description' => 'D', 'keywords' => 'K', 'robots' => 'R'], $this->applied);
        $this->assertSame([], $this->loads);
    }

    public function testIdentifiersAreLoadedOncePerStoreAndTheSuffixIsIgnored(): void
    {
        $plugin = $this->plugin(['meta_title' => 'T'], true, false, ['about-us' => 7]);
        $helper = $this->createStub(CmsPageHelper::class);

        $plugin->afterPrepareResultPage($helper, $this->resultPage(), $this->action(), 'about-us|1');
        $plugin->afterPrepareResultPage($helper, $this->resultPage(), $this->action(), 'about-us');

        $this->assertSame([['about-us', 'identifier', 3]], $this->loads);
        $this->assertSame([['cms', 7, 3], ['cms', 7, 3]], $this->resolved);
    }

    public function testUnknownOrMissingPageIdsResolveNothing(): void
    {
        $plugin = $this->plugin([], true, false, []);
        $helper = $this->createStub(CmsPageHelper::class);

        $plugin->afterPrepareResultPage($helper, $this->resultPage(), $this->action(), 'missing');
        $plugin->afterPrepareResultPage($helper, $this->resultPage(), $this->action(), null);
        $plugin->afterPrepareResultPage($helper, $this->resultPage(), $this->action(), ['x']);

        $this->assertSame([], $this->resolved);
    }

    public function testALoadFailureIsLogged(): void
    {
        $plugin = $this->plugin([], true, false, [], true);
        $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $this->resultPage(), $this->action(), 'about');

        $this->assertSame([], $this->resolved);
        $this->assertSame('Panth SEO failed loading CMS page by identifier', $this->warnings[0][0]);
        $this->assertSame(['identifier' => 'about', 'store' => 3, 'error' => 'db'], $this->warnings[0][1]);
    }

    public function testNoRoutePageIsNoindexedWhenConfigured(): void
    {
        $plugin = $this->plugin([], true, true);
        $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $this->resultPage(), $this->action('cms_noroute_index'), null);
        $this->assertSame(['robots' => 'noindex,follow'], $this->applied);

        $plugin = $this->plugin([], true, false);
        $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $this->resultPage(), $this->action('cms_noroute_index'), null);
        $this->assertSame([], $this->applied);
    }

    public function testNonPageResultsAndDisabledModulePassThrough(): void
    {
        $plugin = $this->plugin(['meta_title' => 'T']);
        $this->assertFalse($plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), false, $this->action(), 1));

        $plugin = $this->plugin(['meta_title' => 'T'], false);
        $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $this->resultPage(), $this->action(), 1);
        $this->assertSame([], $this->resolved);
    }

    public function testResolverFailuresAreLogged(): void
    {
        $plugin = $this->plugin(new \RuntimeException('boom'));
        $page = $this->resultPage();

        $this->assertSame($page, $plugin->afterPrepareResultPage($this->createStub(CmsPageHelper::class), $page, $this->action(), 1));
        $this->assertSame(['Panth SEO CMS metadata plugin failed', ['error' => 'boom']], $this->warnings[0]);
    }
}
