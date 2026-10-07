<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\PageConfig;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Page\Title;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\PageConfig\StripTitlePrefixSuffixPlugin;
use PHPUnit\Framework\TestCase;

class StripTitlePrefixSuffixPluginTest extends TestCase
{
    private function plugin(string $prefix, string $suffix, bool $enabled = true, bool $strip = true): StripTitlePrefixSuffixPlugin
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => ['design/head/title_prefix' => $prefix, 'design/head/title_suffix' => $suffix][$path] ?? null
        );
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('isStripTitlePrefixSuffix')->willReturn($strip);

        return new StripTitlePrefixSuffixPlugin($scopeConfig, $config);
    }

    public function testPrefixAndSuffixAreRemoved(): void
    {
        $title = $this->createStub(Title::class);

        $this->assertSame('Shirts', $this->plugin(' Shop |', '| Brand ')->afterGet($title, 'Shop | Shirts | Brand'));
    }

    public function testTitlesWithoutTheAffixesAreKept(): void
    {
        $title = $this->createStub(Title::class);

        $this->assertSame('Shirts', $this->plugin('Shop', 'Brand')->afterGet($title, 'Shirts'));
        $this->assertSame('Shop Shirts', $this->plugin('', '')->afterGet($title, 'Shop Shirts'));
    }

    public function testDisabledSettingsKeepTheFullTitle(): void
    {
        $title = $this->createStub(Title::class);

        $this->assertSame('Shop Shirts', $this->plugin('Shop', '', false)->afterGet($title, 'Shop Shirts'));
        $this->assertSame('Shop Shirts', $this->plugin('Shop', '', true, false)->afterGet($title, 'Shop Shirts'));
    }
}
