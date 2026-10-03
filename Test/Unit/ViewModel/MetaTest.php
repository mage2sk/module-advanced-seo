<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\ViewModel\Meta;
use PHPUnit\Framework\TestCase;

class MetaTest extends TestCase
{
    private array $resolved = [];

    private function viewModel(array $registry = [], int $requestId = 0, bool $resolverFails = false, bool|\Throwable $enabled = true): Meta
    {
        $this->resolved = [];
        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $type, int $id, int $store) use ($resolverFails) {
            if ($resolverFails) {
                throw new \RuntimeException('resolver');
            }
            $this->resolved[] = [$type, $id, $store];
            $meta = new ResolvedMeta([
                'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'robots' => 'R',
            ]);
            $meta->setHreflangPayload(['en' => 'https://e.test/']);
            return $meta;
        });
        $registryStub = $this->createStub(Registry::class);
        $registryStub->method('registry')->willReturnCallback(static fn(string $key) => $registry[$key] ?? null);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $key === 'id' ? $requestId : $default);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        if ($enabled instanceof \Throwable) {
            $config->method('isEnabled')->willThrowException($enabled);
        } else {
            $config->method('isEnabled')->willReturn($enabled);
        }

        return new Meta($resolver, $registryStub, $request, $storeManager, $config);
    }

    public function testProductMetaIsResolvedOnceAndExposed(): void
    {
        $vm = $this->viewModel(['current_product' => new DataObject(['id' => 4])]);

        $this->assertSame('T', $vm->getTitle());
        $this->assertSame('D', $vm->getDescription());
        $this->assertSame('K', $vm->getKeywords());
        $this->assertSame('R', $vm->getRobots());
        $this->assertSame(['en' => 'https://e.test/'], $vm->getHreflang());
        $this->assertSame([['product', 4, 3]], $this->resolved);
    }

    public function testEntityDetectionOrder(): void
    {
        $this->viewModel(['current_category' => new DataObject(['id' => 2])])->resolveCurrent();
        $this->assertSame([['category', 2, 3]], $this->resolved);

        $this->viewModel(['cms_page' => new DataObject(['id' => 8]), 'current_product' => new DataObject([])])->resolveCurrent();
        $this->assertSame([['cms', 8, 3]], $this->resolved);

        $this->viewModel([], 11)->resolveCurrent();
        $this->assertSame([['other', 11, 3]], $this->resolved);
    }

    public function testNoEntityOrFailingResolverGivesEmptyValues(): void
    {
        $vm = $this->viewModel();
        $this->assertNull($vm->resolveCurrent());
        $this->assertSame('', $vm->getTitle());
        $this->assertSame([], $vm->getHreflang());

        $vm = $this->viewModel(['current_product' => new DataObject(['id' => 4])], 0, true);
        $this->assertNull($vm->resolveCurrent());
        $this->assertSame('', $vm->getRobots());
    }

    public function testIsEnabledSwallowsConfigErrors(): void
    {
        $this->assertTrue($this->viewModel()->isEnabled());
        $this->assertFalse($this->viewModel([], 0, false, false)->isEnabled());
        $this->assertFalse($this->viewModel([], 0, false, new \RuntimeException('cfg'))->isEnabled());
    }
}
