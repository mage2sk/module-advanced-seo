<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Frontend;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Layout;
use Panth\AdvancedSEO\Plugin\Frontend\SeoToolbarCacheablePlugin;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;
use PHPUnit\Framework\TestCase;

class SeoToolbarCacheablePluginTest extends TestCase
{
    private array $context = [];

    private int $checks = 0;

    private function plugin(bool|\Throwable $allowed): SeoToolbarCacheablePlugin
    {
        $this->context = [];
        $this->checks = 0;
        $toolbar = $this->createStub(SeoToolbar::class);
        $toolbar->method('isAllowed')->willReturnCallback(function () use ($allowed): bool {
            $this->checks++;
            if ($allowed instanceof \Throwable) {
                throw $allowed;
            }
            return $allowed;
        });
        $httpContext = $this->createStub(HttpContext::class);
        $httpContext->method('setValue')->willReturnCallback(function ($key, $value, $default) use ($httpContext) {
            $this->context[$key] = [$value, $default];
            return $httpContext;
        });

        return new SeoToolbarCacheablePlugin($toolbar, $httpContext);
    }

    public function testPagesShowingTheToolbarAreNotCachedAndVaryTheCacheKey(): void
    {
        $plugin = $this->plugin(true);
        $layout = $this->createStub(Layout::class);

        $this->assertFalse($plugin->afterIsCacheable($layout, true));
        $this->assertFalse($plugin->afterIsCacheable($layout, true));
        $this->assertSame(['panth_seo_toolbar' => [1, 0]], $this->context);
        $this->assertSame(1, $this->checks);
    }

    public function testOtherVisitorsKeepTheCacheableFlag(): void
    {
        $layout = $this->createStub(Layout::class);

        $this->assertTrue($this->plugin(false)->afterIsCacheable($layout, true));
        $this->assertSame([], $this->context);
        $this->assertTrue($this->plugin(new \RuntimeException('ip'))->afterIsCacheable($layout, true));
        $this->assertFalse($this->plugin(true)->afterIsCacheable($layout, false));
        $this->assertSame(0, $this->checks);
    }
}
