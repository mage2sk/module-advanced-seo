<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Meta;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Meta\PaginationMetaPlugin;
use PHPUnit\Framework\TestCase;

class PaginationMetaPluginTest extends TestCase
{

    private function pagedPlugin(int $max): PaginationMetaPlugin
    {
        $config = $this->createStub(\Panth\AdvancedSEO\Helper\Config::class);
        $config->method('getDescriptionMaxLength')->willReturn($max);

        return new PaginationMetaPlugin(
            $this->createStub(\Magento\Framework\App\RequestInterface::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(\Magento\Framework\App\State::class),
            $config
        );
    }

    public function testPagedDescriptionKeepsTheLengthLimitAtAWordBoundary(): void
    {
        $long = str_repeat('Soft cotton shirt for every day. ', 8);
        $result = $this->pagedPlugin(160)->pagedDescription($long, 12);

        $this->assertLessThanOrEqual(160, mb_strlen($result));
        $this->assertStringEndsWith('... - Page 12', $result);
        $this->assertStringNotContainsString('cott... ', $result);
    }

    public function testPagedDescriptionDecodesEntitiesBeforeMeasuring(): void
    {
        $plugin = $this->pagedPlugin(160);

        $this->assertSame("Women's tops & tees - Page 2", $plugin->pagedDescription('Women&#039;s tops &amp; tees.', 2));
        $this->assertSame('Short - Page 3', $plugin->pagedDescription('Short', 3));
        $this->assertSame('- Page 4', $this->pagedPlugin(10)->pagedDescription('Anything long enough', 4));
    }

    private ?string $title = null;

    private ?string $description = null;

    private function apply(
        string $current,
        int|string $page,
        string $position = 'suffix',
        string $format = '| Page %p',
        string $area = 'frontend',
        bool $enabled = true,
        ?string $description = 'Great shirts.'
    ): Title {
        $this->title = null;
        $this->description = null;

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'p' ? $page : $default);
        $state = $this->createStub(State::class);
        if ($area === '!') {
            $state->method('getAreaCode')->willThrowException(new \RuntimeException('no area'));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getPaginationPosition')->willReturn($position);
        $config->method('getPaginationFormat')->willReturn($format);

        $title = $this->createStub(Title::class);
        $title->method('getShort')->willReturn($current);
        $title->method('set')->willReturnCallback(function ($value): void {
            $this->title = $value;
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getDescription')->willReturn($description);
        $pageConfig->method('setDescription')->willReturnCallback(function ($value): void {
            $this->description = $value;
        });

        $plugin = new PaginationMetaPlugin($request, $this->createStub(ScopeConfigInterface::class), $state, $config);

        return $plugin->afterGetTitle($pageConfig, $title);
    }

    public function testPageNumberIsAppendedToTitleAndDescription(): void
    {
        $this->apply('Shirts', '3');

        $this->assertSame('Shirts | Page 3', $this->title);
        $this->assertSame('Great shirts - Page 3', $this->description);
    }

    public function testPrefixPositionAndCustomFormat(): void
    {
        $this->apply('Shirts', 2, 'prefix', 'Page %p:');

        $this->assertSame('Page 2: Shirts', $this->title);
    }

    public function testEmptySettingsFallBackToTheDefaults(): void
    {
        $this->apply('Shirts', 2, '', '', 'frontend', true, '');

        $this->assertSame('Shirts | Page 2', $this->title);
        $this->assertNull($this->description);
    }

    public function testAnAlreadyLabelledTitleIsNotLabelledTwice(): void
    {
        $this->apply('Shirts | Page 2', 2);

        $this->assertNull($this->title);
    }

    public function testFirstPageEmptyTitleAndInactiveContextsAreUntouched(): void
    {
        $this->apply('Shirts', 1);
        $this->apply('', 2);
        $this->apply('Shirts', 2, 'none');
        $this->apply('Shirts', 2, 'suffix', '| Page %p', 'adminhtml');
        $this->apply('Shirts', 2, 'suffix', '| Page %p', '!');
        $this->apply('Shirts', 2, 'suffix', '| Page %p', 'frontend', false);

        $this->assertNull($this->title);
        $this->assertNull($this->description);
    }
}
