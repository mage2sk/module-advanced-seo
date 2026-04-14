<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\AiPrompt;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\AiPrompt as AiPromptModel;
use Panth\AdvancedSEO\Model\ResourceModel\AiPrompt as AiPromptResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'prompt_id';

    /**
     * @var AggregationInterface
     */
    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(AiPromptModel::class, AiPromptResource::class);
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
