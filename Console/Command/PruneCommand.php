<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PruneCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_AUTHORED = 'include-authored';

    public function __construct(
        private readonly OrphanCleaner $orphanCleaner,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:prune')
            ->setDescription('Delete SEO scores, embeddings and resolved meta left behind by deleted products, categories and CMS pages.')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it')
            ->addOption(
                self::OPT_AUTHORED,
                null,
                InputOption::VALUE_NONE,
                'Also delete hand-entered rows (per-entity SEO overrides and custom canonicals) for deleted entities'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
        }

        $dryRun   = (bool) $input->getOption(self::OPT_DRY_RUN);
        $authored = (bool) $input->getOption(self::OPT_AUTHORED);

        if (!$authored) {
            $output->writeln('<comment>Hand-entered overrides and custom canonicals are kept. Pass --include-authored to remove those too.</comment>');
        }

        try {
            $counts = $dryRun
                ? $this->orphanCleaner->count($authored)
                : $this->orphanCleaner->sweep($authored);
        } catch (\Throwable $e) {
            $output->writeln('<error>Prune failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($counts === []) {
            $output->writeln('<info>No SEO rows for deleted entities were found.</info>');
            return Command::SUCCESS;
        }

        $total = 0;
        foreach ($counts as $table => $count) {
            $total += $count;
            $output->writeln(sprintf('  %-28s %d row(s)', $table, $count));
        }

        $output->writeln($dryRun
            ? sprintf('<comment>Dry run: %d row(s) would be deleted.</comment>', $total)
            : sprintf('<info>Deleted %d row(s) for entities that no longer exist.</info>', $total));

        return Command::SUCCESS;
    }
}
