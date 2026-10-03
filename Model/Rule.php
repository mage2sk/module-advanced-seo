<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\Rule as RuleResource;

class Rule extends AbstractModel
{
    protected $_idFieldName = 'rule_id';

    protected function _construct(): void
    {
        $this->_init(RuleResource::class);
    }
}
