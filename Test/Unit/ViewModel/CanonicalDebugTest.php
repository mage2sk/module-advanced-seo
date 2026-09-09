<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\ViewModel\Canonical;
use PHPUnit\Framework\TestCase;

class CanonicalDebugTest extends TestCase
{
    private array $lines = [];

    protected function setUp(): void
    {
        $this->lines = [];
    }

    private function logger(): SeoDebugLogger
    {
        $logger = $this->createMock(SeoDebugLogger::class);
        $logger->method('debug')->willReturnCallback(
            function ($message, array $context = []): void {
                $this->lines[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return $logger;
    }

    private function decisions(): array
    {
        return array_column(array_column($this->lines, 'context'), 'decision');
    }

    private function viewModel(
        array $options = [],
        ?SeoDebugLogger $logger = null
    ): Canonical {
        $options += [
            'enabled' => true,
            'debug' => true,
            'robots' => 'INDEX,FOLLOW',
            'noindexSuppression' => true,
            'enabledThrows' => false,
            'resolverThrows' => false,
            'path' => '/some-page',
            'pageConfigCanonical' => false,
        ];

        $config = $this->createMock(SeoConfig::class);
        if ($options['enabledThrows']) {
            $config->method('isEnabled')->willThrowException(new \RuntimeException('config down'));
        } else {
            $config->method('isEnabled')->willReturn($options['enabled']);
        }
        $config->method('isCanonicalEnabled')->willReturn(true);
        $config->method('isDebug')->willReturn($options['debug']);
        $config->method('isNoindexNoRoute')->willReturn(true);
        $config->method('isCanonicalDisabledForNoindex')->willReturn($options['noindexSuppression']);
        $config->method('getCanonicalIgnorePages')->willReturn('');
        $config->method('canonicalPaginatedToFirst')->willReturn(false);

        $resolver = $this->createMock(CanonicalResolverInterface::class);
        if ($options['resolverThrows']) {
            $resolver->method('normalize')->willThrowException(new \RuntimeException('boom'));
        } else {
            $resolver->method('normalize')->willReturnArgument(0);
        }

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getRequestUri')->willReturn($options['path']);
        $request->method('getParam')->willReturn('');
        $request->method('getParams')->willReturn([]);
        $request->method('getFullActionName')->willReturn('cms_index_index');

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getRobots')->willReturn($options['robots']);

        $assets = [];
        if ($options['pageConfigCanonical']) {
            $asset = $this->createMock(\Magento\Framework\View\Asset\AssetInterface::class);
            $asset->method('getContentType')->willReturn('canonical');
            $assets[] = $asset;
        }
        $collection = $this->createMock(GroupedCollection::class);
        $collection->method('getAll')->willReturn($assets);
        $pageConfig->method('getAssetCollection')->willReturn($collection);

        return new Canonical(
            $resolver,
            $this->createMock(Registry::class),
            $request,
            $storeManager,
            $config,
            $pageConfig,
            $this->createMock(AttributeCollectionFactory::class),
            $logger
        );
    }

    public function testASuccessfulCanonicalLogsNothing(): void
    {
        $url = $this->viewModel([], $this->logger())->getCanonicalUrl();

        $this->assertSame('https://example.com/some-page', $url);
        $this->assertSame([], $this->lines);
    }

    public function testAnExceptionIsLoggedWithClassFileAndRequestUri(): void
    {
        $url = $this->viewModel(['resolverThrows' => true], $this->logger())->getCanonicalUrl();

        $this->assertSame('', $url);
        $this->assertCount(1, $this->lines);
        $context = $this->lines[0]['context'];
        $this->assertSame('panth_seo: canonical.suppressed', $this->lines[0]['message']);
        $this->assertSame('exception', $context['decision']);
        $this->assertSame('boom', $context['exception']);
        $this->assertSame(\RuntimeException::class, $context['class']);
        $this->assertStringContainsString('.php:', $context['at']);
        $this->assertSame('/some-page', $context['request_uri']);
    }

    public function testIsEnabledFailureIsLogged(): void
    {
        $viewModel = $this->viewModel(['enabledThrows' => true], $this->logger());

        $this->assertFalse($viewModel->isEnabled());
        $this->assertSame(['is_enabled_threw'], $this->decisions());
        $this->assertSame('config down', $this->lines[0]['context']['exception']);
    }

    public function testConfiguredSuppressionNamesItsReasonInsteadOfLookingLikeACrash(): void
    {
        $url = $this->viewModel(['robots' => 'NOINDEX,NOFOLLOW'], $this->logger())->getCanonicalUrl();

        $this->assertSame('', $url);
        $this->assertSame(['noindex_page'], $this->decisions());
        $this->assertSame('NOINDEX,NOFOLLOW', $this->lines[0]['context']['robots']);
        $this->assertArrayNotHasKey('exception', $this->lines[0]['context']);
    }

    public function testCanonicalDisabledIsReported(): void
    {
        $url = $this->viewModel(['enabled' => false], $this->logger())->getCanonicalUrl();

        $this->assertSame('', $url);
        $this->assertSame(['canonical_disabled'], $this->decisions());
    }

    public function testNothingIsLoggedWhenDebugIsOff(): void
    {
        $url = $this->viewModel(['debug' => false, 'resolverThrows' => true], $this->logger())->getCanonicalUrl();

        $this->assertSame('', $url);
        $this->assertSame([], $this->lines, 'debug=0 must stay silent in production');
    }

    public function testNothingBreaksWhenTheLoggerIsNotBound(): void
    {
        $url = $this->viewModel(['resolverThrows' => true], null)->getCanonicalUrl();

        $this->assertSame('', $url, 'a missing di.xml binding must not change behaviour, only silence the log');
        $this->assertSame([], $this->lines);
    }

    public function testAnotherModulesCanonicalIsNotDuplicated(): void
    {
        $url = $this->viewModel(['pageConfigCanonical' => true], $this->logger())->getCanonicalUrl();

        $this->assertSame('', $url, 'a page that already has a canonical must not get a second one');
        $this->assertSame(['already_in_page_config'], $this->decisions());
    }

    public function testAPageWithNoExistingCanonicalStillGetsOne(): void
    {
        $url = $this->viewModel(['pageConfigCanonical' => false], $this->logger())->getCanonicalUrl();

        $this->assertSame('https://example.com/some-page', $url);
    }
}
