<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Response;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Plugin\Response\CanonicalHeaderPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CanonicalHeaderPluginTest extends TestCase
{
    private const PAGE = '<!doctype html><html><head><title>T</title>'
        . '<link rel="canonical" href="https://example.test/bag.html?a=1&amp;b=2" data-panth-seo="canonical" />'
        . '</head><body>x</body></html>';

    private function createPlugin(bool $ajax = false, bool $enabled = true): CanonicalHeaderPlugin
    {
        $appState = $this->createStub(AppState::class);
        $appState->method('getAreaCode')->willReturn('frontend');

        $request = $this->createStub(HttpRequest::class);
        $request->method('isAjax')->willReturn($ajax);
        $request->method('isXmlHttpRequest')->willReturn($ajax);

        $config = $this->createStub(SeoConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isCanonicalEnabled')->willReturn(true);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CanonicalHeaderPlugin(
            $appState,
            $request,
            $config,
            $storeManager,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function createResponse(string $content, ?string $contentType = null): HttpResponse
    {
        $response = new HttpResponse(
            $this->createStub(HttpRequest::class),
            $this->createStub(CookieManagerInterface::class),
            $this->createStub(CookieMetadataFactory::class),
            $this->createStub(HttpContext::class),
            $this->createStub(DateTime::class),
            $this->createStub(SessionConfig::class)
        );
        $response->setContent($content);
        if ($contentType !== null) {
            $response->setHeader('Content-Type', $contentType, true);
        }

        return $response;
    }

    private function linkHeader(HttpResponse $response): ?string
    {
        $header = $response->getHeader('Link');

        return $header ? $header->getFieldValue() : null;
    }

    public function testHtmlPageGetsCanonicalLinkHeader(): void
    {
        $response = $this->createResponse(self::PAGE, 'text/html; charset=UTF-8');
        $this->createPlugin()->beforeSendResponse($response);

        $this->assertSame('<https://example.test/bag.html?a=1&b=2>; rel="canonical"', $this->linkHeader($response));
    }

    public function testJsonResponseIsSkipped(): void
    {
        $response = $this->createResponse('{"valid":true}', 'application/json');
        $this->createPlugin()->beforeSendResponse($response);

        $this->assertNull($this->linkHeader($response));
    }

    public function testAjaxRequestIsSkipped(): void
    {
        $response = $this->createResponse(self::PAGE, 'text/html');
        $this->createPlugin(true)->beforeSendResponse($response);

        $this->assertNull($this->linkHeader($response));
    }

    public function testResponseWithoutRenderedHeadIsSkipped(): void
    {
        $response = $this->createResponse('<div><link rel="canonical" href="https://example.test/x.html"></div>');
        $this->createPlugin()->beforeSendResponse($response);

        $this->assertNull($this->linkHeader($response));
    }

    public function testDisabledModuleIsSkipped(): void
    {
        $response = $this->createResponse(self::PAGE, 'text/html');
        $this->createPlugin(false, false)->beforeSendResponse($response);

        $this->assertNull($this->linkHeader($response));
    }

    public function testExistingLinkHeaderIsKept(): void
    {
        $response = $this->createResponse(self::PAGE, 'text/html');
        $response->setHeader('Link', '<https://cdn.example.test>; rel=preconnect; crossorigin', true);
        $this->createPlugin()->beforeSendResponse($response);

        $this->assertSame(
            '<https://cdn.example.test>; rel=preconnect; crossorigin, <https://example.test/bag.html?a=1&b=2>; rel="canonical"',
            $this->linkHeader($response)
        );
    }
}
