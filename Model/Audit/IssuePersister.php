<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class IssuePersister
{
    public const TABLE = 'panth_seo_audit_issue';

    public const URL_LENGTH     = 512;
    public const ELEMENT_LENGTH = 1024;

    private const BATCH_INSERT_SIZE = 200;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->resource->getConnection()->isTableExists($this->resource->getTableName(self::TABLE));
    }

    public function nextRunId(): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['max_run' => 'MAX(run_id)'])
        ) + 1;
    }

    public function persist(int $storeId, array $issues, int $keepRuns = 5): int
    {
        if (!$this->isAvailable()) {
            $this->logger->warning('Panth SEO audit: table ' . self::TABLE . ' does not exist, issues not saved.');
            return 0;
        }
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);
        $now        = $this->dateTime->gmtDate();

        $connection->beginTransaction();
        try {
            $runId  = $this->nextRunId();
            $buffer = [];
            foreach ($issues as $issue) {
                if (!$issue instanceof Issue) {
                    continue;
                }
                $buffer[] = [
                    'run_id'     => $runId,
                    'store_id'   => $storeId,
                    'code'       => substr($issue->code, 0, 64),
                    'severity'   => $issue->severity,
                    'category'   => $issue->category,
                    'url'        => mb_substr($issue->url, 0, self::URL_LENGTH),
                    'element'    => mb_substr($issue->element, 0, self::ELEMENT_LENGTH),
                    'detail'     => $issue->detail,
                    'created_at' => $now,
                ];
                if (count($buffer) >= self::BATCH_INSERT_SIZE) {
                    $connection->insertMultiple($table, $buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $connection->insertMultiple($table, $buffer);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('Panth SEO audit: saving issues for store ' . $storeId . ' failed: ' . $e->getMessage());
            throw $e;
        }

        $this->prune($storeId, max(2, $keepRuns));

        return $runId;
    }

    public function getRunIds(int $storeId, int $limit = 2): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $connection = $this->resource->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['run_id'])
                ->where('store_id = ?', $storeId)
                ->group('run_id')
                ->order('run_id DESC')
                ->limit($limit)
        ));
    }

    public function getPreviousRunId(int $storeId, int $runId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['prev' => 'MAX(run_id)'])
                ->where('store_id = ?', $storeId)
                ->where('run_id < ?', $runId)
        );
    }

    public function getRunIssues(int $runId, ?string $severity = null, ?string $category = null): array
    {
        if ($runId <= 0 || !$this->isAvailable()) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('run_id = ?', $runId)
            ->order(['code ASC', 'url ASC', 'id ASC']);
        if ($severity !== null && $severity !== '') {
            $select->where('severity = ?', $severity);
        }
        if ($category !== null && $category !== '') {
            $select->where('category = ?', $category);
        }

        return $connection->fetchAll($select);
    }

    public function getRunCreatedAt(int $runId): ?string
    {
        if ($runId <= 0 || !$this->isAvailable()) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $value      = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['created' => 'MIN(created_at)'])
                ->where('run_id = ?', $runId)
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function prune(int $storeId, int $keepRuns): void
    {
        $runIds = $this->getRunIds($storeId, $keepRuns + 1);
        if (count($runIds) <= $keepRuns) {
            return;
        }
        $oldestKept = $runIds[$keepRuns - 1];
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['store_id = ?' => $storeId, 'run_id < ?' => $oldestKept]
        );
    }
}
