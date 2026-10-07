<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField as FeedFieldResource;

class FeedField extends AbstractModel
{
    protected $_idFieldName = 'field_id';
    protected $_eventPrefix = 'panth_seo_feed_field';

    protected function _construct(): void
    {
        $this->_init(FeedFieldResource::class);
    }
}
