<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Meta;

use Magento\Catalog\Block\Product\ProductList\Toolbar;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State;
use Magento\Framework\Data\Collection;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Result\Layout as LayoutResult;
use Magento\Framework\View\Result\Page;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Meta\RelPrevNextPlugin;
use PHPUnit\Framework\TestCase;

class RelPrevNextPluginTest extends TestCase
{
    private ?string $body = null;

    private function plugin(
        string $currentUrl = 'https://shop.test/shirts.html?color=red&p=2#top',
        int|string $page = 2,
        string $action = 'catalog_category_view',
        string $position = 'suffix',
        string $area = 'frontend',
        bool $enabled = true,
        ?RequestInterface $request = null
    ): RelPrevNextPlugin {
        if ($request === null) {
            $request = $this->createStub(HttpRequest::class);
            $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'p' ? $page : $default);
            $request->method('getFullActionName')->willReturn($action);
        }
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($position);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getCurrentUrl')->willReturn($currentUrl);
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new RelPrevNextPlugin($request, $scopeConfig, $url, $state, $config);
    }

    private function page(?int $lastPage, bool $withCollection = true, bool $throws = false): Page
    {
        $blocks = [new \stdClass()];
        if ($lastPage !== null) {
            $toolbar = $this->createStub(Toolbar::class);
            $toolbar->method('getCollection')->willReturn($withCollection ? $this->createStub(Collection::class) : null);
            if ($throws) {
                $toolbar->method('getLastPageNum')->willThrowException(new \RuntimeException('no collection'));
            } else {
                $toolbar->method('getLastPageNum')->willReturn($lastPage);
            }
            $blocks[] = $toolbar;
        }
        $layout = $this->createStub(Layout::class);
        $layout->method('getAllBlocks')->willReturn($blocks);
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        return $page;
    }

    private function response(mixed $body): HttpResponse
    {
        $this->body = null;
        $response = $this->createStub(HttpResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('setBody')->willReturnCallback(function ($value) use ($response) {
            $this->body = $value;
            return $response;
        });
        return $response;
    }

    public function testBuildLinksForMiddleFirstAndLastPages(): void
    {
        $plugin = $this->plugin();

        $this->assertSame(
            '<link rel="prev" href="https://shop.test/shirts.html?color=red#top" />' . "\n"
            . '<link rel="next" href="https://shop.test/shirts.html?color=red&amp;p=3#top" />' . "\n",
            $plugin->buildLinks(2, 5)
        );
        $this->assertStringNotContainsString('rel="prev"', $plugin->buildLinks(1, 5));
        $this->assertStringNotContainsString('rel="next"', $plugin->buildLinks(5, 5));
        $this->assertSame('', $plugin->buildLinks(1, 1));
        $this->assertSame('', $plugin->buildLinks(6, 5));
        $this->assertSame('', $plugin->buildLinks(0, 5));
    }

    public function testTrackingParametersAreNotCarriedIntoPageLinks(): void
    {
        $plugin = $this->plugin('https://shop.test/shirts.html?utm_source=mail&color=red&gclid=abc&p=2');

        $this->assertSame(
            '<link rel="prev" href="https://shop.test/shirts.html?color=red" />' . "\n"
            . '<link rel="next" href="https://shop.test/shirts.html?color=red&amp;p=3" />' . "\n",
            $plugin->buildLinks(2, 5)
        );
    }

    public function testPortAndMissingPathAreKept(): void
    {
        $plugin = $this->plugin('http://shop.test:8080');

        $this->assertSame('<link rel="next" href="http://shop.test:8080/?p=2" />' . "\n", $plugin->buildLinks(1, 2));
    }

    public function testLinksAreInjectedBeforeTheClosingHead(): void
    {
        $response = $this->response('<html><head><title>x</title></head><body></body></html>');

        $result = $this->plugin()->afterRenderResult($this->page(4), 'res', $response);

        $this->assertSame('res', $result);
        $this->assertStringContainsString('<title>x</title><link rel="prev"', $this->body);
        $this->assertStringContainsString('p=3#top" />' . "\n" . '</head>', $this->body);
    }

    public function testNothingIsInjectedWithoutAToolbarOrHead(): void
    {
        $this->plugin()->afterRenderResult($this->page(null), 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin()->afterRenderResult($this->page(4, false), 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin()->afterRenderResult($this->page(4, true, true), 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin()->afterRenderResult($this->page(4), 'r', $this->response('<html>no head</html>'));
        $this->assertNull($this->body);

        $this->plugin()->afterRenderResult($this->page(4), 'r', $this->response(null));
        $this->assertNull($this->body);

        $this->plugin('https://shop.test/', 1)->afterRenderResult($this->page(1), 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);
    }

    public function testIneligibleRequestsAreSkipped(): void
    {
        $page = $this->page(4);

        $this->plugin('https://shop.test/', 2, 'catalog_product_view')->afterRenderResult($page, 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin('https://shop.test/', 2, 'catalog_category_view', 'none')->afterRenderResult($page, 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin('https://shop.test/', 2, 'catalog_category_view', 'suffix', 'adminhtml')
            ->afterRenderResult($page, 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin('https://shop.test/', 2, 'catalog_category_view', 'suffix', 'frontend', false)
            ->afterRenderResult($page, 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->plugin()->afterRenderResult($this->createStub(LayoutResult::class), 'r', $this->response('<head></head>'));
        $this->assertNull($this->body);

        $this->assertSame('r', $this->plugin()->afterRenderResult($page, 'r', $this->createStub(ResponseInterface::class)));
    }

    public function testNonHttpRequestsUseRouteParts(): void
    {
        $request = new class implements RequestInterface {
            public function getModuleName()
            {
                return 'catalogsearch';
            }

            public function setModuleName($name)
            {
                return $this;
            }

            public function getControllerName()
            {
                return 'result';
            }

            public function getActionName()
            {
                return 'index';
            }

            public function setActionName($name)
            {
                return $this;
            }

            public function getParam($key, $defaultValue = null)
            {
                return $key === 'p' ? 1 : $defaultValue;
            }

            public function setParams(array $params)
            {
                return $this;
            }

            public function getParams()
            {
                return [];
            }

            public function getCookie($name, $default)
            {
                return $default;
            }

            public function isSecure()
            {
                return true;
            }
        };

        $plugin = $this->plugin('https://shop.test/catalogsearch/result/?q=a', 1, '', 'suffix', 'frontend', true, $request);
        $plugin->afterRenderResult($this->page(3), 'r', $this->response('<head></head>'));

        $this->assertStringContainsString('<link rel="next" href="https://shop.test/catalogsearch/result/?q=a&amp;p=2" />', $this->body);
    }
}
