<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento panth:seo:generate`
 *
 * Schedules AI meta generation jobs by enqueuing messages on
 * `panth_seo.generate_meta`. The actual work is done by
 * Panth\AdvancedSEO\Model\Queue\BulkGenerateConsumer.
 */
class GenerateMetaCommand extends Command
{
    private const OPT_TYPE  = 'type';
    private const OPT_STORE = 'store';
    private const OPT_BATCH = 'batch';
    private const OPT_LIMIT = 'limit';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly PublisherInterface $publisher,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:generate')
            ->setDescription('Schedule AI meta generation jobs for an entity type and store.')
            ->addOption(self::OPT_TYPE, 't', InputOption::VALUE_REQUIRED, 'Entity type: product|category|cms', 'product')
            ->addOption(self::OPT_STORE, 's', InputOption::VALUE_REQUIRED, 'Store id (0 = all)', '0')
            ->addOption(self::OPT_BATCH, 'b', InputOption::VALUE_REQUIRED, 'Batch size', '200')
            ->addOption(self::OPT_LIMIT, 'l', InputOption::VALUE_REQUIRED, 'Max entities (0 = all)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $type   = (string) $input->getOption(self::OPT_TYPE);
        $storeId = (int) $input->getOption(self::OPT_STORE);
        $batch   = max(1, (int) $input->getOption(self::OPT_BATCH));
        $limit   = max(0, (int) $input->getOption(self::OPT_LIMIT));

        $validTypes = [
            MetaResolverInterface::ENTITY_PRODUCT,
            MetaResolverInterface::ENTITY_CATEGORY,
            MetaResolverInterface::ENTITY_CMS,
        ];
        if (!in_array($type, $validTypes, true)) {
            $output->writeln('<error>Invalid --type. Use one of: ' . implode(', ', $validTypes) . '</error>');
            return Command::INVALID;
        }

        $storeIds = [];
        if ($storeId > 0) {
            $storeIds[] = $storeId;
        } else {
            foreach ($this->storeRepository->getList() as $store) {
                if ((int) $store->getId() > 0) {
                    $storeIds[] = (int) $store->getId();
                }
            }
        }

        $connection = $this->resource->getConnection();
        $table = match ($type) {
            MetaResolverInterface::ENTITY_PRODUCT  => $this->resource->getTableName('catalog_product_entity'),
            MetaResolverInterface::ENTITY_CATEGORY => $this->resource->getTableName('catalog_category_entity'),
            MetaResolverInterface::ENTITY_CMS      => $this->resource->getTableName('cms_page'),
        };
        $idColumn = $type === MetaResolverInterface::ENTITY_CMS ? 'page_id' : 'entity_id';

        $select = $connection->select()->from($table, [$idColumn]);
        if ($limit > 0) {
            $select->limit($limit);
        }
        $ids = array_map('intval', $connection->fetchCol($select));

        $total = 0;
        foreach ($storeIds as $store) {
            foreach (array_chunk($ids, $batch) as $chunk) {
                foreach ($chunk as $id) {
                    $this->publisher->publish(
                        'panth_seo.generate_meta',
                        json_encode([
                            'entity_type' => $type,
                            'entity_id'   => $id,
                            'store_id'    => $store,
                        ]) ?: ''
                    );
                    $total++;
                }
            }
        }

        $output->writeln(sprintf('<info>Queued %d generation jobs (type=%s).</info>', $total, $type));
        return Command::SUCCESS;
    }
}
