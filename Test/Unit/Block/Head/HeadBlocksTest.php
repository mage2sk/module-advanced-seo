<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Head;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\Block\Head\Canonical;
use Panth\AdvancedSEO\Block\Head\SearchConsoleVerification;
use Panth\AdvancedSEO\Block\Head\SpeculationRules;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\ViewModel\Canonical as CanonicalViewModel;
use PHPUnit\Framework\TestCase;

class HeadBlocksTest extends TestCase
{
    private function scopeConfig(?string $code = null, bool $flag = false): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($code);
        $scopeConfig->method('isSetFlag')->willReturn($flag);
        return $scopeConfig;
    }

    private function config(bool $enabled = true): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    public function testCanonicalBlockDelegatesToTheViewModel(): void
    {
        $viewModel = $this->createStub(CanonicalViewModel::class);
        $viewModel->method('isEnabled')->willReturn(true);
        $viewModel->method('getCanonicalUrl')->willReturn('https://shop.test/x.html');

        $block = new Canonical($this->createStub(Context::class), $viewModel);

        $this->assertTrue($block->isEnabled());
        $this->assertSame('https://shop.test/x.html', $block->getCanonicalUrl());
    }

    public function testVerificationCodeIsTrimmedAndRequiresTheModule(): void
    {
        $block = new SearchConsoleVerification($this->createStub(Context::class), $this->scopeConfig('  abc123  '), $this->config());
        $this->assertSame('abc123', $block->getVerificationCode());
        $this->assertTrue($block->hasVerificationCode());

        $block = new SearchConsoleVerification($this->createStub(Context::class), $this->scopeConfig('abc'), $this->config(false));
        $this->assertFalse($block->hasVerificationCode());

        $block = new SearchConsoleVerification($this->createStub(Context::class), $this->scopeConfig(null), $this->config());
        $this->assertSame('', $block->getVerificationCode());
        $this->assertFalse($block->hasVerificationCode());
    }

    public function testSpeculationRulesNeedBothSwitches(): void
    {
        $this->assertTrue((new SpeculationRules($this->createStub(Context::class), $this->scopeConfig(null, true), $this->config()))->isEnabled());
        $this->assertFalse((new SpeculationRules($this->createStub(Context::class), $this->scopeConfig(null, false), $this->config()))->isEnabled());
        $this->assertFalse((new SpeculationRules($this->createStub(Context::class), $this->scopeConfig(null, true), $this->config(false)))->isEnabled());
    }

    public function testSpeculationRulesStepBackWhenTheThemeOutputsItsOwn(): void
    {
        $layout = $this->createStub(\Magento\Framework\View\LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(
            fn (string $name) => $name === 'speculationrules'
                ? $this->createStub(\Magento\Framework\View\Element\Template::class)
                : false
        );
        $context = $this->createStub(Context::class);
        $context->method('getLayout')->willReturn($layout);

        $this->assertFalse((new SpeculationRules($context, $this->scopeConfig(null, true), $this->config()))->isEnabled());
    }

    public function testSpeculationRulesJsonExcludesPrivatePaths(): void
    {
        $block = new SpeculationRules($this->createStub(Context::class), $this->scopeConfig(), $this->config());

        $rules = json_decode($block->getSpeculationRulesJson(), true);

        $this->assertSame(['prerender', 'prefetch'], array_keys($rules));
        $this->assertSame('/*.html', $rules['prerender'][0]['where']['and'][0]['href_matches']);
        $this->assertSame('/*', $rules['prefetch'][0]['where']['and'][0]['href_matches']);
        $excluded = array_column($rules['prefetch'][0]['where']['and'][1]['not']['or'], 'href_matches');
        $this->assertContains('/checkout/*', $excluded);
        $this->assertContains('/customer/*', $excluded);
        $this->assertCount(9, $excluded);
        $this->assertSame('moderate', $rules['prerender'][0]['eagerness']);
    }
}
