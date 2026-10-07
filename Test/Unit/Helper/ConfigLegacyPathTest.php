<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Panth\AdvancedSEO\Helper\Config;
use PHPUnit\Framework\TestCase;

class ConfigLegacyPathTest extends TestCase
{
    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );

        return new Config($scopeConfig, $this->createStub(EncryptorInterface::class));
    }

    public function testBrandAttributeReadsTheStructuredDataSection(): void
    {
        $config = $this->config([Config::XML_SD_BRAND_ATTRIBUTE => 'product_brand']);

        $this->assertSame('product_brand', $config->getBrandAttribute(1));
    }

    public function testBrandAttributeFallsBackToTheLegacyPath(): void
    {
        $config = $this->config([Config::XML_SD_BRAND_ATTRIBUTE_LEGACY => 'legacy_brand']);

        $this->assertSame('legacy_brand', $config->getBrandAttribute(1));
    }

    public function testBrandAttributeDefaultsToManufacturer(): void
    {
        $this->assertSame('manufacturer', $this->config([])->getBrandAttribute(1));
    }

    public function testCurrentPathWinsOverLegacyPath(): void
    {
        $config = $this->config([
            Config::XML_SD_BRAND_ATTRIBUTE        => 'current_brand',
            Config::XML_SD_BRAND_ATTRIBUTE_LEGACY => 'legacy_brand',
        ]);

        $this->assertSame('current_brand', $config->getBrandAttribute(1));
    }

    public function testGtinAndMpnResolveThroughTheSameChain(): void
    {
        $config = $this->config([
            Config::XML_SD_GTIN_ATTRIBUTE       => 'ean',
            Config::XML_SD_MPN_ATTRIBUTE_LEGACY => 'legacy_mpn',
        ]);

        $this->assertSame('ean', $config->getGtinAttribute(1));
        $this->assertSame('legacy_mpn', $config->getMpnAttribute(1));
    }

    public function testGtinAndMpnAreEmptyWhenNeitherPathIsSet(): void
    {
        $config = $this->config([]);

        $this->assertSame('', $config->getGtinAttribute(1));
        $this->assertSame('', $config->getMpnAttribute(1));
    }

    public function testDefaultBrandFallsBackToTheLegacyPath(): void
    {
        $config = $this->config([Config::XML_SD_DEFAULT_BRAND_LEGACY => 'Acme Store']);

        $this->assertSame('Acme Store', $config->getDefaultBrand(1));
    }

    public function testWhitespaceOnlyValuesAreTreatedAsUnset(): void
    {
        $config = $this->config([
            Config::XML_SD_BRAND_ATTRIBUTE        => '   ',
            Config::XML_SD_BRAND_ATTRIBUTE_LEGACY => 'legacy_brand',
        ]);

        $this->assertSame('legacy_brand', $config->getBrandAttribute(1));
    }
}
