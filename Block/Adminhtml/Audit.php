<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\FormKey as FormKeyModel;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\CronActivity;

class Audit extends Template
{
    protected $_template = 'Panth_AdvancedSEO::audit.phtml';

    private ?int $selectedStoreId = null;

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly FormKeyModel $formKeyModel,
        private readonly \Panth\AdvancedSEO\Model\Score\GradeCalculator $gradeCalculator,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly CrawlState $crawlState,
        private readonly CronActivity $cronActivity,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormKey(): string
    {
        return $this->formKeyModel->getFormKey();
    }

    public function getStoreOptions(): array
    {
        $options = [];

        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $options[] = [
                'value' => (int) $store->getId(),
                'label' => sprintf('%s (%s)', (string) $store->getName(), (string) $store->getCode()),
            ];
        }

        return $options;
    }

    public function getSelectedStoreId(): int
    {
        if ($this->selectedStoreId !== null) {
            return $this->selectedStoreId;
        }

        $requested = (int) $this->getRequest()->getParam('store', 0);
        $options   = $this->getStoreOptions();

        foreach ($options as $option) {
            if ($option['value'] === $requested) {
                return $this->selectedStoreId = $requested;
            }
        }

        return $this->selectedStoreId = ($options === [] ? 0 : (int) $options[0]['value']);
    }

    public function getCrawlState(): array
    {
        return $this->crawlState->get($this->getSelectedStoreId());
    }

    public function isCronActive(): bool
    {
        return $this->cronActivity->isActive();
    }

    public function getSyncPageLimit(): int
    {
        return CrawlRunner::SYNC_PAGE_LIMIT;
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
                ->where('store_id = ?', $this->getSelectedStoreId())
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
                ->where('store_id = ?', $this->getSelectedStoreId())
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

        $storeId = $this->getSelectedStoreId();

        $counts = $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'total_pages' => new \Zend_Db_Expr('COUNT(*)'),
                    'status_200'  => new \Zend_Db_Expr('SUM(status_code >= 200 AND status_code < 300)'),
                    'status_301'  => new \Zend_Db_Expr('SUM(status_code >= 300 AND status_code < 400)'),
                    'status_404'  => new \Zend_Db_Expr('SUM(status_code >= 400 AND status_code < 500)'),
                    'status_5xx'  => new \Zend_Db_Expr('SUM(status_code >= 500)'),
                    'failed'      => new \Zend_Db_Expr('SUM(status_code = 0)'),
                    'pages_with_issues' => new \Zend_Db_Expr(
                        "SUM(issues_json IS NOT NULL AND issues_json != '[]' AND issues_json != 'null')"
                    ),
                    'last_crawled_at' => new \Zend_Db_Expr('MAX(crawled_at)'),
                ])
                ->where('store_id = ?', $storeId)
        );

        if (!is_array($counts) || (int) ($counts['total_pages'] ?? 0) === 0) {
            return null;
        }

        return [
            'total_pages'       => (int) $counts['total_pages'],
            'status_200'        => (int) $counts['status_200'],
            'status_301'        => (int) $counts['status_301'],
            'status_404'        => (int) $counts['status_404'],
            'status_5xx'        => (int) $counts['status_5xx'],
            'failed'            => (int) $counts['failed'],
            'pages_with_issues' => (int) $counts['pages_with_issues'],
            'last_crawled_at'   => $counts['last_crawled_at'] ?: null,
        ];
    }
}
