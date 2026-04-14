<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento panth:seo:audit`
 *
 * CI-friendly JSON audit: counts missing titles, missing descriptions,
 * duplicate meta, low scores, failed jobs. Exits non-zero on any regression
 * against --threshold-* flags.
 */
class AuditCommand extends Command
{
    private const OPT_STORE             = 'store';
    private const OPT_FAIL_ON_MISSING   = 'fail-on-missing';
    private const OPT_FAIL_ON_DUPLICATE = 'fail-on-duplicate';
    private const OPT_FAIL_ON_SCORE     = 'fail-on-score';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:audit')
            ->setDescription('Emit a CI-friendly SEO audit summary (JSON).')
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_REQUIRED, 'Store id (0 = all)', '0')
            ->addOption(self::OPT_FAIL_ON_MISSING, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if missing >= N', '0')
            ->addOption(self::OPT_FAIL_ON_DUPLICATE, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if duplicates >= N', '0')
            ->addOption(self::OPT_FAIL_ON_SCORE, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if avg score < N', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // ignored
        }

        $storeId = (int) $input->getOption(self::OPT_STORE);
        $connection = $this->resource->getConnection();
        $resolvedTable  = $this->resource->getTableName('panth_seo_resolved');
        $scoreTable     = $this->resource->getTableName('panth_seo_score');
        $duplicateTable = $this->resource->getTableName('panth_seo_duplicate');

        $storeClause = $storeId > 0 ? [['column' => 'store_id = ?', 'value' => $storeId]] : [];

        $missingTitle = $this->count(
            $connection,
            $resolvedTable,
            array_merge([['column' => '(meta_title IS NULL OR meta_title = ?)', 'value' => '']], $storeClause)
        );
        $missingDesc = $this->count(
            $connection,
            $resolvedTable,
            array_merge([['column' => '(meta_description IS NULL OR meta_description = ?)', 'value' => '']], $storeClause)
        );
        $duplicateCount = $this->count(
            $connection,
            $duplicateTable,
            $storeClause
        );
        $avgScore = (float) $connection->fetchOne(
            $connection->select()
                ->from($scoreTable, ['avg' => 'AVG(score)'])
                ->where($storeId > 0 ? 'store_id = ?' : '1=1', $storeId > 0 ? $storeId : null)
        );

        $total = (int) $connection->fetchOne(
            $connection->select()->from($resolvedTable, ['c' => 'COUNT(*)'])
                ->where($storeId > 0 ? 'store_id = ?' : '1=1', $storeId > 0 ? $storeId : null)
        );

        $report = [
            'store_id'          => $storeId,
            'total_resolved'    => $total,
            'missing_title'     => $missingTitle,
            'missing_description' => $missingDesc,
            'duplicate_groups'  => $duplicateCount,
            'average_score'     => round($avgScore, 2),
        ];

        $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $failMissing   = (int) $input->getOption(self::OPT_FAIL_ON_MISSING);
        $failDuplicate = (int) $input->getOption(self::OPT_FAIL_ON_DUPLICATE);
        $failScore     = (float) $input->getOption(self::OPT_FAIL_ON_SCORE);

        if ($failMissing > 0 && ($missingTitle + $missingDesc) >= $failMissing) {
            return Command::FAILURE;
        }
        if ($failDuplicate > 0 && $duplicateCount >= $failDuplicate) {
            return Command::FAILURE;
        }
        if ($failScore > 0 && $avgScore < $failScore) {
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * @param array<int,array{column:string,value:mixed}> $where
     */
    private function count(\Magento\Framework\DB\Adapter\AdapterInterface $connection, string $table, array $where): int
    {
        $select = $connection->select()->from($table, ['c' => 'COUNT(*)']);
        foreach ($where as $cond) {
            $select->where($cond['column'], $cond['value']);
        }
        return (int) $connection->fetchOne($select);
    }
}
