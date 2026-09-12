<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Audit\Crawler;
use Panth\AdvancedSEO\Model\Audit\CrawlResult;
use Panth\AdvancedSEO\Model\Audit\IssueDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CrawlCommand extends Command
{
    private const OPT_STORE = 'store';
    private const OPT_LIMIT = 'limit';
    private const OPT_DRY_RUN = 'dry-run';

    private const BATCH_INSERT_SIZE = 100;

    public function __construct(
        private readonly Crawler $crawler,
        private readonly IssueDetector $issueDetector,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly AppState $appState,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:crawl')
            ->setDescription('Run an internal SEO crawl audit and output results.')
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_REQUIRED, 'Store code or ID (omit to crawl all active stores)')
            ->addOption(self::OPT_LIMIT, 'l', InputOption::VALUE_REQUIRED, 'Maximum pages to crawl per store', '100')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Print the results without writing them to the Crawl Results grid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
        }

        $storeArg = $input->getOption(self::OPT_STORE);
        $limit    = max(1, (int) $input->getOption(self::OPT_LIMIT));
        $dryRun   = (bool) $input->getOption(self::OPT_DRY_RUN);

        $stores = $this->resolveStores($storeArg, $output);
        if ($stores === null) {
            return Command::FAILURE;
        }

        $exitCode = Command::SUCCESS;

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $output->writeln(sprintf('<info>Crawling store "%s" (ID %d), limit %d pages...</info>', $store->getCode(), $storeId, $limit));

            try {
                $rawResults = $this->crawler->crawl($storeId, $limit);
                $analysis   = $this->issueDetector->analyse($rawResults, $this->crawler->getRedirectMap());

                $results = $analysis['results'];
                $summary = $analysis['summary'];

                $this->outputResults($output, $results);
                $this->outputSummary($output, $summary, count($results));

                if ($dryRun) {
                    $output->writeln('  <comment>Dry run: results were not saved.</comment>');
                } else {
                    $saved = $this->persistResults($storeId, $results);
                    $output->writeln(sprintf('  <info>Saved %d row(s) to the Crawl Results grid.</info>', $saved));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Failed for store %s: %s</error>', $store->getCode(), $e->getMessage()));
                $exitCode = Command::FAILURE;
            }

            $output->writeln('');
        }

        return $exitCode;
    }

    private function persistResults(int $storeId, array $results): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_crawl_result');

        if (!$connection->isTableExists($table)) {
            return 0;
        }

        $connection->delete($table, ['store_id = ?' => $storeId]);

        $now    = $this->dateTime->gmtDate();
        $buffer = [];
        $saved  = 0;

        foreach ($results as $result) {
            $row = $result->toArray();
            $row['store_id']   = $storeId;
            $row['crawled_at'] = $now;
            $buffer[] = $row;

            if (count($buffer) >= self::BATCH_INSERT_SIZE) {
                $connection->insertMultiple($table, $buffer);
                $saved += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $connection->insertMultiple($table, $buffer);
            $saved += count($buffer);
        }

        return $saved;
    }

    private function resolveStores(mixed $storeArg, OutputInterface $output): ?array
    {
        if ($storeArg !== null && $storeArg !== '') {
            try {
                $store = is_numeric($storeArg)
                    ? $this->storeRepository->getById((int) $storeArg)
                    : $this->storeRepository->get((string) $storeArg);
                return [$store];
            } catch (\Throwable) {
                $output->writeln(sprintf('<error>Store not found: %s</error>', (string) $storeArg));
                return null;
            }
        }

        $stores = [];
        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $stores[] = $store;
        }

        if ($stores === []) {
            $output->writeln('<error>No active stores found.</error>');
            return null;
        }

        return $stores;
    }

    private function outputResults(OutputInterface $output, array $results): void
    {
        foreach ($results as $result) {
            $statusTag = $result->statusCode === 200 ? 'info' : 'error';
            $output->writeln(sprintf(
                '  <%s>[%d]</%s> %s',
                $statusTag,
                $result->statusCode,
                $statusTag,
                $result->url
            ));

            if ($result->title !== '' && $output->isVerbose()) {
                $output->writeln('    Title: ' . $result->title);
            }

            if ($result->issues !== []) {
                foreach ($result->issues as $issue) {
                    $output->writeln('    <comment>! ' . $issue . '</comment>');
                }
            }
        }
    }

    private function outputSummary(OutputInterface $output, array $summary, int $totalPages): void
    {
        $totalIssues = (int) array_sum($summary);
        $output->writeln(sprintf(
            '<info>  Summary: %d pages crawled, %d total issues</info>',
            $totalPages,
            $totalIssues
        ));

        foreach ($summary as $category => $count) {
            if ($count > 0) {
                $label = str_replace('_', ' ', $category);
                $output->writeln(sprintf('    %s: %d', ucfirst($label), $count));
            }
        }
    }
}
