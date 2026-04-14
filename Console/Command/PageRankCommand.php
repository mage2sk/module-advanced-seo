<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\InternalLinking\Graph;
use Panth\AdvancedSEO\Model\InternalLinking\PageRank;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PageRankCommand extends Command
{
    public function __construct(
        private readonly PageRank $pageRank,
        private readonly Graph $graph,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly ResourceConnection $resource,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:pagerank')
            ->setDescription('Recompute and persist PageRank + related suggestions.')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store code or id')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Top N related per node', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $storeArg = $input->getOption('store');
        $limit    = max(1, (int) $input->getOption('limit'));
        $stores   = [];
        if ($storeArg !== null && $storeArg !== '') {
            try {
                $stores[] = is_numeric($storeArg)
                    ? $this->storeRepository->getById((int) $storeArg)
                    : $this->storeRepository->get((string) $storeArg);
            } catch (\Throwable $e) {
                $output->writeln('<error>Store not found: ' . $storeArg . '</error>');
                return Command::FAILURE;
            }
        } else {
            foreach ($this->storeRepository->getList() as $store) {
                if ((int) $store->getId() === 0) {
                    continue;
                }
                $stores[] = $store;
            }
        }

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_related');
        $hasTable = false;
        try {
            $hasTable = $conn->isTableExists($table);
        } catch (\Throwable) {
            $hasTable = false;
        }

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            $output->writeln(sprintf('<info>Computing PageRank for store %s...</info>', $store->getCode()));
            $this->graph->clear($storeId);
            $ranks = $this->pageRank->compute($storeId);
            $output->writeln(sprintf('  nodes ranked: %d', count($ranks)));

            if (!$hasTable) {
                continue;
            }
            // Persist top-N related per source node using PR weight
            // (Embedding-blended suggestions are computed lazily in ViewModel.)
            $conn->delete($table, ['store_id = ?' => $storeId]);
            arsort($ranks);
            $buffer = [];
            $i = 0;
            foreach ($ranks as $node => $score) {
                if (++$i > 10000) {
                    break;
                }
                [$type, $id] = explode(':', $node, 2) + [null, null];
                if ($type === null || $id === null) {
                    continue;
                }
                $buffer[] = [
                    'source_type' => (string) $type,
                    'source_id'   => (int) $id,
                    'target_type' => (string) $type,
                    'target_id'   => (int) $id,
                    'score'       => (float) $score,
                    'store_id'    => $storeId,
                ];
                if (count($buffer) >= 500) {
                    $conn->insertMultiple($table, $buffer);
                    $buffer = [];
                }
            }
            if (!empty($buffer)) {
                $conn->insertMultiple($table, $buffer);
            }
            $output->writeln('  <info>PageRank persisted.</info>');
        }
        return Command::SUCCESS;
    }
}
