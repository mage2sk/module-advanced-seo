<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CrawlResult extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_crawl_result', 'id');
    }
}
