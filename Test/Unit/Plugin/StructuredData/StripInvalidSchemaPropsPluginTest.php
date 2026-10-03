<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\StructuredData;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;
use Panth\AdvancedSEO\Plugin\StructuredData\StripInvalidSchemaPropsPlugin;
use PHPUnit\Framework\TestCase;

class StripInvalidSchemaPropsPluginTest extends TestCase
{
    private function plugin(bool $enabled, bool $stripFails = false): StripInvalidSchemaPropsPlugin
    {
        $config = $this->createStub(Config::class);
        $config->method('isAuditFixEnabled')->willReturnCallback(
            static fn(string $fix): bool => $enabled && $fix === Config::AUDIT_FIX_STRIP_INVALID_SCHEMA_PROPS
        );
        $allowList = $this->createStub(SchemaAllowList::class);
        $allowList->method('strip')->willReturnCallback(static function (array $node) use ($stripFails): array {
            if ($stripFails) {
                throw new \RuntimeException('bad schema file');
            }
            unset($node['bogus']);
            return $node;
        });

        return new StripInvalidSchemaPropsPlugin($config, $allowList);
    }

    public function testInvalidPropertiesAreStrippedWhenTheFixIsOn(): void
    {
        $result = $this->plugin(true)->afterGetJsonLd(null, ['@type' => 'Product', 'bogus' => 1]);

        $this->assertSame(['@type' => 'Product'], $result);
    }

    public function testResultIsUntouchedWhenTheFixIsOffOrFails(): void
    {
        $node = ['@type' => 'Product', 'bogus' => 1];

        $this->assertSame($node, $this->plugin(false)->afterGetJsonLd(null, $node));
        $this->assertSame($node, $this->plugin(true, true)->afterGetJsonLd(null, $node));
    }

    public function testNonArrayAndEmptyResultsPassThrough(): void
    {
        $plugin = $this->plugin(true);

        $this->assertSame('json', $plugin->afterGetJsonLd(null, 'json'));
        $this->assertSame([], $plugin->afterGetJsonLd(null, []));
        $this->assertNull($plugin->afterGetJsonLd(null, null));
    }
}
