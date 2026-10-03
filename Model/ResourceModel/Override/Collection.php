<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\Override;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\Meta\Override as OverrideModel;
use Panth\AdvancedSEO\Model\ResourceModel\Override as OverrideResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'override_id';

    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(OverrideModel::class, OverrideResource::class);
    }

    public function getAggregations()
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria()
    {
        return null;
    }

    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null)
    {
        return $this;
    }

    public function getTotalCount()
    {
        return $this->getSize();
    }

    public function setTotalCount($totalCount)
    {
        return $this;
    }

    public function setItems(?array $items = null)
    {
        return $this;
    }
}
