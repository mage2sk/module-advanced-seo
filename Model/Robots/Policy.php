<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Robots;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\RobotsPolicy as RobotsPolicyResource;

class Policy extends AbstractModel
{
    protected $_idFieldName = 'policy_id';

    protected function _construct(): void
    {
        $this->_init(RobotsPolicyResource::class);
    }
}
