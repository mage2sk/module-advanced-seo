<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;
use PHPUnit\Framework\TestCase;

class SchemaAllowListTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panth_seo_schema_' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function list(array|string $definition): SchemaAllowList
    {
        $file = $this->dir . '/schema.json';
        file_put_contents($file, is_string($definition) ? $definition : json_encode($definition));

        return new SchemaAllowList($file);
    }

    private function sample(): SchemaAllowList
    {
        return $this->list(['types' => [
            'Product' => ['properties' => ['name', 'offers', 'brand']],
            'Offer'   => ['properties' => ['price', 'priceCurrency']],
            'Thing'   => ['properties' => ['url']],
        ]]);
    }

    public function testTypesAreLoadedOnceFromTheFile(): void
    {
        $list = $this->sample();

        $this->assertSame(['name' => true, 'offers' => true, 'brand' => true], $list->getTypes()['Product']);
        unlink($this->dir . '/schema.json');
        $this->assertTrue($list->isKnownType('Offer'));
        $this->assertFalse($list->isKnownType('Recipe'));
    }

    public function testMissingOrInvalidFilesYieldNoTypes(): void
    {
        $this->assertSame([], (new SchemaAllowList($this->dir . '/missing.json'))->getTypes());
        $this->assertSame([], $this->list('{not json')->getTypes());
        $this->assertSame([], $this->list(['other' => []])->getTypes());
    }

    public function testDefaultFileShipsWithTheModule(): void
    {
        $this->assertTrue((new SchemaAllowList())->isKnownType('Product'));
    }

    public function testTypeNamesStripNamespaces(): void
    {
        $this->assertSame(['Product'], SchemaAllowList::typeNames('https://schema.org/Product'));
        $this->assertSame(['Offer', 'Thing'], SchemaAllowList::typeNames(['schema:Offer', '', 5, 'http://x#Thing']));
        $this->assertSame([], SchemaAllowList::typeNames(null));
    }

    public function testAllowedForMergesKnownTypes(): void
    {
        $list = $this->sample();

        $this->assertNull($list->allowedFor(['Recipe']));
        $this->assertSame(
            ['name' => true, 'offers' => true, 'brand' => true, 'url' => true],
            $list->allowedFor(['Product', 'Recipe', 'Thing'])
        );
    }

    public function testPropertyRules(): void
    {
        $list    = $this->sample();
        $allowed = ['name' => true];

        foreach (['', '@id', 'schema:name', 'http://x/y', 'query-input', 'name'] as $property) {
            $this->assertTrue($list->isPropertyAllowed($allowed, $property), $property);
        }
        $this->assertFalse($list->isPropertyAllowed($allowed, 'datePublished'));
    }

    public function testFindInvalidWalksNestedNodes(): void
    {
        $node = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Product',
            'name'          => 'Shirt',
            'datePublished' => '2026-01-01',
            'offers'        => [
                ['@type' => 'Offer', 'price' => '1', 'color' => 'red'],
                ['@type' => 'Recipe', 'anything' => 1],
            ],
        ];

        $this->assertSame(
            [
                ['type' => 'Product', 'property' => 'datePublished'],
                ['type' => 'Offer', 'property' => 'color'],
            ],
            $this->sample()->findInvalid($node)
        );
    }

    public function testStripRemovesOnlyInvalidProperties(): void
    {
        $node = [
            '@type'  => ['Product'],
            'name'   => 'Shirt',
            'review' => 'x',
            'offers' => ['@type' => 'Offer', 'price' => '1', 'seller' => 'me'],
        ];

        $this->assertSame(
            ['@type' => ['Product'], 'name' => 'Shirt', 'offers' => ['@type' => 'Offer', 'price' => '1']],
            $this->sample()->strip($node)
        );
    }

    public function testRecursionStopsBeyondTheDepthLimit(): void
    {
        $deep = ['@type' => 'Offer', 'bogus' => 1];
        for ($i = 0; $i < 13; $i++) {
            $deep = ['child' => $deep];
        }
        $list = $this->sample();

        $this->assertSame([], $list->findInvalid($deep));
        $this->assertSame($deep, $list->strip($deep));
        $this->assertSame([['type' => 'Offer', 'property' => 'bogus']], $list->findInvalid($deep['child']));
    }
}
