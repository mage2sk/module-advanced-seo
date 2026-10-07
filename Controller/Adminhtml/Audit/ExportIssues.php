<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\IssuePersister;

class ExportIssues extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::crawl_audit';

    public function __construct(
        Context $context,
        private readonly IssuePersister $issuePersister,
        private readonly IssueCatalog $issueCatalog,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $storeId  = (int) $this->getRequest()->getParam('store', 0);
        $runId    = (int) $this->getRequest()->getParam('run', 0);
        $severity = (string) $this->getRequest()->getParam('severity', '');
        $category = (string) $this->getRequest()->getParam('category', '');
        if ($runId <= 0) {
            $runId = (int) ($this->issuePersister->getRunIds($storeId, 1)[0] ?? 0);
        }
        $previous = $runId > 0 ? $this->issuePersister->getPreviousRunId($storeId, $runId) : 0;
        $rows     = $this->issuePersister->getRunIssues(
            $runId,
            isset(Issue::SEVERITIES[$severity]) ? $severity : null,
            in_array($category, Issue::CATEGORIES, true) ? $category : null
        );
        $prevKeys = [];
        foreach ($previous > 0 ? $this->issuePersister->getRunIssues($previous) : [] as $row) {
            $prevKeys[$row['code'] . '|' . $row['url'] . '|' . $row['element']] = true;
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv(
            $handle,
            ['run_id', 'severity', 'category', 'code', 'issue', 'url', 'element', 'detail', 'compare', 'fix_hint'],
            ',',
            '"',
            '\\'
        );
        foreach ($rows as $row) {
            $compare = $previous === 0 || !isset($prevKeys[$row['code'] . '|' . $row['url'] . '|' . $row['element']])
                ? 'new'
                : 'unchanged';
            fputcsv($handle, [
                $row['run_id'],
                $row['severity'],
                $row['category'],
                $row['code'],
                $this->issueCatalog->title((string) $row['code']),
                $row['url'],
                $row['element'],
                $row['detail'],
                $compare,
                $this->issueCatalog->fixHint((string) $row['code']),
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $result->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="panth-seo-audit-issues-store%d-run%d.csv"', $storeId, $runId),
            true
        );
        $result->setContents($csv);

        return $result;
    }
}
