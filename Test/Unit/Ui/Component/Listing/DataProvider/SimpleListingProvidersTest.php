<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\Collection as CanonicalCollection;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\CollectionFactory as CanonicalCollectionFactory;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\CustomCanonicalDataProvider;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\FeedFieldDataProvider;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\RuleDataProvider;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\ScoreDataProvider;
use Panth\AdvancedSEO\Ui\Component\Listing\DataProvider\TemplateDataProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimpleListingProvidersTest extends TestCase
{
    use ConnectionRecorder;

    private array $addedFilters = [];

    private function searchArgs(array $params = []): array
    {
        $this->addedFilters = [];
        $criteria = $this->createStub(SearchCriteria::class);
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('create')->willReturn($criteria);
        $builder->method('addFilter')->willReturnCallback(function (Filter $filter) use ($builder) {
            $this->addedFilters[] = [$filter->getField(), $filter->getValue(), $filter->getConditionType()];
            return $builder;
        });
        $result = $this->createStub(SearchResultInterface::class);
        $result->method('getItems')->willReturn([]);
        $result->method('getTotalCount')->willReturn(0);
        $reporting = $this->createStub(ReportingInterface::class);
        $reporting->method('search')->willReturn($result);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);

        $filter = new Filter();
        $filterBuilder = $this->createStub(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnCallback(static function ($v) use ($filterBuilder, $filter) {
            $filter->setField($v);
            return $filterBuilder;
        });
        $filterBuilder->method('setValue')->willReturnCallback(static function ($v) use ($filterBuilder, $filter) {
            $filter->setValue($v);
            return $filterBuilder;
        });
        $filterBuilder->method('setConditionType')->willReturnCallback(static function ($v) use ($filterBuilder, $filter) {
            $filter->setConditionType($v);
            return $filterBuilder;
        });
        $filterBuilder->method('create')->willReturn($filter);

        return [$reporting, $builder, $request, $filterBuilder];
    }

    public static function tableProviders(): array
    {
        return [
            'template' => [TemplateDataProvider::class],
            'rule'     => [RuleDataProvider::class],
            'score'    => [ScoreDataProvider::class],
        ];
    }

    #[DataProvider('tableProviders')]
    public function testRowsAreReadStraightFromTheTable(string $class): void
    {
        [$reporting, $builder, $request, $filterBuilder] = $this->searchArgs();
        $rows = [['id' => 1], ['id' => 2]];
        $provider = new $class('n', 'id', 'id', $reporting, $builder, $request, $filterBuilder, $this->recordingResource([], [$rows]));

        $this->assertSame(['totalRecords' => 2, 'items' => $rows], $provider->getData());

        [$reporting, $builder, $request, $filterBuilder] = $this->searchArgs();
        $missing = new $class('n', 'id', 'id', $reporting, $builder, $request, $filterBuilder, $this->recordingResource([], [$rows], null, [], false));
        $this->assertSame(['totalRecords' => 0, 'items' => []], $missing->getData());
    }

    public function testCustomCanonicalProviderLoadsOnceAndListsItems(): void
    {
        $loaded = false;
        $loads = 0;
        $collection = $this->createStub(CanonicalCollection::class);
        $collection->method('isLoaded')->willReturnCallback(static function () use (&$loaded) {
            return $loaded;
        });
        $collection->method('load')->willReturnCallback(static function () use (&$loaded, &$loads, $collection) {
            $loaded = true;
            $loads++;
            return $collection;
        });
        $collection->method('getIterator')->willReturnCallback(static fn() => new \ArrayIterator([new DataObject(['canonical_id' => 1])]));
        $collection->method('getSize')->willReturn(1);
        $factory = $this->createStub(CanonicalCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $provider = new CustomCanonicalDataProvider('n', 'canonical_id', 'id', $factory);

        $this->assertSame(['totalRecords' => 1, 'items' => [['canonical_id' => 1]]], $provider->getData());
        $provider->getData();
        $this->assertSame(1, $loads);
    }

    private function feedFieldProvider(array $params, ?int $sessionFeed): FeedFieldDataProvider
    {
        [$reporting, $builder, $request, $filterBuilder] = $this->searchArgs($params);
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturn($sessionFeed);

        return new FeedFieldDataProvider('n', 'field_id', 'id', $reporting, $builder, $request, $filterBuilder, $session);
    }

    public function testFeedFieldListIsFilteredByTheCurrentFeedOnce(): void
    {
        $provider = $this->feedFieldProvider(['feed_id' => '6'], 9);
        $provider->getData();
        $provider->getData();
        $this->assertSame([['feed_id', 6, 'eq']], $this->addedFilters);

        $this->feedFieldProvider([], 9)->getData();
        $this->assertSame([['feed_id', 9, 'eq']], $this->addedFilters);

        $this->feedFieldProvider([], null)->getData();
        $this->assertSame([], $this->addedFilters);
    }
}
