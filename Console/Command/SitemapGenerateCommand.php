<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\SitemapBuilderInterface;
use Panth\AdvancedSEO\Model\Sitemap\Builder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SitemapGenerateCommand extends Command
{
    public function __construct(
        private readonly SitemapBuilderInterface $builder,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:sitemap')
            ->setDescription('Generate sharded XML sitemap(s) for one or all stores, optionally from a profile.')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store code or id (default: all stores)')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Sitemap profile ID to generate for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable) {
            // area already set
        }

        $profileArg = $input->getOption('profile');
        $storeArg   = $input->getOption('store');

        // Profile mode: generate sitemap for a specific profile
        if ($profileArg !== null && $profileArg !== '') {
            return $this->executeProfile((int) $profileArg, $output);
        }

        // Store mode: generate for all active profiles in that store,
        // or fall back to legacy build if no profiles exist
        if ($storeArg !== null && $storeArg !== '') {
            return $this->executeStore($storeArg, $output);
        }

        // No args: generate for all active profiles across all stores,
        // or fall back to legacy build per store
        return $this->executeAll($output);
    }

    /**
     * Generate sitemap for a specific profile ID.
     */
    private function executeProfile(int $profileId, OutputInterface $output): int
    {
        if (!$this->builder instanceof Builder) {
            $output->writeln('<error>Profile-based generation requires the Panth Builder implementation.</error>');
            return Command::FAILURE;
        }

        $profile = $this->builder->loadProfile($profileId);
        if ($profile === null) {
            $output->writeln('<error>Profile not found: ' . $profileId . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Building sitemap for profile "%s" (id %d, store %d)...</info>',
            $profile['name'] ?? 'unnamed',
            $profileId,
            $profile['store_id'] ?? 0
        ));

        try {
            $stats = $this->builder->buildFromProfile($profile);
            $this->builder->updateProfileStats($profileId, $stats);

            $output->writeln(sprintf(
                '<info>  Done: %d URLs in %d files (%.2fs)</info>',
                $stats['url_count'],
                $stats['file_count'],
                $stats['generation_time']
            ));
            foreach ($stats['files'] as $file) {
                $output->writeln('  - ' . $file);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>  Failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Generate sitemaps for a specific store (all active profiles or legacy build).
     */
    private function executeStore(string $storeArg, OutputInterface $output): int
    {
        try {
            $store = is_numeric($storeArg)
                ? $this->storeRepository->getById((int) $storeArg)
                : $this->storeRepository->get((string) $storeArg);
        } catch (\Throwable $e) {
            $output->writeln('<error>Store not found: ' . $storeArg . '</error>');
            return Command::FAILURE;
        }

        $storeId = (int) $store->getId();

        // Try profile-based generation first
        if ($this->builder instanceof Builder) {
            $profiles = $this->builder->loadActiveProfiles($storeId);
            if (!empty($profiles)) {
                $output->writeln(sprintf(
                    '<info>Found %d active profile(s) for store "%s" (id %d)</info>',
                    count($profiles),
                    $store->getCode(),
                    $storeId
                ));

                $exitCode = Command::SUCCESS;
                foreach ($profiles as $profile) {
                    $result = $this->executeProfile((int) $profile['profile_id'], $output);
                    if ($result !== Command::SUCCESS) {
                        $exitCode = Command::FAILURE;
                    }
                }
                return $exitCode;
            }
        }

        // Legacy build (no profiles)
        $output->writeln(sprintf(
            '<info>Building sitemap for store "%s" (id %d)...</info>',
            $store->getCode(),
            $storeId
        ));

        try {
            $files = $this->builder->build($storeId);
            foreach ($files as $file) {
                $output->writeln('  - ' . $file);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>  Failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Generate sitemaps for all stores / all active profiles.
     */
    private function executeAll(OutputInterface $output): int
    {
        // Try profile-based generation first
        if ($this->builder instanceof Builder) {
            $profiles = $this->builder->loadActiveProfiles();
            if (!empty($profiles)) {
                $output->writeln(sprintf(
                    '<info>Found %d active profile(s) across all stores</info>',
                    count($profiles)
                ));

                $exitCode = Command::SUCCESS;
                foreach ($profiles as $profile) {
                    $result = $this->executeProfile((int) $profile['profile_id'], $output);
                    if ($result !== Command::SUCCESS) {
                        $exitCode = Command::FAILURE;
                    }
                }
                return $exitCode;
            }
        }

        // Legacy: build for all stores
        $exitCode = Command::SUCCESS;
        foreach ($this->storeRepository->getList() as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $storeId = (int) $store->getId();
            $output->writeln(sprintf(
                '<info>Building sitemap for store "%s" (id %d)...</info>',
                $store->getCode(),
                $storeId
            ));
            try {
                $files = $this->builder->build($storeId);
                foreach ($files as $file) {
                    $output->writeln('  - ' . $file);
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>  Failed: ' . $e->getMessage() . '</error>');
                $exitCode = Command::FAILURE;
            }
        }
        return $exitCode;
    }
}
