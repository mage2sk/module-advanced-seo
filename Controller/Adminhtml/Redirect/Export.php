<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Redirect;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Backend\App\Action\Context;

class Export extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::redirects';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName('panth_seo_redirect'))
        );

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['pattern', 'target', 'match_type', 'status_code', 'store_id', 'is_active', 'priority']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['pattern'] ?? '',
                $r['target'] ?? '',
                $r['match_type'] ?? 'literal',
                $r['status_code'] ?? 301,
                $r['store_id'] ?? 0,
                $r['is_active'] ?? 1,
                $r['priority'] ?? 10,
            ]);
        }
        rewind($out);
        $content = stream_get_contents($out) ?: '';
        fclose($out);

        return $this->fileFactory->create(
            'panth_seo_redirects_' . date('Ymd_His') . '.csv',
            $content,
            \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}
