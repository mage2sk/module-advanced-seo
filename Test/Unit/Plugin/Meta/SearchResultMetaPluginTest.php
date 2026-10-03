<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Meta;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Escaper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Plugin\Meta\SearchResultMetaPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Test\Unit\Plugin\PageConfigRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SearchResultMetaPluginTest extends TestCase
{
    use ConnectionRecorder;
    use PageConfigRecorder;

    private array $rendered = [];

    private array $warnings = [];

    private function plugin(
        array|false $template,
        string $path = '/catalogsearch/result/',
        string $query = 'red shirt',
        bool $enabled = true,
        ?\Throwable $dbFails = null
    ): SearchResultMetaPlugin {
        $this->rendered = [];
        $this->warnings = [];

        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(function (string $pattern, $entity, array $context): string {
            $this->rendered[] = [$pattern, $entity, $context];
            return $pattern === 'EMPTY' ? '' : str_replace('{{q}}', $context['search_query'], $pattern);
        });
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'q' ? $query : $default);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn($v) => htmlspecialchars((string) $v));
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        return new SearchResultMetaPlugin(
            $renderer,
            $this->recordingResource([], [], $dbFails, [$template]),
            $storeManager,
            $request,
            $config,
            $escaper,
            $logger
        );
    }

    public function testTemplateIsRenderedWithTheSearchQuery(): void
    {
        $plugin = $this->plugin(['meta_title' => 'Results for {{q}}', 'meta_description' => 'Buy {{q}}', 'robots' => 'NOINDEX,FOLLOW']);
        $pageConfig = $this->recordingPageConfig();

        $this->assertSame([], $plugin->beforePublicBuild($pageConfig));
        $this->assertSame(
            ['title' => 'Results for red shirt', 'description' => 'Buy red shirt', 'robots' => 'NOINDEX,FOLLOW'],
            $this->applied
        );
        $this->assertSame(['store_id' => 2, 'search_query' => 'red shirt'], $this->rendered[0][2]);
        $this->assertNull($this->rendered[0][1]);
        $this->assertContains(['entity_type = ?', 'search'], $this->db['where']);
        $this->assertContains(['store_id IN (?)', [0, 2]], $this->db['where']);
    }

    public function testItOnlyRunsOncePerRequest(): void
    {
        $plugin = $this->plugin(['meta_title' => 'T']);
        $plugin->beforePublicBuild($this->recordingPageConfig());
        $plugin->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame([], $this->applied);
        $this->assertCount(1, $this->rendered);
    }

    public function testWithoutATemplateADefaultDescriptionIsUsed(): void
    {
        $this->plugin(false, '/catalogsearch/result/', '<b>shoes</b>')->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame(
            ['description' => 'Find &lt;b&gt;shoes&lt;/b&gt; and related products in our store. Browse our full selection.'],
            $this->applied
        );
    }

    public function testEmptyRenderingsFallBackToTheDefaultDescription(): void
    {
        $this->plugin(['meta_title' => 'EMPTY', 'meta_description' => 'EMPTY'], '/catalogsearch/result/', '  ')
            ->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame(['description' => 'Browse our full selection of products in our store.'], $this->applied);
    }

    public function testLongQueriesAreTruncated(): void
    {
        $this->plugin(false, '/catalogsearch/result/', str_repeat('a', 100))->beforePublicBuild($this->recordingPageConfig());

        $this->assertStringContainsString('Find ' . str_repeat('a', 80) . ' and related', $this->applied['description']);
    }

    public function testNonSearchPagesAndDisabledModuleAreSkipped(): void
    {
        $this->plugin(['meta_title' => 'T'], '/shirts.html')->beforePublicBuild($this->recordingPageConfig());
        $this->plugin(['meta_title' => 'T'], '')->beforePublicBuild($this->recordingPageConfig());
        $this->plugin(['meta_title' => 'T'], '/catalogsearch/result/', 'x', false)->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame([], $this->applied);
    }

    public function testFailuresAreLogged(): void
    {
        $this->plugin([], '/catalogsearch/result/', 'q', true, new \RuntimeException('db'))
            ->beforePublicBuild($this->recordingPageConfig());

        $this->assertSame(['Panth SEO search result meta plugin failed', ['error' => 'db']], $this->warnings[0]);
    }
}
