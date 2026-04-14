<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CategoryFilterMeta extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_category_filter_meta', 'id');
    }
}
