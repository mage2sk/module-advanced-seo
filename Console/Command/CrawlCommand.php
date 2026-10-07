<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\CrawlRunner;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CrawlCommand extends Command
{
    private const OPT_STORE   = 'store';
    private const OPT_LIMIT   = 'limit';
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_FORCE   = 'force';

    public function __construct(
        private readonly CrawlRunner $crawlRunner,
        private readonly CrawlState $crawlState,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly Config $config,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:crawl')
            ->setDescription('Run an internal SEO crawl audit and output results.')
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_REQUIRED, 'Store code or ID (omit to crawl all active stores)')
            ->addOption(
                self::OPT_LIMIT,
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum pages to crawl per store (omit to use the configured Crawl Depth)'
            )
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Print the results without writing them to the Crawl Results grid')
            ->addOption(self::OPT_FORCE, 'f', InputOption::VALUE_NONE, 'Crawl even when a crawl is already queued or running for the store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
        }

        $storeArg = $input->getOption(self::OPT_STORE);
        $limitArg = $input->getOption(self::OPT_LIMIT);
        $dryRun   = (bool) $input->getOption(self::OPT_DRY_RUN);
        $force    = (bool) $input->getOption(self::OPT_FORCE);

        $stores = $this->resolveStores($storeArg, $output);
        if ($stores === null) {
            return Command::FAILURE;
        }

        $exitCode = Command::SUCCESS;

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $limit   = $limitArg !== null && $limitArg !== ''
                ? max(1, (int) $limitArg)
                : $this->config->getCrawlDepth($storeId);

            if (!$force && $this->crawlState->isActive($storeId)) {
                $output->writeln(sprintf(
                    '<comment>Skipping store "%s": a crawl is already queued or running. Use --force to override.</comment>',
                    $store->getCode()
                ));
                continue;
            }

            $output->writeln(sprintf(
                '<info>Crawling store "%s" (ID %d), limit %d pages...</info>',
                $store->getCode(),
                $storeId,
                $limit
            ));

            try {
                $outcome = $this->crawlRunner->run($storeId, $limit, !$dryRun);

                $this->outputResults($output, $outcome['results']);
                $this->outputSummary($output, $outcome['summary'], (int) $outcome['pages']);

                if ($outcome['cancelled']) {
                    $output->writeln('  <comment>Crawl stopped early: a cancel was requested from the admin.</comment>');
                }

                if ($dryRun) {
                    $output->writeln('  <comment>Dry run: results were not saved.</comment>');
                } else {
                    $output->writeln(sprintf('  <info>Saved %d row(s) to the Crawl Results grid.</info>', (int) $outcome['saved']));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Failed for store %s: %s</error>', $store->getCode(), $e->getMessage()));
                $exitCode = Command::FAILURE;
            }

            $output->writeln('');
        }

        return $exitCode;
    }

    private function resolveStores(mixed $storeArg, OutputInterface $output): ?array
    {
        if ($storeArg !== null && $storeArg !== '') {
            try {
                $store = is_numeric($storeArg)
                    ? $this->storeRepository->getById((int) $storeArg)
                    : $this->storeRepository->get((string) $storeArg);
            } catch (\Throwable) {
                $output->writeln(sprintf('<error>Store not found: %s</error>', (string) $storeArg));
                return null;
            }

            if ((int) $store->getId() === 0) {
                $output->writeln('<error>The admin store view cannot be crawled. Pass a storefront store view.</error>');
                return null;
            }

            return [$store];
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
