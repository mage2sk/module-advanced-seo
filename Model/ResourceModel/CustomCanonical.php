<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CustomCanonical extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_custom_canonical', 'canonical_id');
    }
}
