<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonical as CustomCanonicalModel;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical as CustomCanonicalResource;

/**
 * Custom canonical collection with SearchResultInterface for UI component grids.
 */
class Collection extends AbstractCollection implements SearchResultInterface
{
    /** @var string */
    protected $_idFieldName = 'canonical_id';

    private ?AggregationInterface $aggregations = null;

    protected function _construct(): void
    {
        $this->_init(CustomCanonicalModel::class, CustomCanonicalResource::class);
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
