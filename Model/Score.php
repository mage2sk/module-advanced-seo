<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\Score as ScoreResource;

class Score extends AbstractModel
{
    protected $_idFieldName = 'score_id';

    protected function _construct(): void
    {
        $this->_init(ScoreResource::class);
    }
}
