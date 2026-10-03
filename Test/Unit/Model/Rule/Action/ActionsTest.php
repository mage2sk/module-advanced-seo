<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule\Action;

use Magento\Framework\DataObject;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Rule\Action\Canonical;
use Panth\AdvancedSEO\Model\Rule\Action\Noindex;
use Panth\AdvancedSEO\Model\Rule\Action\Template;
use PHPUnit\Framework\TestCase;

class ActionsTest extends TestCase
{
    private function canonical(string $baseUrl = 'https://shop.test/'): Canonical
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Canonical($storeManager);
    }

    public function testCanonicalEmptyLeavesOutputUntouched(): void
    {
        $output = ['title' => 'x'];
        $this->assertSame($output, $this->canonical()->apply(['canonical' => '   '], $output));
        $this->assertSame($output, $this->canonical()->apply([], $output));
    }

    public function testCanonicalAbsoluteUrlKept(): void
    {
        $result = $this->canonical()->apply(['canonical' => ' HTTPS://other.test/a '], []);
        $this->assertSame('HTTPS://other.test/a', $result['canonical']);
    }

    public function testCanonicalRelativeUrlPrefixedWithBase(): void
    {
        $result = $this->canonical('https://shop.test/')->apply(['canonical' => '/shoes.html'], []);
        $this->assertSame('https://shop.test/shoes.html', $result['canonical']);
    }

    public function testCanonicalStoreFailureKeepsRelativeUrl(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $result = (new Canonical($storeManager))->apply(['canonical' => 'shoes.html'], []);
        $this->assertSame('shoes.html', $result['canonical']);
    }

    public function testNoindexDefaults(): void
    {
        $result = (new Noindex())->apply([], ['keep' => 1]);

        $this->assertTrue($result['noindex']);
        $this->assertFalse($result['nofollow']);
        $this->assertSame('noindex,follow', $result['robots']);
        $this->assertSame(1, $result['keep']);
    }

    public function testNoindexExplicitFlags(): void
    {
        $result = (new Noindex())->apply(['noindex' => '0', 'nofollow' => '1'], []);

        $this->assertFalse($result['noindex']);
        $this->assertTrue($result['nofollow']);
        $this->assertSame('index,nofollow', $result['robots']);
    }

    public function testTemplateApplyMapsKeysAndSkipsEmpty(): void
    {
        $result = (new Template())->apply(
            ['title_template' => 'Buy {{ name }}', 'description_template' => '', 'og_template' => '{{name}} OG'],
            ['name' => 'Boots'],
            ['description' => 'existing']
        );

        $this->assertSame('Buy Boots', $result['title']);
        $this->assertSame('existing', $result['description']);
        $this->assertSame('Boots OG', $result['og_title']);
    }

    public function testTemplateRenderFlattensNestedArraysAndObjects(): void
    {
        $context = [
            'product' => new DataObject(['name' => 'Boot', 'price' => 9.5, 'flag' => null]),
            'store' => ['name' => 'Main', 'meta' => ['code' => 'en']],
            'skip' => new \stdClass(),
        ];

        $rendered = (new Template())->render(
            '{{product.name}}|{{product.price}}|{{product.flag}}|{{store.meta.code}}|{{missing}}|{{skip}}',
            $context
        );

        $this->assertSame('Boot|9.5||en||', $rendered);
    }

    public function testTemplateRenderLeavesNonTokenText(): void
    {
        $this->assertSame('{bad} plain', (new Template())->render('{bad} plain', []));
    }
}
