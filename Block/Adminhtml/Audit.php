<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\FormKey as FormKeyModel;

class Audit extends Template
{
    protected $_template = 'Panth_AdvancedSEO::audit.phtml';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly FormKeyModel $formKeyModel,
        private readonly \Panth\AdvancedSEO\Model\Score\GradeCalculator $gradeCalculator,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        return $this->formKeyModel->getFormKey();
    }

    public function getLowScoringEntities(int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_score');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('score < ?', 60)
                ->order('score ASC')
                ->limit($limit)
        );

        foreach ($rows as &$row) {
            $row['grade'] = $this->gradeCalculator->forScore((int) ($row['score'] ?? 0));
        }

        return $rows;
    }

    public function getDuplicates(int $limit = 50): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_duplicate');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        return $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->order('count DESC')
                ->limit($limit)
        );
    }

    public function getLastCrawlSummary(): ?array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_crawl_result');

        if (!$connection->isTableExists($table)) {
            return null;
        }

        $totalPages = (int) $connection->fetchOne(
            $connection->select()->from($table, [new \Zend_Db_Expr('COUNT(*)')])
        );

        if ($totalPages === 0) {
            return null;
        }

        $status200 = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('COUNT(*)')])
                ->where('status_code >= 200 AND status_code < 300')
        );

        $status301 = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('COUNT(*)')])
                ->where('status_code >= 300 AND status_code < 400')
        );

        $status404 = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('COUNT(*)')])
                ->where('status_code >= 400 AND status_code < 500')
        );

        $status5xx = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('COUNT(*)')])
                ->where('status_code >= 500')
        );

        $pagesWithIssues = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('COUNT(*)')])
                ->where('issues_json IS NOT NULL')
                ->where('issues_json != ?', '[]')
                ->where('issues_json != ?', 'null')
        );

        $lastCrawledAt = $connection->fetchOne(
            $connection->select()
                ->from($table, ['crawled_at'])
                ->order('crawled_at DESC')
                ->limit(1)
        );

        return [
            'total_pages'       => $totalPages,
            'status_200'        => $status200,
            'status_301'        => $status301,
            'status_404'        => $status404,
            'status_5xx'        => $status5xx,
            'pages_with_issues' => $pagesWithIssues,
            'last_crawled_at'   => $lastCrawledAt ?: null,
        ];
    }
}
