<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\PageConfig;

use Magento\Framework\DataObject;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\SeoTitleHolder;
use Panth\AdvancedSEO\Plugin\PageConfig\SeoTitleGuardPlugin;
use PHPUnit\Framework\TestCase;

class SeoTitleGuardPluginTest extends TestCase
{
    private ?string $setTitle = null;

    private function guard(?string $seoTitle, string $current, bool $enabled = true, ?object $heading = null): mixed
    {
        $this->setTitle = null;
        $holder = new SeoTitleHolder();
        $holder->set($seoTitle);

        $config = $this->createStub(Config::class);
        $config->method('isAuditFixEnabled')->willReturn($enabled);

        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($heading ?? false);

        $title = $this->createStub(Title::class);
        $title->method('getShort')->willReturn($current);
        $title->method('set')->willReturnCallback(function ($value): void {
            $this->setTitle = $value;
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        return (new SeoTitleGuardPlugin($config, $holder, $layout))->afterPublicBuild($pageConfig, 'built');
    }

    public function testTheSeoTitleReplacesAnH1DrivenTitleAndKeepsTheHeading(): void
    {
        $heading = new class extends DataObject {
            public function setPageTitle($title)
            {
                return $this->setData('page_title', $title);
            }

            public function getPageHeading()
            {
                return 'Visible Heading';
            }
        };

        $this->assertSame('built', $this->guard('SEO Title', 'Visible Heading', true, $heading));
        $this->assertSame('SEO Title', $this->setTitle);
        $this->assertSame('Visible Heading', $heading->getData('page_title'));
    }

    public function testNothingChangesWithoutASeoTitleOrWhenTheFixIsOff(): void
    {
        $this->guard(null, 'Heading');
        $this->assertNull($this->setTitle);

        $this->guard('SEO', 'Heading', false);
        $this->assertNull($this->setTitle);
    }

    public function testMatchingOrEmptyTitlesAreLeftAlone(): void
    {
        $this->guard('SEO', 'SEO');
        $this->assertNull($this->setTitle);

        $this->guard('SEO', '');
        $this->assertNull($this->setTitle);
    }

    public function testTitleIsSetEvenWithoutAHeadingBlock(): void
    {
        $this->assertSame('built', $this->guard('SEO', 'Other'));
        $this->assertSame('SEO', $this->setTitle);
    }
}
