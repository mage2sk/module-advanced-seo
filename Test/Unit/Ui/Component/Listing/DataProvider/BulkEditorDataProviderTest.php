<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection as CmsCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\BulkEditorDataProvider;
use PHPUnit\Framework\TestCase;

class BulkEditorDataProviderTest extends TestCase
{
    use CollectionRecorder;

    private static function entity(array $data): DataObject
    {
        return new class ($data) extends DataObject {
            public function getId()
            {
                return $this->getData('id');
            }
        };
    }

    private function provider(array $params = [], string $referer = '', array $session = [], array $items = []): BulkEditorDataProvider
    {
        $this->calls = [];
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $request->method('getServer')->willReturnCallback(static fn($key) => $key === 'HTTP_REFERER' ? $referer : null);

        $products = $this->createStub(ProductCollectionFactory::class);
        $products->method('create')->willReturnCallback(fn() => $this->recordingCollection(ProductCollection::class, $items, count($items)));
        $categories = $this->createStub(CategoryCollectionFactory::class);
        $categories->method('create')->willReturnCallback(fn() => $this->recordingCollection(CategoryCollection::class, $items, count($items)));
        $cms = $this->createStub(CmsCollectionFactory::class);
        $cms->method('create')->willReturnCallback(fn() => $this->recordingCollection(CmsCollection::class, $items, count($items)));

        return new BulkEditorDataProvider('n', 'entity_id', 'id', $products, $categories, $cms, $this->recordingSession($session), $request);
    }

    private static function filter(string $field, mixed $value, string $condition = 'like'): Filter
    {
        return (new Filter())->setField($field)->setValue($value)->setConditionType($condition);
    }

    public function testProductRowsForTheRequestedStore(): void
    {
        $provider = $this->provider(['type' => 'product', 'store' => '2'], '', [], [
            self::entity(['id' => '4', 'sku' => 'S', 'name' => 'N', 'meta_title' => 'T', 'meta_description' => null]),
        ]);

        $this->assertSame(['panth_seo_bulkeditor_type' => 'product', 'panth_seo_bulkeditor_store' => 2], $this->sessionWrites);
        $this->assertSame([[2]], $this->callsTo('addStoreFilter'));
        $this->assertSame([
            'totalRecords' => 1,
            'items' => [['entity_id' => 4, 'sku' => 'S', 'name' => 'N', 'meta_title' => 'T', 'meta_description' => '']],
        ], $provider->getData());
    }

    public function testTypeAndStoreFallBackToRefererThenSession(): void
    {
        $this->provider([], 'https://admin.test/panth_seo/bulkeditor/index/type/category/store/3/', ['panth_seo_bulkeditor_type' => 'cms']);
        $this->assertSame('category', $this->sessionWrites['panth_seo_bulkeditor_type']);
        $this->assertSame(3, $this->sessionWrites['panth_seo_bulkeditor_store']);
        $this->assertSame([[['gteq' => 2]]], array_map(static fn($a) => [$a[1]], $this->callsTo('addFieldToFilter')));

        $this->provider(['type' => 'brand', 'store' => '-4'], '', ['panth_seo_bulkeditor_type' => 'cms', 'panth_seo_bulkeditor_store' => 5]);
        $this->assertSame('cms', $this->sessionWrites['panth_seo_bulkeditor_type']);
        $this->assertSame(0, $this->sessionWrites['panth_seo_bulkeditor_store']);

        $this->provider([], '', ['panth_seo_bulkeditor_type' => 'weird', 'panth_seo_bulkeditor_store' => '7']);
        $this->assertSame('product', $this->sessionWrites['panth_seo_bulkeditor_type']);
        $this->assertSame(7, $this->sessionWrites['panth_seo_bulkeditor_store']);
    }

    public function testCategoryAndCmsRowsUseTheirOwnFields(): void
    {
        $category = $this->provider(['type' => 'category'], '', [], [
            self::entity(['id' => 3, 'url_key' => 'gear', 'name' => 'Gear', 'meta_title' => 'T', 'meta_description' => 'D']),
        ])->getData();
        $this->assertSame(
            ['entity_id' => 3, 'sku' => 'gear', 'url_key' => 'gear', 'name' => 'Gear', 'meta_title' => 'T', 'meta_description' => 'D'],
            $category['items'][0]
        );

        $cms = $this->provider(['type' => 'cms', 'store' => 1], '', [], [
            self::entity(['id' => 8, 'identifier' => 'about', 'title' => 'About']),
        ]);
        $this->assertSame([[1]], $this->callsTo('addStoreFilter'));
        $this->assertSame(
            ['entity_id' => 8, 'sku' => 'about', 'identifier' => 'about', 'name' => 'About', 'title' => 'About', 'meta_title' => '', 'meta_description' => ''],
            $cms->getData()['items'][0]
        );
    }

    public function testFiltersAreRoutedPerEntityType(): void
    {
        $provider = $this->provider(['type' => 'product']);
        $this->calls = [];
        $provider->addFilter(self::filter('entity_id', '5', 'eq'));
        $provider->addFilter(self::filter('store_id', '3', 'eq'));
        $provider->addFilter(self::filter('store_id', '0', 'eq'));
        $provider->addFilter(self::filter('sku', '%A%'));
        $provider->addFilter(self::filter('status', '1', 'eq'));

        $this->assertSame([['entity_id', ['eq' => '5']], ['status', ['eq' => '1']]], $this->callsTo('addFieldToFilter'));
        $this->assertSame([[3]], $this->callsTo('setStoreId'));
        $this->assertSame([['sku', ['like' => '%A%']]], $this->callsTo('addAttributeToFilter'));

        $cms = $this->provider(['type' => 'cms']);
        $this->calls = [];
        $cms->addFilter(self::filter('entity_id', '5', 'eq'));
        $cms->addFilter(self::filter('title', '%x%'));
        $cms->addFilter(self::filter('store_id', '2', 'eq'));
        $this->assertSame([['page_id', ['eq' => '5']], ['title', ['like' => '%x%']]], $this->callsTo('addFieldToFilter'));
        $this->assertSame([], $this->callsTo('setStoreId'));
    }

    public function testOrdersAreRoutedPerEntityType(): void
    {
        $provider = $this->provider(['type' => 'category']);
        $this->calls = [];
        $provider->addOrder('entity_id', 'DESC');
        $provider->addOrder('url_key', 'ASC');
        $provider->addOrder('position', 'ASC');

        $this->assertSame([['entity_id', 'DESC']], $this->callsTo('setOrder'));
        $this->assertSame([['url_key', 'ASC']], $this->callsTo('addAttributeToSort'));
        $this->assertSame([['position', 'ASC']], $this->callsTo('addOrder'));

        $cms = $this->provider(['type' => 'cms']);
        $this->calls = [];
        $cms->addOrder('entity_id', 'ASC');
        $cms->addOrder('name', 'DESC');
        $this->assertSame([['page_id', 'ASC'], ['name', 'DESC']], $this->callsTo('setOrder'));
    }
}
