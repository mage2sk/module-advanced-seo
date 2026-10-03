<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Canonical;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\Canonical\Resolver;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolverSuppressionTest extends TestCase
{
    private array $lines = [];

    protected function setUp(): void
    {
        $this->lines = [];
    }

    private function resolver(array $options = []): Resolver
    {
        $options += [
            'debug' => true,
            'noindexSuppression' => true,
            'ignorePages' => '',
            'bindLogger' => true,
        ];

        $config = $this->createMock(Config::class);
        $config->method('isDebug')->willReturn($options['debug']);
        $config->method('isCanonicalDisabledForNoindex')->willReturn($options['noindexSuppression']);
        $config->method('getCanonicalIgnorePages')->willReturn($options['ignorePages']);

        $logger = $this->createMock(SeoDebugLogger::class);
        $logger->method('debug')->willReturnCallback(
            function ($message, array $context = []): void {
                $this->lines[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return new Resolver(
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(PageRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $config,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EavConfig::class),
            $this->createMock(CustomCanonicalRepository::class),
            null,
            null,
            $options['bindLogger'] ? $logger : null
        );
    }

    private function decisions(): array
    {
        return array_column(array_column($this->lines, 'context'), 'decision');
    }

    public function testNoindexSuppressionNamesItsReason(): void
    {
        $url = $this->resolver()->getCanonicalUrl(
            MetaResolverInterface::ENTITY_PRODUCT,
            11,
            1,
            ['current_path' => '/a-product.html', 'robots' => 'NOINDEX,NOFOLLOW']
        );

        $this->assertSame('', $url);
        $this->assertSame(['noindex_page'], $this->decisions());
        $context = $this->lines[0]['context'];
        $this->assertSame('panth_seo: canonical.suppressed', $this->lines[0]['message']);
        $this->assertSame(MetaResolverInterface::ENTITY_PRODUCT, $context['entity_type']);
        $this->assertSame(11, $context['entity_id']);
        $this->assertSame(1, $context['store_id']);
        $this->assertSame('NOINDEX,NOFOLLOW', $context['robots']);
    }

    public function testAnIgnoredPathNamesItsReason(): void
    {
        $url = $this->resolver(['ignorePages' => "/checkout/*\n/customer/*"])->getCanonicalUrl(
            MetaResolverInterface::ENTITY_CMS,
            7,
            1,
            ['current_path' => '/checkout/cart', 'robots' => 'INDEX,FOLLOW']
        );

        $this->assertSame('', $url);
        $this->assertSame(['ignored_path'], $this->decisions());
        $this->assertSame('/checkout/cart', $this->lines[0]['context']['current_path']);
    }

    public function testAnUnbuildableUrlNamesItsReason(): void
    {
        $url = $this->resolver()->getCanonicalUrl('something_unknown', 3, 1, ['robots' => 'INDEX,FOLLOW']);

        $this->assertSame('', $url);
        $this->assertSame(['no_url_built'], $this->decisions());
    }

    public function testIndexablePagesAreNotSuppressed(): void
    {
        $this->resolver(['noindexSuppression' => false])->getCanonicalUrl(
            MetaResolverInterface::ENTITY_PRODUCT,
            11,
            1,
            ['current_path' => '/a-product.html', 'robots' => 'NOINDEX,NOFOLLOW']
        );

        $this->assertNotContains('noindex_page', $this->decisions());
    }

    public function testNothingIsLoggedWhenDebugIsOff(): void
    {
        $this->resolver(['debug' => false])->getCanonicalUrl(
            MetaResolverInterface::ENTITY_PRODUCT,
            11,
            1,
            ['current_path' => '/a-product.html', 'robots' => 'NOINDEX,NOFOLLOW']
        );

        $this->assertSame([], $this->lines);
    }

    public function testSuppressionStillReturnsEmptyWithoutALogger(): void
    {
        $url = $this->resolver(['bindLogger' => false])->getCanonicalUrl(
            MetaResolverInterface::ENTITY_PRODUCT,
            11,
            1,
            ['current_path' => '/a-product.html', 'robots' => 'NOINDEX,NOFOLLOW']
        );

        $this->assertSame('', $url);
        $this->assertSame([], $this->lines);
    }
}
