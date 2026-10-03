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
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\IssuePersister;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;

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
        private readonly EntityTableMap $entityTableMap,
        array $data = [],
        private readonly ?IssuePersister $issuePersister = null,
        private readonly ?IssueCatalog $issueCatalog = null
    ) {
        parent::__construct($context, $data);
    }

    public function getIssueSeverityFilter(): string
    {
        $severity = (string) $this->getRequest()->getParam('issue_severity', Issue::SEVERITY_ERROR);

        return isset(Issue::SEVERITIES[$severity]) ? $severity : Issue::SEVERITY_ERROR;
    }

    public function getIssueCategoryFilter(): string
    {
        $category = (string) $this->getRequest()->getParam('issue_category', '');

        return in_array($category, Issue::CATEGORIES, true) ? $category : '';
    }

    public function getCategoryLabel(string $category): string
    {
        return $category === 'ai_search' ? 'AI Search' : ucwords(str_replace('_', ' ', $category));
    }

    public function getIssueFilterUrl(string $severity, string $category = ''): string
    {
        $params = ['store' => $this->getSelectedStoreId(), 'issue_severity' => $severity, '_fragment' => 'panth-seo-issues'];
        if ($category !== '') {
            $params['issue_category'] = $category;
        }

        return $this->getUrl('panth_seo/audit/index', $params);
    }

    public function getIssueExportUrl(): string
    {
        return $this->getUrl('panth_seo/audit/exportIssues', ['store' => $this->getSelectedStoreId()]);
    }

    public function getIssueReport(int $rowsPerGroup = 200): ?array
    {
        if ($this->issuePersister === null) {
            return null;
        }
        $storeId = $this->getSelectedStoreId();
        $runIds  = $this->issuePersister->getRunIds($storeId, 1);
        if ($runIds === []) {
            return null;
        }
        $runId    = $runIds[0];
        $previous = $this->issuePersister->getPreviousRunId($storeId, $runId);
        $rows     = $this->issuePersister->getRunIssues($runId);
        $prevRows = $previous > 0 ? $this->issuePersister->getRunIssues($previous) : [];
        $severity = $this->getIssueSeverityFilter();
        $category = $this->getIssueCategoryFilter();
        $catalog  = $this->issueCatalog ?? new IssueCatalog();

        $key       = static fn (array $row): string => $row['code'] . '|' . $row['url'] . '|' . $row['element'];
        $prevKeys  = [];
        foreach ($prevRows as $row) {
            $prevKeys[$key($row)] = true;
        }
        $currentKeys = [];
        $counts      = array_fill_keys(array_keys(Issue::SEVERITIES), 0);
        $categories  = [];
        $groups      = [];
        foreach ($rows as $row) {
            $currentKeys[$key($row)] = true;
            $counts[$row['severity']] = ($counts[$row['severity']] ?? 0) + 1;
            if ($row['severity'] !== $severity) {
                continue;
            }
            $categories[$row['category']] = ($categories[$row['category']] ?? 0) + 1;
            if ($category !== '' && $row['category'] !== $category) {
                continue;
            }
            $code = (string) $row['code'];
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code'      => $code,
                    'title'     => $catalog->title($code),
                    'hint'      => $catalog->fixHint($code),
                    'category'  => (string) $row['category'],
                    'urls'      => [],
                    'rows'      => [],
                    'total'     => 0,
                    'new'       => 0,
                    'unchanged' => 0,
                    'fixed'     => 0,
                    'fixed_rows' => [],
                ];
            }
            $status = $previous === 0 ? 'new' : (isset($prevKeys[$key($row)]) ? 'unchanged' : 'new');
            $groups[$code][$status]++;
            $groups[$code]['total']++;
            $groups[$code]['urls'][$row['url']] = true;
            if (count($groups[$code]['rows']) < $rowsPerGroup) {
                $row['compare'] = $status;
                $groups[$code]['rows'][] = $row;
            }
        }
        foreach ($prevRows as $row) {
            if ($row['severity'] !== $severity || ($category !== '' && $row['category'] !== $category)
                || isset($currentKeys[$key($row)])
            ) {
                continue;
            }
            $code = (string) $row['code'];
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $code, 'title' => $catalog->title($code), 'hint' => $catalog->fixHint($code),
                    'category' => (string) $row['category'], 'urls' => [], 'rows' => [], 'total' => 0,
                    'new' => 0, 'unchanged' => 0, 'fixed' => 0, 'fixed_rows' => [],
                ];
            }
            $groups[$code]['fixed']++;
            if (count($groups[$code]['fixed_rows']) < $rowsPerGroup) {
                $row['compare'] = 'fixed';
                $groups[$code]['fixed_rows'][] = $row;
            }
        }
        foreach ($groups as &$group) {
            $group['pages'] = count($group['urls']);
        }
        unset($group);
        uasort($groups, static fn (array $a, array $b): int => [$b['total'], $a['code']] <=> [$a['total'], $b['code']]);
        ksort($categories);

        return [
            'run_id'          => $runId,
            'previous_run_id' => $previous,
            'created_at'      => $this->issuePersister->getRunCreatedAt($runId),
            'counts'          => $counts,
            'categories'      => $categories,
            'groups'          => array_values($groups),
            'severity'        => $severity,
            'category'        => $category,
        ];
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
                ->from(['entity_score' => $table])
                ->where('entity_score.store_id = ?', $this->getSelectedStoreId())
                ->where('entity_score.score < ?', 60)
                ->where($this->entityTableMap->existsCondition('entity_score', 'entity_type', 'entity_id'))
                ->order('entity_score.score ASC')
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
