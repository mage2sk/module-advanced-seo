<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Response;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Plugin\Response\CanonicalHeaderPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CanonicalHeaderPluginCoverageTest extends TestCase
{
    private array $debug = [];

    private function plugin(string|\Throwable $area = 'frontend', bool $storeFails = false, bool $canonicalEnabled = true): CanonicalHeaderPlugin
    {
        $this->debug = [];
        $appState = $this->createStub(AppState::class);
        if ($area instanceof \Throwable) {
            $appState->method('getAreaCode')->willThrowException($area);
        } else {
            $appState->method('getAreaCode')->willReturn($area);
        }
        $config = $this->createStub(SeoConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isCanonicalEnabled')->willReturn($canonicalEnabled);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('store gone'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function ($message): void {
            $this->debug[] = $message;
        });

        return new CanonicalHeaderPlugin($appState, $this->createStub(HttpRequest::class), $config, $storeManager, $logger);
    }

    private function response(string $head, ?string $link = null): HttpResponse
    {
        $response = new HttpResponse(
            $this->createStub(HttpRequest::class),
            $this->createStub(CookieManagerInterface::class),
            $this->createStub(CookieMetadataFactory::class),
            $this->createStub(HttpContext::class),
            $this->createStub(DateTime::class),
            $this->createStub(SessionConfig::class)
        );
        $response->setContent('<html><head>' . $head . '</head><body></body></html>');
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        if ($link !== null) {
            $response->setHeader('Link', $link, true);
        }
        return $response;
    }

    private static function link(HttpResponse $response): ?string
    {
        $header = $response->getHeader('Link');
        return $header ? $header->getFieldValue() : null;
    }

    public static function unusableCanonicals(): array
    {
        return [
            'relative'      => ['<link rel="canonical" href="/bag.html" />'],
            'javascript'    => ['<link rel="canonical" href="javascript:alert(1)" />'],
            'with spaces'   => ['<link rel="canonical" href="https://a.test/a b" />'],
            'empty href'    => ['<link rel="canonical" href="" />'],
            'no href'       => ['<link rel="canonical" />'],
            'not canonical' => ['<link rel="alternate" href="https://a.test/" />'],
            'no links'      => ['<title>x</title>'],
        ];
    }

    #[DataProvider('unusableCanonicals')]
    public function testUnusableCanonicalsProduceNoHeader(string $head): void
    {
        $response = $this->response($head);
        $this->plugin()->beforeSendResponse($response);

        $this->assertNull(self::link($response));
    }

    public function testTheFirstCanonicalWithAnHrefWins(): void
    {
        $response = $this->response("<link rel='canonical'><link data-x rel = 'canonical' href='https://a.test/x' />");
        $this->plugin()->beforeSendResponse($response);

        $this->assertSame('<https://a.test/x>; rel="canonical"', self::link($response));
    }

    public function testAnExistingLinkHeaderIsExtended(): void
    {
        $response = $this->response('<link rel="canonical" href="https://a.test/x" />', '<https://cdn.test/>; rel="preconnect"');
        $this->plugin()->beforeSendResponse($response);

        $this->assertSame('<https://cdn.test/>; rel="preconnect", <https://a.test/x>; rel="canonical"', self::link($response));
    }

    public function testNonFrontendOrDisabledCanonicalIsSkipped(): void
    {
        foreach ([$this->plugin('adminhtml'), $this->plugin(new \RuntimeException('no area')), $this->plugin('frontend', false, false)] as $plugin) {
            $response = $this->response('<link rel="canonical" href="https://a.test/x" />');
            $plugin->beforeSendResponse($response);
            $this->assertNull(self::link($response));
        }
    }

    public function testFailuresAreLoggedAtDebugLevel(): void
    {
        $response = $this->response('<link rel="canonical" href="https://a.test/x" />');
        $this->plugin('frontend', true)->beforeSendResponse($response);

        $this->assertSame(['Panth SEO CanonicalHeaderPlugin: store gone'], $this->debug);
        $this->assertNull(self::link($response));
    }
}
