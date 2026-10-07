<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Embedding extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_meta_embedding', 'embedding_id');
    }
}
