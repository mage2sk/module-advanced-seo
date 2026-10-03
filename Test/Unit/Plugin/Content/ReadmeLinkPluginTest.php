<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Content;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\ReadmeLinkRewriter;
use Panth\AdvancedSEO\Plugin\Content\ReadmeLinkPlugin;
use PHPUnit\Framework\TestCase;

class ReadmeLinkPluginTest extends TestCase
{
    private function plugin(bool $enabled, bool $fails = false): ReadmeLinkPlugin
    {
        $config = $this->createStub(Config::class);
        $config->method('isAuditFixEnabled')->willReturnCallback(
            static fn(string $fix): bool => $enabled && $fix === Config::AUDIT_FIX_REWRITE_RELATIVE_README_LINKS
        );
        $rewriter = $this->createStub(ReadmeLinkRewriter::class);
        $rewriter->method('rewrite')->willReturnCallback(static function (string $html) use ($fails): string {
            if ($fails) {
                throw new \RuntimeException('regex');
            }
            return '[' . $html . ']';
        });

        return new ReadmeLinkPlugin($config, $rewriter);
    }

    public function testEveryHookRewritesHtmlWhenTheFixIsOn(): void
    {
        $plugin = $this->plugin(true);

        $this->assertSame('[a]', $plugin->afterProductAttribute(null, 'a'));
        $this->assertSame('[b]', $plugin->afterCategoryAttribute(null, 'b'));
        $this->assertSame('[c]', $plugin->afterFilter(null, 'c'));
    }

    public function testHtmlIsUntouchedWhenOffFailingOrNotAString(): void
    {
        $this->assertSame('a', $this->plugin(false)->afterFilter(null, 'a'));
        $this->assertSame('a', $this->plugin(true, true)->afterFilter(null, 'a'));
        $this->assertSame('', $this->plugin(true)->afterFilter(null, ''));
        $this->assertNull($this->plugin(true)->afterProductAttribute(null, null));
    }
}
