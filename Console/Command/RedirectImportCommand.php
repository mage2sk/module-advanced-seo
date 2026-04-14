<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Panth\AdvancedSEO\Model\Redirect\ImportExport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RedirectImportCommand extends Command
{
    public function __construct(
        private readonly ImportExport $importExport,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:redirect:import')
            ->setDescription('Import redirects from a CSV file.')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to CSV file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $file   = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $result = $this->importExport->import($file, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s complete — imported=%d skipped=%d errors=%d</info>',
            $dryRun ? 'Dry-run' : 'Import',
            $result['imported'],
            $result['skipped'],
            count($result['errors'])
        ));
        foreach ($result['errors'] as $err) {
            $output->writeln('  <comment>' . $err . '</comment>');
        }
        return empty($result['errors']) ? Command::SUCCESS : Command::SUCCESS;
    }
}
