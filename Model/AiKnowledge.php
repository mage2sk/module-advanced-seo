<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model;

use Magento\Framework\Model\AbstractModel;

class AiKnowledge extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Panth\AdvancedSEO\Model\ResourceModel\AiKnowledge::class);
    }
}
