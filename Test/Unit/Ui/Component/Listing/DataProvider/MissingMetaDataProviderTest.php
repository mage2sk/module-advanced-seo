<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DocumentFactory;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\MissingMetaDataProvider;
use PHPUnit\Framework\TestCase;

class MissingMetaDataProviderTest extends TestCase
{
    use CollectionRecorder;

    private array $sql = [];

    private function provider(
        array $params = [],
        array $server = [],
        array $session = [],
        array $items = [],
        array $db = []
    ): MissingMetaDataProvider {
        $this->calls = [];
        $this->sql = [];
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $request->method('getServer')->willReturnCallback(static fn($key) => $server['referer'] ?? null);
        $request->method('getRequestUri')->willReturn($server['uri'] ?? '');

        $products = $this->createStub(ProductCollectionFactory::class);
        $products->method('create')->willReturnCallback(fn() => $this->recordingCollection(ProductCollection::class, $items, count($items)));
        $categories = $this->createStub(CategoryCollectionFactory::class);
        $categories->method('create')->willReturnCallback(fn() => $this->recordingCollection(CategoryCollection::class, $items, count($items)));

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) use ($db) {
            $this->sql[] = $sql;
            if ($db['fails'] ?? false) {
                throw new \RuntimeException('db');
            }
            if (str_contains($sql, 'attribute_set_name')) {
                return $db['set_names'][$bind[0]] ?? false;
            }
            return $db['name_attr'] ?? false;
        });
        $connection->method('fetchPairs')->willReturn($db['names'] ?? []);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $documents = $this->createStub(DocumentFactory::class);
        $documents->method('create')->willReturnCallback(static fn() => new DataObject());

        return new MissingMetaDataProvider(
            'n',
            'entity_id',
            'id',
            $products,
            $categories,
            $request,
            $this->recordingSession($session),
            $resource,
            $documents
        );
    }

    public function testProductRowsResolveAttributeSetNamesOnce(): void
    {
        $provider = $this->provider(['type' => 'product', 'store' => '2'], [], [], [
            new DataObject(['entity_id' => '4', 'sku' => 'S', 'name' => 'N', 'type_id' => 'simple', 'attribute_set_id' => '4']),
            new DataObject(['entity_id' => '5', 'attribute_set_id' => '4']),
            new DataObject(['entity_id' => '6', 'attribute_set_id' => '9']),
            new DataObject(['entity_id' => '7']),
        ], ['set_names' => [4 => 'Default']]);

        $data = $provider->getData();

        $this->assertSame(4, $data['totalRecords']);
        $this->assertSame(
            ['entity_id' => 4, 'sku' => 'S', 'name' => 'N', 'meta_title' => '', 'meta_description' => '', 'type_id' => 'simple', 'attribute_set_id' => 'Default'],
            $data['items'][0]
        );
        $this->assertSame('9', $data['items'][2]['attribute_set_id']);
        $this->assertSame('', $data['items'][3]['attribute_set_id']);
        $this->assertCount(2, $this->sql);
        $this->assertSame(['panth_seo_missing_meta_type' => 'product', 'panth_seo_missing_meta_store' => 2], $this->sessionWrites);
        $this->assertSame([[2]], $this->callsTo('addStoreFilter'));
        $this->assertCount(4, $this->callsTo('addAttributeToFilter')[0][0]);
    }

    public function testCategoryRowsShowLevelAndNamedPath(): void
    {
        $provider = $this->provider(['type' => 'category'], [], [], [
            new DataObject(['entity_id' => 9, 'name' => 'Shirts', 'level' => 3, 'path' => '1/2/5/9']),
            new DataObject(['entity_id' => 2, 'level' => 2, 'path' => '1/2']),
            new DataObject(['entity_id' => 3, 'level' => 2, 'path' => '']),
        ], ['name_attr' => '45', 'names' => [5 => 'Men']]);

        $items = $provider->getData()['items'];

        $this->assertSame('Level 3', $items[0]['type_id']);
        $this->assertSame('Men > ID:9', $items[0]['attribute_set_id']);
        $this->assertSame('', $items[0]['sku']);
        $this->assertSame('Root', $items[1]['attribute_set_id']);
        $this->assertSame('', $items[2]['attribute_set_id']);
        $this->assertSame([['level', ['gt' => 1]]], $this->callsTo('addFieldToFilter'));
    }

    public function testCategoryPathFallsBackToIdsWithoutTheNameAttribute(): void
    {
        $items = $this->provider(['type' => 'category'], [], [], [new DataObject(['entity_id' => 9, 'path' => '1/2/5/9'])])->getData()['items'];
        $this->assertSame('5 / 9', $items[0]['attribute_set_id']);

        $items = $this->provider(['type' => 'category'], [], [], [new DataObject(['entity_id' => 9, 'path' => '1/2/5'])], ['fails' => true])->getData()['items'];
        $this->assertSame('', $items[0]['attribute_set_id']);
    }

    public function testTypeIsResolvedFromRefererUriOrSession(): void
    {
        $this->provider([], ['referer' => 'https://a/report/missingMeta/type/category/'])->getData();
        $this->assertSame('category', $this->sessionWrites['panth_seo_missing_meta_type']);

        $this->provider([], ['uri' => '/admin/panth_seo/report/missingMeta/type/category'])->getData();
        $this->assertSame('category', $this->sessionWrites['panth_seo_missing_meta_type']);

        $this->provider([], ['referer' => 'https://a/x/store/4/'], ['panth_seo_missing_meta_type' => 'category'])->getData();
        $this->assertArrayNotHasKey('panth_seo_missing_meta_type', $this->sessionWrites);
        $this->assertSame(4, $this->sessionWrites['panth_seo_missing_meta_store']);
        $this->assertSame([['level', ['gt' => 1]]], $this->callsTo('addFieldToFilter'));

        $this->provider([], [], ['panth_seo_missing_meta_type' => 'cms', 'panth_seo_missing_meta_store' => '6'])->getData();
        $this->assertSame([[6]], $this->callsTo('addStoreFilter'));
    }

    public function testFiltersAndOrdersAreRouted(): void
    {
        $provider = $this->provider(['type' => 'product']);
        $provider->getData();
        $this->calls = [];

        $provider->addFilter((new Filter())->setField('name')->setValue('%a%')->setConditionType('like'));
        $provider->addFilter((new Filter())->setField('sku')->setValue('X')->setConditionType('eq'));
        $provider->addFilter((new Filter())->setField('status')->setValue('1')->setConditionType('eq'));
        $provider->addOrder('meta_title', 'ASC');
        $provider->addOrder('sku', 'DESC');

        $this->assertSame([['name', ['like' => '%a%']]], $this->callsTo('addAttributeToFilter'));
        $this->assertSame([['sku', ['eq' => 'X']]], $this->callsTo('addFieldToFilter'));
        $this->assertSame([['meta_title', 'ASC']], $this->callsTo('addAttributeToSort'));
        $this->assertSame([['sku', 'DESC']], $this->callsTo('setOrder'));
    }

    public function testExportSearchResultWrapsRowsInDocuments(): void
    {
        $provider = $this->provider(['type' => 'product'], [], [], [new DataObject(['entity_id' => 3, 'sku' => 'A'])]);
        $criteria = $provider->getSearchCriteria();
        $this->assertSame($criteria, $provider->getSearchCriteria());
        $criteria->setData(['page_size' => 50, 'current_page' => 2]);

        $result = $provider->getSearchResult();

        $this->assertSame(1, $result->getData('total_count'));
        $document = $result->getData('items')[0];
        $this->assertSame('A', $document->getData('sku'));
        $this->assertSame(3, $document->getData('id'));
        $this->assertSame([[50]], $this->callsTo('setPageSize'));
        $this->assertSame([[2]], $this->callsTo('setCurPage'));
    }

    public function testExportWithoutPagingLoadsEverything(): void
    {
        $provider = $this->provider(['type' => 'product']);
        $provider->getSearchResult();

        $this->assertSame([[false]], $this->callsTo('setPageSize'));
        $this->assertSame([[1]], $this->callsTo('setCurPage'));
    }
}
