<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\NotFoundLog as NotFoundLogResource;

class NotFoundLog extends AbstractModel
{
    protected $_idFieldName = 'log_id';
    protected $_eventPrefix = 'panth_seo_404_log';

    protected function _construct(): void
    {
        $this->_init(NotFoundLogResource::class);
    }
}
