<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: panth:seo:feed [--store=<code|id>] [--feed=<profile_id>]
 *
 * Generates product feeds:
 *   --feed=<id>    Generates a specific feed profile
 *   --store=<id>   Generates all active feed profiles for that store
 *   No options:    Generates all active feed profiles; falls back to legacy Google feed if none exist
 */
class GenerateFeedCommand extends Command
{
    public function __construct(
        private readonly GoogleMerchantFeedBuilder $feedBuilder,
        private readonly ProfileBasedFeedBuilder $profileFeedBuilder,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly AppState $appState,
        private readonly DirectoryList $directoryList,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:feed')
            ->setDescription('Generate product feeds from feed profiles or legacy Google Merchant feed.')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store code or ID (generates all active profiles for the store)')
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Feed profile ID (generates a specific feed profile)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable) {
            // area already set
        }

        $feedId = $input->getOption('feed');
        $storeArg = $input->getOption('store');

        // Mode 1: Generate a specific feed profile
        if ($feedId !== null && $feedId !== '') {
            return $this->generateSingleProfile((int) $feedId, $output);
        }

        // Mode 2: Generate all active profiles for a store
        if ($storeArg !== null && $storeArg !== '') {
            return $this->generateProfilesForStore($storeArg, $output);
        }

        // Mode 3: Generate all active profiles; fall back to legacy if none exist
        return $this->generateAllProfiles($output);
    }

    /**
     * Generate a single feed profile by ID.
     */
    private function generateSingleProfile(int $feedId, OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>Generating feed profile #%d...</info>', $feedId));

        try {
            $stats = $this->profileFeedBuilder->generateById($feedId);
            $this->printStats($output, $stats);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Generate all active profiles for a specific store.
     */
    private function generateProfilesForStore(string $storeArg, OutputInterface $output): int
    {
        try {
            $store = is_numeric($storeArg)
                ? $this->storeRepository->getById((int) $storeArg)
                : $this->storeRepository->get($storeArg);
        } catch (\Throwable) {
            $output->writeln(sprintf('<error>Store not found: %s</error>', $storeArg));
            return Command::FAILURE;
        }

        $storeId = (int) $store->getId();
        $profiles = $this->profileFeedBuilder->loadActiveProfiles($storeId);

        if (!empty($profiles)) {
            return $this->runProfileGeneration($profiles, $output);
        }

        // Fall back to legacy feed
        return $this->generateLegacyFeed([$store], $output);
    }

    /**
     * Generate all active profiles across all stores.
     */
    private function generateAllProfiles(OutputInterface $output): int
    {
        $profiles = $this->profileFeedBuilder->loadActiveProfiles();

        if (!empty($profiles)) {
            return $this->runProfileGeneration($profiles, $output);
        }

        // Fall back to legacy feed for all stores
        $output->writeln('<comment>No feed profiles found. Falling back to legacy Google Merchant feed.</comment>');
        $stores = [];
        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $stores[] = $store;
        }

        return $this->generateLegacyFeed($stores, $output);
    }

    /**
     * Run profile-based feed generation for a list of profiles.
     */
    private function runProfileGeneration(array $profiles, OutputInterface $output): int
    {
        $exitCode = Command::SUCCESS;

        foreach ($profiles as $profile) {
            $feedId = (int) $profile['feed_id'];
            $name = $profile['name'] ?? 'Unnamed';

            $output->writeln(sprintf(
                '<info>Generating feed #%d "%s" (store %d, %s)...</info>',
                $feedId,
                $name,
                $profile['store_id'] ?? 0,
                $profile['output_format'] ?? 'xml'
            ));

            try {
                $stats = $this->profileFeedBuilder->generate($profile);
                $this->printStats($output, $stats);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>  Failed: %s</error>', $e->getMessage()));
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * Generate legacy (non-profile) Google Merchant feed for given stores.
     */
    private function generateLegacyFeed(array $stores, OutputInterface $output): int
    {
        $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
        $feedDir = $mediaDir . '/panth_seo';
        $exitCode = Command::SUCCESS;

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $storeCode = $store->getCode();

            if (!$this->config->isEnabled($storeId) || !$this->config->isMerchantFeedEnabled($storeId)) {
                $output->writeln(sprintf(
                    '<comment>Skipping store "%s" (id %d): merchant feed disabled.</comment>',
                    $storeCode,
                    $storeId
                ));
                continue;
            }

            $output->writeln(sprintf(
                '<info>Generating legacy Google feed for store "%s" (id %d)...</info>',
                $storeCode,
                $storeId
            ));

            $filePath = $feedDir . '/google_feed_' . $storeCode . '.xml';

            try {
                $written = $this->feedBuilder->buildToFile($storeId, $filePath);
                $output->writeln('  -> ' . $written);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>  Failed: %s</error>', $e->getMessage()));
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * Print generation statistics.
     */
    private function printStats(OutputInterface $output, array $stats): void
    {
        if (isset($stats['error'])) {
            $output->writeln(sprintf('<error>  Error: %s</error>', $stats['error']));
            return;
        }

        $output->writeln(sprintf(
            '  -> %s (%d products, %s, %.2fs)',
            $stats['file_path'] ?? 'unknown',
            $stats['product_count'] ?? 0,
            $this->formatFileSize($stats['file_size'] ?? 0),
            $stats['generation_time'] ?? 0
        ));
    }

    /**
     * Format file size for human-readable output.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
