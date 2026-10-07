<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Canonical;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Canonical\CrossDomainResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CrossDomainResolverTest extends TestCase
{
    private function resolver(mixed $configValue, ?string $targetBase, ?LoggerInterface $logger = null): CrossDomainResolver
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($configValue);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($targetBase === null) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('missing'));
        } else {
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn($targetBase);
            $storeManager->method('getStore')->willReturn($store);
        }

        return new CrossDomainResolver($config, $storeManager, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testEmptyUrlReturnsEmpty(): void
    {
        $this->assertSame('', $this->resolver('2', 'https://b.test/')->resolve('', 1));
    }

    public static function disabledProvider(): array
    {
        return [[null], [''], ['0'], ['1']];
    }

    #[DataProvider('disabledProvider')]
    public function testDisabledOrSameStoreKeepsUrl(mixed $value): void
    {
        $url = 'https://a.test/p.html';
        $this->assertSame($url, $this->resolver($value, 'https://b.test/')->resolve($url, 1));
    }

    public function testDomainIsReplacedKeepingPathQueryAndFragment(): void
    {
        $this->assertSame(
            'http://b.test:8080/shoes/boot.html?c=red#top',
            $this->resolver('2', 'http://b.test:8080/')->resolve('https://a.test/shoes/boot.html?c=red#top', 1)
        );
    }

    public function testMissingPathDefaultsToSlashAndSchemeDefaultsToHttps(): void
    {
        $this->assertSame('https://b.test/', $this->resolver('2', '//b.test')->resolve('https://a.test', 1));
    }

    public function testUnresolvableTargetStoreKeepsUrl(): void
    {
        $this->assertSame('https://a.test/x', $this->resolver('2', null)->resolve('https://a.test/x', 1));
        $this->assertSame('https://a.test/x', $this->resolver('2', '/')->resolve('https://a.test/x', 1));
    }

    public function testRelativeOrMalformedSourceKeepsUrl(): void
    {
        $this->assertSame('/relative', $this->resolver('2', 'https://b.test')->resolve('/relative', 1));
        $this->assertSame('http:///x', $this->resolver('2', 'https://b.test')->resolve('http:///x', 1));
    }

    public function testDifferentTargetStoreRewritesWithoutWarning(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://b.test');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('3');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $resolver = new CrossDomainResolver($config, $storeManager, $logger);
        $this->assertSame('https://b.test/a', $resolver->resolve('https://a.test/a', 1));
    }
}
