<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical as CustomCanonicalResource;

class CustomCanonical extends AbstractModel
{
    protected $_idFieldName = 'canonical_id';

    protected function _construct(): void
    {
        $this->_init(CustomCanonicalResource::class);
    }
}
