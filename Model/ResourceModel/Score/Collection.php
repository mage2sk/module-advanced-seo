<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\Score;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\Score as ScoreModel;
use Panth\AdvancedSEO\Model\ResourceModel\Score as ScoreResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'score_id';

    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(ScoreModel::class, ScoreResource::class);
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
