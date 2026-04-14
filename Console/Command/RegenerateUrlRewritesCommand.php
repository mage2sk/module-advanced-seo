<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Model\Url\RewriteRegenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento panth:seo:regenerate-urls`
 *
 * Deletes existing catalog URL rewrites and regenerates them
 * in batches using Magento's native rewrite generators.
 */
class RegenerateUrlRewritesCommand extends Command
{
    private const OPT_ENTITY   = 'entity';
    private const OPT_STORE    = 'store';
    private const OPT_IDS      = 'ids';
    private const OPT_ID_RANGE = 'id-range';

    private const ENTITY_PRODUCT  = 'product';
    private const ENTITY_CATEGORY = 'category';
    private const ENTITY_ALL      = 'all';

    public function __construct(
        private readonly RewriteRegenerator $rewriteRegenerator,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth:seo:regenerate-urls')
            ->setDescription('Regenerate catalog URL rewrites for products and/or categories.')
            ->addOption(
                self::OPT_ENTITY,
                'e',
                InputOption::VALUE_REQUIRED,
                'Entity type to regenerate: product, category, or all',
                self::ENTITY_ALL
            )
            ->addOption(
                self::OPT_STORE,
                's',
                InputOption::VALUE_REQUIRED,
                'Store ID or store code (0 = all stores)',
                '0'
            )
            ->addOption(
                self::OPT_IDS,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated entity IDs to regenerate (e.g. "1,5,42")'
            )
            ->addOption(
                self::OPT_ID_RANGE,
                null,
                InputOption::VALUE_REQUIRED,
                'ID range in format "start-end" (e.g. "1-100")'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable) {
            // already set
        }

        $entity = (string) $input->getOption(self::OPT_ENTITY);
        $validEntities = [self::ENTITY_PRODUCT, self::ENTITY_CATEGORY, self::ENTITY_ALL];
        if (!in_array($entity, $validEntities, true)) {
            $output->writeln(sprintf(
                '<error>Invalid --entity value "%s". Use one of: %s</error>',
                $entity,
                implode(', ', $validEntities)
            ));
            return Command::INVALID;
        }

        try {
            $storeId = $this->resolveStoreId((string) $input->getOption(self::OPT_STORE));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::INVALID;
        }

        try {
            $ids = $this->resolveIds($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::INVALID;
        }

        $output->writeln(sprintf(
            '<info>Regenerating URL rewrites (entity=%s, store=%d, ids=%s)...</info>',
            $entity,
            $storeId,
            !empty($ids) ? implode(',', $ids) : 'all'
        ));

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat(' %current% URLs regenerated [%bar%] %elapsed:6s% %memory:6s%');
        $progressBar->start();

        $totalGenerated = 0;

        if ($entity === self::ENTITY_PRODUCT || $entity === self::ENTITY_ALL) {
            $count = $this->rewriteRegenerator->regenerateProducts($storeId, $ids);
            $totalGenerated += $count;
            $progressBar->advance($count);
        }

        if ($entity === self::ENTITY_CATEGORY || $entity === self::ENTITY_ALL) {
            $count = $this->rewriteRegenerator->regenerateCategories($storeId, $ids);
            $totalGenerated += $count;
            $progressBar->advance($count);
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done. Regenerated %d URL rewrite(s).</info>',
            $totalGenerated
        ));

        return Command::SUCCESS;
    }

    /**
     * Resolve store option into a numeric store ID.
     * Accepts a numeric ID or a store code.
     *
     * @throws \InvalidArgumentException
     */
    private function resolveStoreId(string $storeOption): int
    {
        if (is_numeric($storeOption)) {
            return (int) $storeOption;
        }

        // Treat as store code
        try {
            $store = $this->storeRepository->get($storeOption);
            return (int) $store->getId();
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                sprintf('Store with code "%s" does not exist.', $storeOption)
            );
        }
    }

    /**
     * Resolve --ids and --id-range options into an array of entity IDs.
     *
     * @return int[]
     * @throws \InvalidArgumentException
     */
    private function resolveIds(InputInterface $input): array
    {
        $idsOption = $input->getOption(self::OPT_IDS);
        $rangeOption = $input->getOption(self::OPT_ID_RANGE);

        if ($idsOption !== null && $rangeOption !== null) {
            throw new \InvalidArgumentException(
                'Options --ids and --id-range are mutually exclusive. Use only one.'
            );
        }

        if ($idsOption !== null) {
            $ids = array_filter(
                array_map('intval', explode(',', (string) $idsOption)),
                static fn (int $id): bool => $id > 0
            );
            if (empty($ids)) {
                throw new \InvalidArgumentException(
                    'Option --ids must contain at least one valid positive integer.'
                );
            }
            return $ids;
        }

        if ($rangeOption !== null) {
            if (!preg_match('/^(\d+)-(\d+)$/', (string) $rangeOption, $matches)) {
                throw new \InvalidArgumentException(
                    'Option --id-range must be in "start-end" format (e.g. "1-100").'
                );
            }

            $start = (int) $matches[1];
            $end   = (int) $matches[2];

            if ($start < 1 || $end < $start) {
                throw new \InvalidArgumentException(
                    'Option --id-range: start must be >= 1 and end must be >= start.'
                );
            }

            return range($start, $end);
        }

        return [];
    }
}
