<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Meta\Token\StoreToken;
use PHPUnit\Framework\TestCase;

class StoreTokenTest extends TestCase
{
    private function store(): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getCode')->willReturn('de');
        $store->method('getWebsiteId')->willReturn(5);
        $store->method('getBaseUrl')->willReturn('https://de.example.com/');
        $store->method('getName')->willReturn('German Store');

        return $store;
    }

    public function testArgumentsSelectStoreFields(): void
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($this->store());
        $token = new StoreToken($manager);

        $this->assertSame('German Store', $token->getValue(null, ['store_id' => 2]));
        $this->assertSame('2', $token->getValue(null, ['store_id' => 2], 'id'));
        $this->assertSame('de', $token->getValue(null, ['store_id' => 2], 'CODE'));
        $this->assertSame('5', $token->getValue(null, ['store_id' => 2], 'website'));
        $this->assertSame('https://de.example.com/', $token->getValue(null, ['store_id' => 2], 'url'));
        $this->assertSame('German Store', $token->getValue(null, ['store_id' => 2], 'unknown'));
    }

    public function testCurrentStoreIsUsedWithoutContext(): void
    {
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->expects($this->exactly(2))->method('getStore')->willReturn($this->store());

        $this->assertSame('de', (new StoreToken($manager))->getValue(null, [], 'code'));
    }

    public function testStoreFailureYieldsEmpty(): void
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $this->assertSame('', (new StoreToken($manager))->getValue(null, ['store_id' => 9]));
    }
}
