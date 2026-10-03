<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Cache;

use Magento\Framework\App\Cache\TypeListInterface;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Plugin\Cache\CleanMetaCachePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanMetaCachePluginTest extends TestCase
{
    private int $cleaned = 0;

    private array $warnings = [];

    private function plugin(bool $fails = false): CleanMetaCachePlugin
    {
        $this->cleaned = 0;
        $this->warnings = [];
        $cache = $this->createStub(MetaCache::class);
        $cache->method('invalidateAll')->willReturnCallback(function () use ($fails): void {
            if ($fails) {
                throw new \RuntimeException('redis down');
            }
            $this->cleaned++;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });

        return new CleanMetaCachePlugin($cache, $logger);
    }

    public function testPageCacheAndCollectionCleansAlsoCleanResolvedMeta(): void
    {
        $subject = $this->createStub(TypeListInterface::class);
        $plugin = $this->plugin();

        $this->assertSame('r', $plugin->afterCleanType($subject, 'r', 'full_page'));
        $this->assertSame('r', $plugin->afterCleanType($subject, 'r', 'collections'));
        $this->assertSame(2, $this->cleaned);
    }

    public function testOtherCacheTypesAreIgnored(): void
    {
        $this->plugin()->afterCleanType($this->createStub(TypeListInterface::class), null, 'layout');

        $this->assertSame(0, $this->cleaned);
    }

    public function testAFailedCleanIsLoggedAndTheResultReturned(): void
    {
        $result = $this->plugin(true)->afterCleanType($this->createStub(TypeListInterface::class), true, 'full_page');

        $this->assertTrue($result);
        $this->assertSame(['Panth SEO: resolved meta cache clean failed: redis down'], $this->warnings);
    }
}
