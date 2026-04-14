<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\FilterRewrite;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\FilterUrl\FilterRewrite as FilterRewriteModel;
use Panth\AdvancedSEO\Model\ResourceModel\FilterRewrite as FilterRewriteResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'rewrite_id';

    /**
     * @var \Magento\Framework\Api\Search\AggregationInterface
     */
    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(FilterRewriteModel::class, FilterRewriteResource::class);
    }

    /**
     * @inheritdoc
     */
    public function getAggregations()
    {
        return $this->aggregations;
    }

    /**
     * @inheritdoc
     */
    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getSearchCriteria()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria = null)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTotalCount()
    {
        return $this->getSize();
    }

    /**
     * @inheritdoc
     */
    public function setTotalCount($totalCount)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setItems(array $items = null)
    {
        return $this;
    }
}
