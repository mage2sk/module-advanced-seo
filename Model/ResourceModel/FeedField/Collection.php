<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel\FeedField;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdvancedSEO\Model\Feed\FeedField;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField as FeedFieldResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'field_id';

    protected function _construct(): void
    {
        $this->_init(FeedField::class, FeedFieldResource::class);
    }
}
