<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\HreflangGroupValidator;
use Panth\AdvancedSEO\Model\Audit\Fix\ReadmeLinkRewriter;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;
use Panth\AdvancedSEO\Plugin\Content\ReadmeLinkPlugin;
use Panth\AdvancedSEO\Plugin\StructuredData\StripInvalidSchemaPropsPlugin;
use PHPUnit\Framework\TestCase;

class AuditFixesTest extends TestCase
{
    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isAuditFixEnabled')->willReturn($enabled);

        return $config;
    }

    public function testStripInvalidSchemaPropsOnlyActsWhenEnabled(): void
    {
        $node = ['@type' => 'Product', 'name' => 'X', 'datePublished' => '2024-01-01', 'dateModified' => '2024-02-01'];

        $off = (new StripInvalidSchemaPropsPlugin($this->config(false), new SchemaAllowList()))->afterGetJsonLd(null, $node);
        $on  = (new StripInvalidSchemaPropsPlugin($this->config(true), new SchemaAllowList()))->afterGetJsonLd(null, $node);

        $this->assertSame($node, $off);
        $this->assertSame(['@type' => 'Product', 'name' => 'X'], $on);
    }

    public function testStripHandlesListsOfNodes(): void
    {
        $nodes = [
            ['@type' => 'Product', 'name' => 'X', 'datePublished' => '2024-01-01'],
            ['@type' => 'BlogPosting', 'headline' => 'Y', 'datePublished' => '2024-01-01'],
        ];

        $result = (new StripInvalidSchemaPropsPlugin($this->config(true), new SchemaAllowList()))->afterGetJsonLd(null, $nodes);

        $this->assertArrayNotHasKey('datePublished', $result[0]);
        $this->assertSame('2024-01-01', $result[1]['datePublished']);
    }

    public function testRelativeReadmeLinksAreRewrittenToTheRawBase(): void
    {
        $html = '<p><a href="docs/x.png"><img src="https://raw.githubusercontent.com/example-org/example-repo/main/docs/x.png" alt="x"></a>'
            . '<a href="./docs/y.png"><img src="https://raw.githubusercontent.com/example-org/example-repo/main/docs/y.png"></a>'
            . '<a href="docs/plain">No image</a>'
            . '<a href="https://github.com/example-org/example-repo/raw/main/#installation">Install</a></p>';

        $out = (new ReadmeLinkRewriter())->rewrite($html);

        $this->assertStringContainsString('href="https://raw.githubusercontent.com/example-org/example-repo/main/docs/x.png"', $out);
        $this->assertStringContainsString('href="https://raw.githubusercontent.com/example-org/example-repo/main/docs/y.png"', $out);
        $this->assertStringContainsString('href="docs/plain"', $out);
        $this->assertStringContainsString('href="https://github.com/example-org/example-repo#installation"', $out);
    }

    public function testReadmePluginRespectsTheFlag(): void
    {
        $html = '<a href="docs/x.png"><img src="https://raw.githubusercontent.com/o/r/main/docs/x.png"></a>';

        $this->assertSame($html, (new ReadmeLinkPlugin($this->config(false), new ReadmeLinkRewriter()))->afterFilter(null, $html));
        $this->assertStringContainsString(
            'href="https://raw.githubusercontent.com/o/r/main/docs/x.png"',
            (new ReadmeLinkPlugin($this->config(true), new ReadmeLinkRewriter()))->afterProductAttribute(null, $html)
        );
    }

    public function testHreflangGroupValidatorRefusesTwoMembersWithTheSameLocale(): void
    {
        $validator = new HreflangGroupValidator();
        $locale    = static fn (int $storeId): string => $storeId === 1 ? 'en_US' : 'de_DE';

        $cluster = [
            ['store_id' => 1, 'entity_id' => 10, 'locale' => 'en-US'],
            ['store_id' => 1, 'entity_id' => 11, 'locale' => ''],
            ['store_id' => 1, 'entity_id' => 12, 'locale' => 'en-US', 'is_removed' => 1],
        ];
        $this->assertSame(['en-us' => ['1:10', '1:11']], $validator->duplicateLocales($cluster, $locale));

        $translations = [
            ['store_id' => 1, 'entity_id' => 10, 'locale' => 'en-US'],
            ['store_id' => 2, 'entity_id' => 10, 'locale' => ''],
        ];
        $this->assertSame([], $validator->duplicateLocales($translations, $locale));
    }
}
