<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuditCommand extends Command
{
    private const OPT_STORE             = 'store';
    private const OPT_FAIL_ON_MISSING   = 'fail-on-missing';
    private const OPT_FAIL_ON_DUPLICATE = 'fail-on-duplicate';
    private const OPT_FAIL_ON_SCORE     = 'fail-on-score';
    private const OPT_MAX_PAGES         = 'max-pages';
    private const OPT_FORMAT            = 'format';
    private const OPT_SEVERITY          = 'severity';
    private const OPT_NO_SAVE           = 'no-save';

    public const FORMATS = ['table', 'json', 'csv'];

    public const EXIT_ERRORS_FOUND = 1;
    public const EXIT_NOT_RUN      = 2;

    private float $lastAverageScore = 0.0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AppState $appState,
        private readonly ?CrawlRunner $crawlRunner = null,
        private readonly ?CrawlState $crawlState = null,
        private readonly ?StoreManagerInterface $storeManager = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:audit')
            ->setDescription(
                'Emit a CI-friendly SEO audit. Without --format/--max-pages/--severity it prints the resolved-meta '
                . 'summary (JSON); with them it crawls the store and runs the Site Audit checks.'
            )
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_REQUIRED, 'Store id (0 = all; the crawl uses the default store view)', '0')
            ->addOption(self::OPT_FAIL_ON_MISSING, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if missing >= N', '0')
            ->addOption(self::OPT_FAIL_ON_DUPLICATE, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if duplicates >= N', '0')
            ->addOption(self::OPT_FAIL_ON_SCORE, null, InputOption::VALUE_REQUIRED, 'Exit non-zero if avg score < N', '0')
            ->addOption(self::OPT_MAX_PAGES, null, InputOption::VALUE_REQUIRED, 'Crawl at most N pages (default: configured Crawl Depth)')
            ->addOption(self::OPT_FORMAT, null, InputOption::VALUE_REQUIRED, 'Site Audit output: table, json or csv')
            ->addOption(self::OPT_SEVERITY, null, InputOption::VALUE_REQUIRED, 'Only list issues of this severity or worse: error, warning or notice')
            ->addOption(self::OPT_NO_SAVE, null, InputOption::VALUE_NONE, 'Do not save the crawl results and issues');
    }

    public static function exitCodeFor(array $issues): int
    {
        foreach ($issues as $issue) {
            $severity = $issue instanceof Issue ? $issue->severity : (string) ($issue['severity'] ?? '');
            if ($severity === Issue::SEVERITY_ERROR) {
                return self::EXIT_ERRORS_FOUND;
            }
        }

        return Command::SUCCESS;
    }

    public static function filterBySeverity(array $issues, ?string $minimum): array
    {
        if ($minimum === null || $minimum === '') {
            return $issues;
        }
        $rank = Issue::severityRank($minimum);

        return array_values(array_filter(
            $issues,
            static fn (Issue $issue): bool => Issue::severityRank($issue->severity) >= $rank
        ));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Throwable) {
        }

        $format   = $input->getOption(self::OPT_FORMAT);
        $maxPages = $input->getOption(self::OPT_MAX_PAGES);
        $severity = $input->getOption(self::OPT_SEVERITY);

        if ($format !== null || $maxPages !== null || $severity !== null) {
            return $this->runSiteAudit($input, $output, (string) ($format ?? 'table'), $maxPages, $severity);
        }

        $report = $this->legacyReport((int) $input->getOption(self::OPT_STORE));
        $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->legacyExitCode($input, $report);
    }

    private function runSiteAudit(
        InputInterface $input,
        OutputInterface $output,
        string $format,
        mixed $maxPages,
        mixed $severity
    ): int {
        $format = strtolower($format);
        if (!in_array($format, self::FORMATS, true)) {
            $output->writeln('<error>--format must be one of: ' . implode(', ', self::FORMATS) . '</error>');
            return self::EXIT_NOT_RUN;
        }
        $severity = $severity !== null ? strtolower((string) $severity) : null;
        if ($severity !== null && Issue::severityRank($severity) === 0) {
            $output->writeln('<error>--severity must be one of: error, warning, notice</error>');
            return self::EXIT_NOT_RUN;
        }
        if ($this->crawlRunner === null || $this->crawlState === null || $this->storeManager === null) {
            $output->writeln('<error>The Site Audit services are not available.</error>');
            return self::EXIT_NOT_RUN;
        }

        $storeId = (int) $input->getOption(self::OPT_STORE);
        if ($storeId <= 0) {
            $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId();
        }
        if ($storeId <= 0) {
            $output->writeln('<error>No store view to audit.</error>');
            return self::EXIT_NOT_RUN;
        }
        if ($this->crawlState->isActive($storeId)) {
            $output->writeln(sprintf('<error>A crawl of store %d is already queued or running.</error>', $storeId));
            return self::EXIT_NOT_RUN;
        }

        $outcome = $this->crawlRunner->run(
            $storeId,
            $maxPages !== null && $maxPages !== '' ? max(1, (int) $maxPages) : null,
            !$input->getOption(self::OPT_NO_SAVE)
        );
        if ((int) $outcome['pages'] === 0) {
            $output->writeln(sprintf(
                '<error>Store %d could not be crawled. Check that the store URL is reachable from this server '
                . '(inside a container set PANTH_SEO_CRAWL_INTERNAL_HOST).</error>',
                $storeId
            ));
            return self::EXIT_NOT_RUN;
        }

        $all    = $outcome['audit_issues'] ?? [];
        $listed = self::filterBySeverity($all, $severity);
        $counts = $outcome['severity_counts'] ?? CrawlRunner::countBySeverity($all);

        if ($format === 'json') {
            $report = array_merge($this->legacyReport($storeId), [
                'pages'           => (int) $outcome['pages'],
                'max_pages'       => (int) $outcome['max_pages'],
                'run_id'          => (int) ($outcome['run_id'] ?? 0),
                'summary'         => $outcome['summary'],
                'severity_counts' => $counts,
                'stats'           => $outcome['audit_stats'] ?? [],
                'severity_filter' => $severity,
                'issues'          => array_map(static fn (Issue $issue): array => $issue->toArray(), $listed),
            ]);
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($format === 'csv') {
            $output->write($this->toCsv($listed), false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->renderTable($output, $listed);
            $output->writeln(sprintf(
                'Store %d: %d page(s) crawled. Errors: %d, warnings: %d, notices: %d.%s',
                $storeId,
                (int) $outcome['pages'],
                (int) ($counts[Issue::SEVERITY_ERROR] ?? 0),
                (int) ($counts[Issue::SEVERITY_WARNING] ?? 0),
                (int) ($counts[Issue::SEVERITY_NOTICE] ?? 0),
                $severity !== null ? sprintf(' Listing %s and worse (%d).', $severity, count($listed)) : ''
            ));
        }

        return self::exitCodeFor($all);
    }

    private function renderTable(OutputInterface $output, array $issues): void
    {
        $table = new Table($output);
        $table->setHeaders(['Severity', 'Category', 'Code', 'URL', 'Element', 'Detail']);
        foreach ($issues as $issue) {
            $table->addRow([
                $issue->severity,
                $issue->category,
                $issue->code,
                $this->shorten($issue->url, 70),
                $this->shorten($issue->element, 50),
                $this->shorten($issue->detail, 90),
            ]);
        }
        $table->render();
    }

    private function shorten(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 3) . '...' : $value;
    }

    public function toCsv(array $issues): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, ['severity', 'category', 'code', 'url', 'element', 'detail', 'fix_hint'], ',', '"', '\\');
        foreach ($issues as $issue) {
            fputcsv($handle, [
                $issue->severity,
                $issue->category,
                $issue->code,
                $issue->url,
                $issue->element,
                $issue->detail,
                $issue->fixHint,
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function legacyReport(int $storeId): array
    {
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

        $this->lastAverageScore = $avgScore;

        $total = (int) $connection->fetchOne(
            $connection->select()->from($resolvedTable, ['c' => 'COUNT(*)'])
                ->where($storeId > 0 ? 'store_id = ?' : '1=1', $storeId > 0 ? $storeId : null)
        );

        return [
            'store_id'          => $storeId,
            'total_resolved'    => $total,
            'missing_title'     => $missingTitle,
            'missing_description' => $missingDesc,
            'duplicate_groups'  => $duplicateCount,
            'average_score'     => round($avgScore, 2),
        ];
    }

    private function legacyExitCode(InputInterface $input, array $report): int
    {
        $failMissing   = (int) $input->getOption(self::OPT_FAIL_ON_MISSING);
        $failDuplicate = (int) $input->getOption(self::OPT_FAIL_ON_DUPLICATE);
        $failScore     = (float) $input->getOption(self::OPT_FAIL_ON_SCORE);

        if ($failMissing > 0 && ($report['missing_title'] + $report['missing_description']) >= $failMissing) {
            return Command::FAILURE;
        }
        if ($failDuplicate > 0 && $report['duplicate_groups'] >= $failDuplicate) {
            return Command::FAILURE;
        }
        if ($failScore > 0 && $this->lastAverageScore < $failScore) {
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    private function count(\Magento\Framework\DB\Adapter\AdapterInterface $connection, string $table, array $where): int
    {
        $select = $connection->select()->from($table, ['c' => 'COUNT(*)']);
        foreach ($where as $cond) {
            $select->where($cond['column'], $cond['value']);
        }
        return (int) $connection->fetchOne($select);
    }
}
