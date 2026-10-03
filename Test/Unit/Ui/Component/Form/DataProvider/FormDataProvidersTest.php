<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Form\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField\Collection as FeedFieldCollection;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField\CollectionFactory as FeedFieldCollectionFactory;
use Panth\AdvancedSEO\Model\ResourceModel\FeedProfile\Collection as FeedCollection;
use Panth\AdvancedSEO\Model\ResourceModel\FeedProfile\CollectionFactory as FeedCollectionFactory;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\Collection as RuleCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection as TemplateCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\FeedFieldFormDataProvider;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\FeedFormDataProvider;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\GenericFormDataProvider;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\RuleFormDataProvider;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\TemplateFormDataProvider;
use PHPUnit\Framework\TestCase;

class FormDataProvidersTest extends TestCase
{
    private array $filters = [];

    private int $loads = 0;

    private static function item(array $data): DataObject
    {
        return new class ($data) extends DataObject {
            public function getId()
            {
                return $this->getData('id');
            }
        };
    }

    private function collection(string $class, array $items): object
    {
        $this->filters = [];
        $this->loads = 0;
        $collection = $this->createStub($class);
        $collection->method('getItems')->willReturnCallback(function () use ($items) {
            $this->loads++;
            return $items;
        });
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
            $this->filters[] = [$field, $value];
            return $collection;
        });
        return $collection;
    }

    private function factory(string $factoryClass, object $collection): object
    {
        $factory = $this->createStub($factoryClass);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    public function testGenericProviderKeysItemsByIdAndCachesTheResult(): void
    {
        $provider = new GenericFormDataProvider('n', 'id', 'id', $this->collection(RuleCollection::class, [self::item(['id' => 3, 'name' => 'A'])]));

        $this->assertSame([3 => ['id' => 3, 'name' => 'A']], $provider->getData());
        $provider->getData();
        $this->assertSame(1, $this->loads);

        $empty = new GenericFormDataProvider('n', 'id', 'id', $this->collection(RuleCollection::class, []));
        $this->assertSame(['' => []], $empty->getData());
    }

    private function feedFieldProvider(array $params, array $items, ?int $sessionFeed = null): FeedFieldFormDataProvider
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturn($sessionFeed);

        return new FeedFieldFormDataProvider(
            'n',
            'field_id',
            'field_id',
            $this->factory(FeedFieldCollectionFactory::class, $this->collection(FeedFieldCollection::class, $items)),
            $request,
            $session
        );
    }

    public function testFeedFieldProviderLoadsTheRequestedField(): void
    {
        $provider = $this->feedFieldProvider(['field_id' => '5'], [self::item(['id' => 5, 'field_name' => 'title'])]);

        $this->assertSame([5 => ['id' => 5, 'field_name' => 'title']], $provider->getData());
        $this->assertSame([['field_id', 5]], $this->filters);
    }

    public function testFeedFieldProviderDefaultsANewFieldToTheCurrentFeed(): void
    {
        $this->assertSame(
            ['' => ['feed_id' => 4, 'source_type' => 'attribute', 'sort_order' => 0, 'is_required' => 0]],
            $this->feedFieldProvider(['feed_id' => 4], [])->getData()
        );
        $this->assertSame([], $this->filters);

        $this->assertSame(9, $this->feedFieldProvider([], [], 9)->getData()['']['feed_id']);
        $this->assertSame(0, $this->feedFieldProvider(['field_id' => 3], [], null)->getData()['']['feed_id']);
    }

    private function feedProvider(array $items, bool $storeFails = false): FeedFormDataProvider
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/media/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('store'));
        } else {
            $storeManager->method('getStore')->willReturn($store);
        }

        return new FeedFormDataProvider(
            'n',
            'feed_id',
            'id',
            $this->factory(FeedCollectionFactory::class, $this->collection(FeedCollection::class, $items)),
            new Json(),
            $storeManager
        );
    }

    public function testFeedProviderNormalisesStoredProfiles(): void
    {
        $data = $this->feedProvider([self::item([
            'id' => 2, 'filename' => 'g.xml', 'store_id' => '0', 'delivery_password' => 'secret',
            'category_ids' => '3,4', 'attribute_set_ids' => ' ', 'field_mapping' => '{bad json', 'conditions_serialized' => '{"a":1}',
        ])])->getData();

        $row = $data[2];
        $this->assertArrayNotHasKey('delivery_password', $row);
        $this->assertSame(['3', '4'], $row['category_ids']);
        $this->assertSame(' ', $row['attribute_set_ids']);
        $this->assertSame('', $row['field_mapping']);
        $this->assertSame('{"a":1}', $row['conditions_serialized']);
        $this->assertSame('https://shop.test/media/panth_seo/feeds/g.xml', $row['feed_file_url']);
    }

    public function testFeedProviderSkipsTheUrlWithoutAFilenameOrStore(): void
    {
        $this->assertArrayNotHasKey('feed_file_url', $this->feedProvider([self::item(['id' => 1, 'filename' => ''])])->getData()[1]);
        $this->assertArrayNotHasKey('feed_file_url', $this->feedProvider([self::item(['id' => 1, 'filename' => 'x.xml'])], true)->getData()[1]);
    }

    public function testFeedProviderDefaultsForANewProfile(): void
    {
        $defaults = $this->feedProvider([])->getData()[''];

        $this->assertSame('google_shopping', $defaults['feed_type']);
        $this->assertSame('0 1 * * *', $defaults['cron_schedule']);
        $this->assertSame('ftp', $defaults['delivery_type']);
    }

    private function templateProvider(array $items): TemplateFormDataProvider
    {
        return new TemplateFormDataProvider(
            'n',
            'template_id',
            'id',
            $this->factory(TemplateCollectionFactory::class, $this->collection(TemplateCollection::class, $items)),
            new Json()
        );
    }

    public function testTemplateProviderExposesTheFirstCondition(): void
    {
        $data = $this->templateProvider([
            self::item(['id' => 1, 'conditions_serialized' => '{"conditions":[{"attribute":"type_id","value":"simple"},{"attribute":"x"}]}']),
            self::item(['id' => 2, 'conditions_serialized' => '{}']),
            self::item(['id' => 3, 'conditions_serialized' => 'not json']),
            self::item(['id' => 4, 'conditions_serialized' => '{"conditions":[]}']),
            self::item(['id' => 5, 'conditions_serialized' => '{"conditions":["scalar"]}']),
            self::item(['id' => 6, 'conditions_serialized' => '"text"']),
        ])->getData();

        $this->assertSame('type_id', $data[1]['condition_attribute']);
        $this->assertSame('simple', $data[1]['condition_value']);
        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->assertArrayNotHasKey('condition_attribute', $data[$id]);
        }
        $this->assertSame('product', $this->templateProvider([])->getData()['']['entity_type']);
    }

    private function ruleProvider(array $items): RuleFormDataProvider
    {
        return new RuleFormDataProvider(
            'n',
            'rule_id',
            'id',
            $this->factory(RuleCollectionFactory::class, $this->collection(RuleCollection::class, $items)),
            new Json()
        );
    }

    public function testRuleProviderExposesConditionsAndActions(): void
    {
        $data = $this->ruleProvider([
            self::item([
                'id' => 1,
                'conditions_serialized' => '{"conditions":[{"attribute":"sku","value":"A"}]}',
                'actions_serialized' => '{"noindex":"1","title_template":"T","description_template":"D","canonical":"C"}',
            ]),
            self::item(['id' => 2, 'conditions_serialized' => '[]', 'actions_serialized' => '{"noindex":"","title_template":"T"}']),
            self::item(['id' => 3, 'conditions_serialized' => 'bad', 'actions_serialized' => 'bad']),
            self::item(['id' => 4, 'conditions_serialized' => '{"conditions":"x"}', 'actions_serialized' => '"scalar"']),
            self::item(['id' => 5, 'conditions_serialized' => '{"conditions":[5]}', 'actions_serialized' => '[]']),
        ])->getData();

        $this->assertSame('sku', $data[1]['condition_attribute']);
        $this->assertSame('A', $data[1]['condition_value']);
        $this->assertSame('1', $data[1]['action_noindex']);
        $this->assertSame('T', $data[1]['action_title_template']);
        $this->assertSame('D', $data[1]['action_description_template']);
        $this->assertSame('C', $data[1]['action_canonical']);
        $this->assertArrayNotHasKey('action_noindex', $data[2]);
        $this->assertSame('T', $data[2]['action_title_template']);
        foreach ([3, 4, 5] as $id) {
            $this->assertArrayNotHasKey('condition_attribute', $data[$id]);
            $this->assertArrayNotHasKey('action_title_template', $data[$id]);
        }
        $this->assertSame('0', $this->ruleProvider([])->getData()['']['stop_on_match']);
    }
}
