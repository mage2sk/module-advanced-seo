<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\ViewModel\StoreScopeSwitcher;
use PHPUnit\Framework\TestCase;

class StoreScopeSwitcherTest extends TestCase
{
    private function switcher(mixed $storeParam, array $stores = [1 => 'Default Store View', 2 => 'Luma Store View']): StoreScopeSwitcher
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $key === StoreScopeSwitcher::PARAM ? $storeParam : $default
        );
        $request->method('getParams')->willReturn(['type' => 'product']);

        $storeObjects = [];
        foreach ($stores as $id => $name) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getName')->willReturn($name);
            $storeObjects[$id] = $store;
        }

        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($storeObjects);
        $repository->method('getById')->willReturnCallback(
            static function (int $id) use ($storeObjects) {
                if (!isset($storeObjects[$id])) {
                    throw new \Magento\Framework\Exception\NoSuchEntityException(__('no store'));
                }
                return $storeObjects[$id];
            }
        );

        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturn('https://example.com/admin/');

        return new StoreScopeSwitcher($request, $repository, $url);
    }

    public function testMissingParameterMeansDefaultScope(): void
    {
        $this->assertSame(0, $this->switcher(null)->getCurrentStoreId());
        $this->assertSame(0, $this->switcher('')->getCurrentStoreId());
    }

    public function testKnownStoreIsAccepted(): void
    {
        $this->assertSame(2, $this->switcher('2')->getCurrentStoreId());
    }

    public function testUnknownStoreFallsBackToDefaultScope(): void
    {
        $this->assertSame(0, $this->switcher('99')->getCurrentStoreId());
    }

    public function testNegativeAndNonNumericValuesFallBackToDefaultScope(): void
    {
        $this->assertSame(0, $this->switcher('-3')->getCurrentStoreId());
        $this->assertSame(0, $this->switcher('nonsense')->getCurrentStoreId());
    }

    public function testOptionsStartWithTheDefaultScopeAndSkipTheAdminStore(): void
    {
        $options = $this->switcher(null, [0 => 'Admin', 1 => 'Default Store View'])->getStoreOptions();

        $this->assertSame(0, $options[0]['value']);
        $this->assertCount(2, $options);
        $this->assertSame(1, $options[1]['value']);
    }

    public function testHasMultipleStoresOnlyWhenMoreThanOneStoreViewExists(): void
    {
        $this->assertFalse($this->switcher(null, [1 => 'Default Store View'])->hasMultipleStores());
        $this->assertTrue($this->switcher(null)->hasMultipleStores());
    }
}
