<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\ViewModel\Canonical;
use PHPUnit\Framework\TestCase;

class CanonicalCoverageTest extends TestCase
{
    private array $resolverCalls = [];

    private function viewModel(array $registry = [], array $request = [], array $config = []): Canonical
    {
        $this->resolverCalls = [];

        $resolver = $this->createStub(CanonicalResolverInterface::class);
        $resolver->method('getCanonicalUrl')->willReturnCallback(
            function (string $type, int $id, int $store, array $params = []): string {
                $this->resolverCalls[] = ['canonical', $type, $id, $store, $params];
                return 'https://shop.test/' . $type . '/' . $id;
            }
        );
        $resolver->method('normalize')->willReturnCallback(function (string $url, int $store): string {
            $this->resolverCalls[] = ['normalize', $url, $store];
            return $url;
        });

        $registryStub = $this->createStub(Registry::class);
        $registryStub->method('registry')->willReturnCallback(static fn(string $key) => $registry[$key] ?? null);

        $http = $this->createStub(Http::class);
        $params = $request['params'] ?? [];
        $http->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $http->method('getParams')->willReturn($params);
        $http->method('getRequestUri')->willReturn($request['uri'] ?? '/');
        $http->method('getFullActionName')->willReturn($request['action'] ?? 'cms_index_index');

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('isEnabled')->willReturn(true);
        $seoConfig->method('isCanonicalEnabled')->willReturn(true);
        $seoConfig->method('isNoindexNoRoute')->willReturn($config['noindex_noroute'] ?? false);
        $seoConfig->method('canonicalPaginatedToFirst')->willReturn($config['paginated_to_first'] ?? false);
        $seoConfig->method('isCanonicalDisabledForNoindex')->willReturn($config['disable_for_noindex'] ?? false);
        $seoConfig->method('getCanonicalIgnorePages')->willReturn($config['ignore'] ?? '');

        $assets = $this->createStub(GroupedCollection::class);
        $assets->method('getAll')->willReturn([]);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getAssetCollection')->willReturn($assets);
        $pageConfig->method('getRobots')->willReturn($request['robots'] ?? 'INDEX,FOLLOW');

        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $collection = $this->createStub(AttributeCollection::class);
            $collection->method('getIterator')->willReturn(new \ArrayIterator([new DataObject(['attribute_code' => 'color'])]));
            return $collection;
        });

        return new Canonical($resolver, $registryStub, $http, $storeManager, $seoConfig, $pageConfig, $factory);
    }

    public function testProductCanonicalIsDelegatedWithPathRobotsAndPage(): void
    {
        $url = $this->viewModel(
            ['current_product' => new DataObject(['id' => 5])],
            ['uri' => '/bottle.html?utm=x', 'params' => ['p' => '2']]
        )->getCanonicalUrl();

        $this->assertSame('https://shop.test/product/5', $url);
        $this->assertSame(
            ['canonical', 'product', 5, 1, ['current_path' => '/bottle.html', 'robots' => 'INDEX,FOLLOW', 'p' => 2]],
            $this->resolverCalls[0]
        );
    }

    public function testCmsPagesAreDelegatedToo(): void
    {
        $this->assertSame('https://shop.test/cms/3', $this->viewModel(['cms_page' => new DataObject(['id' => 3])])->getCanonicalUrl());
    }

    public function testFilteredCategoryKeepsItsPathAndPage(): void
    {
        $url = $this->viewModel(
            ['current_category' => new DataObject(['id' => 4])],
            ['uri' => '/gear.html?color=5&p=3', 'params' => ['color' => '5', 'p' => '3']]
        )->getCanonicalUrl();

        $this->assertSame('https://shop.test/gear.html?p=3', $url);
    }

    public function testFilteredCategoryDropsThePageWhenPaginationPointsToFirst(): void
    {
        $url = $this->viewModel(
            ['current_category' => new DataObject(['id' => 4])],
            ['uri' => '/gear.html', 'params' => ['color' => '5', 'p' => '3']],
            ['paginated_to_first' => true]
        )->getCanonicalUrl();

        $this->assertSame('https://shop.test/gear.html', $url);
    }

    public function testUnfilteredCategoryUsesTheResolver(): void
    {
        $url = $this->viewModel(['current_category' => new DataObject(['id' => 4])], ['params' => ['dir' => 'asc']])->getCanonicalUrl();

        $this->assertSame('https://shop.test/category/4', $url);
    }

    public function testNoRoutePageIsSuppressedWhenNoindexed(): void
    {
        $vm = $this->viewModel([], ['action' => 'cms_noroute_index'], ['noindex_noroute' => true]);

        $this->assertSame('', $vm->getCanonicalUrl());
    }

    public function testHomepageFallsBackToTheBaseUrl(): void
    {
        $this->assertSame('https://shop.test/', $this->viewModel([], ['uri' => '/index.php'])->getCanonicalUrl());
        $this->assertSame('https://shop.test/', $this->viewModel([], ['uri' => '/'])->getCanonicalUrl());
    }

    public function testSearchResultsKeepTheQuery(): void
    {
        $url = $this->viewModel([], [
            'uri' => '/catalogsearch/result/?q=red+shirt&order=price',
            'action' => 'catalogsearch_result_index',
            'params' => ['q' => ' red shirt '],
        ])->getCanonicalUrl();

        $this->assertSame('https://shop.test/catalogsearch/result/?q=red+shirt', $url);
    }

    public function testOtherPagesUseTheirPathWithoutQuery(): void
    {
        $url = $this->viewModel([], ['uri' => '/contact/?a=b', 'action' => 'contact_index_index'])->getCanonicalUrl();

        $this->assertSame('https://shop.test/contact/', $url);
    }

    public function testNoindexPagesAreSuppressedWhenConfigured(): void
    {
        $vm = $this->viewModel([], ['uri' => '/customer/account/', 'robots' => 'NOINDEX,NOFOLLOW'], ['disable_for_noindex' => true]);
        $this->assertSame('', $vm->getCanonicalUrl());

        $vm = $this->viewModel([], ['uri' => '/customer/account/', 'robots' => 'NOINDEX,NOFOLLOW']);
        $this->assertSame('https://shop.test/customer/account/', $vm->getCanonicalUrl());
    }

    public function testIgnoredPathsAreSuppressedByExactOrWildcardMatch(): void
    {
        $ignore = "checkout/cart/\r\n\n  /customer/*  ";

        $this->assertSame('', $this->viewModel([], ['uri' => '/checkout/cart/'], ['ignore' => $ignore])->getCanonicalUrl());
        $this->assertSame('', $this->viewModel([], ['uri' => '/customer/account/login/'], ['ignore' => $ignore])->getCanonicalUrl());
        $this->assertSame(
            'https://shop.test/sales/guest/',
            $this->viewModel([], ['uri' => '/sales/guest/'], ['ignore' => $ignore])->getCanonicalUrl()
        );
    }
}
