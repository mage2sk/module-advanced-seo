<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\ResourceModel;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\Collection as CustomCanonicalCollection;
use Panth\AdvancedSEO\Model\ResourceModel\FeedProfile\Collection as FeedProfileCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Override\Collection as OverrideCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\Collection as RuleCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Score\Collection as ScoreCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection as TemplateCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchResultCollectionsTest extends TestCase
{
    public static function collectionProvider(): array
    {
        return [
            [CustomCanonicalCollection::class],
            [FeedProfileCollection::class],
            [OverrideCollection::class],
            [RuleCollection::class],
            [ScoreCollection::class],
            [TemplateCollection::class],
        ];
    }

    #[DataProvider('collectionProvider')]
    public function testSearchResultContract(string $class): void
    {
        $collection = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(SearchResultInterface::class, $collection);

        $this->assertNull($collection->getAggregations());
        $aggregations = $this->createStub(AggregationInterface::class);
        $this->assertSame($collection, $collection->setAggregations($aggregations));
        $this->assertSame($aggregations, $collection->getAggregations());

        $this->assertNull($collection->getSearchCriteria());
        $this->assertSame($collection, $collection->setSearchCriteria($this->createStub(SearchCriteriaInterface::class)));
        $this->assertSame($collection, $collection->setSearchCriteria());
        $this->assertSame($collection, $collection->setTotalCount(5));
        $this->assertSame($collection, $collection->setItems([]));
        $this->assertSame($collection, $collection->setItems());
    }
}
