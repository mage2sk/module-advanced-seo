<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\Collection as RuleCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Panth\AdvancedSEO\Model\Rule\Condition\Combine;
use Panth\AdvancedSEO\Model\Rule\Engine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EngineLoadingTest extends TestCase
{
    private function collection(array $rows, array &$filters): RuleCollection
    {
        $collection = $this->createStub(RuleCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $cond) use (&$filters, &$collection) {
                $filters[$field] = $cond;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(
            static fn () => new \ArrayIterator(array_map(static fn ($r) => new DataObject($r), $rows))
        );
        return $collection;
    }

    public function testCacheMissLoadsCollectionSavesCacheAndUsesRuntimeCache(): void
    {
        $filters = [];
        $collection = $this->collection([
            ['rule_id' => 3, 'conditions_serialized' => '[]', 'actions_serialized' => '{"noindex":"1"}'],
        ], $filters);

        $factory = $this->createMock(RuleCollectionFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($collection);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('load')->with('panth_seo_rules_2_cms')->willReturn(false);
        $cache->expects($this->once())->method('save')
            ->with($this->isString(), 'panth_seo_rules_2_cms', ['panth_seo_rule'], 3600);

        $combine = $this->createStub(Combine::class);
        $combine->method('evaluate')->willReturn(true);

        $engine = new Engine($factory, $combine, new Json(), $cache, $this->createStub(LoggerInterface::class));

        $first = $engine->evaluate('cms', 1, 2);
        $second = $engine->evaluate('cms', 4, 2);

        $this->assertSame('1', $first['noindex']);
        $this->assertSame([3], $second['matched_rules']);
        $this->assertSame(1, $filters['is_active']);
        $this->assertSame(['cms', 'all', 'cms_page'], array_values($filters['entity_type']['in']));
        $this->assertSame(['in' => [2, 0]], $filters['store_id']);
    }

    public function testCorruptCacheFallsBackToCollection(): void
    {
        $filters = [];
        $factory = $this->createMock(RuleCollectionFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($this->collection([], $filters));

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{not json');

        $engine = new Engine(
            $factory,
            $this->createStub(Combine::class),
            new Json(),
            $cache,
            $this->createStub(LoggerInterface::class)
        );

        $result = $engine->evaluate('product', 1, 1);

        $this->assertSame([], $result['matched_rules']);
        $this->assertNull($result['noindex']);
        $this->assertSame(['in' => ['product', 'all']], $filters['entity_type']);
    }

    public function testInvalidConditionsAreLoggedAndSkipped(): void
    {
        $serializer = new Json();
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn($serializer->serialize([
            ['rule_id' => 5, 'conditions_serialized' => '{broken', 'actions_serialized' => '{}'],
            ['rule_id' => 6, 'conditions_serialized' => '[]', 'actions_serialized' => '{broken'],
        ]));

        $combine = $this->createStub(Combine::class);
        $combine->method('evaluate')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO rule conditions invalid', $this->callback(
                static fn (array $ctx): bool => $ctx['rule_id'] === 5
            ));

        $engine = new Engine(
            $this->createStub(RuleCollectionFactory::class),
            $combine,
            $serializer,
            $cache,
            $logger
        );

        $result = $engine->evaluate('product', 1, 1);
        $this->assertSame([6], $result['matched_rules']);
    }

    public function testNonMatchingRuleAndEmptyActionValuesIgnored(): void
    {
        $serializer = new Json();
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn($serializer->serialize([
            ['rule_id' => 1, 'conditions_serialized' => '{"skip":1}', 'actions_serialized' => '{"noindex":"1"}'],
            ['rule_id' => 2, 'conditions_serialized' => '[]', 'actions_serialized' => '{"noindex":"","nofollow":null}'],
        ]));

        $combine = $this->createStub(Combine::class);
        $combine->method('evaluate')->willReturnCallback(
            static fn (array $conditions, array $context): bool => !isset($conditions['skip'])
                && $context['entity_type'] === 'category'
        );

        $engine = new Engine(
            $this->createStub(RuleCollectionFactory::class),
            $combine,
            $serializer,
            $cache,
            $this->createStub(LoggerInterface::class)
        );

        $result = $engine->evaluate('category', 1, 1);

        $this->assertNull($result['noindex']);
        $this->assertNull($result['nofollow']);
        $this->assertSame([2], $result['matched_rules']);
    }
}
