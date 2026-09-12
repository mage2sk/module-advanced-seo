<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Psr\Log\LoggerInterface;

class PruneOrphanSeoRows implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly OrphanCleaner $orphanCleaner,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        try {
            $removed = $this->orphanCleaner->sweep();
            if ($removed !== []) {
                $this->logger->info('Panth SEO: pruned SEO rows for deleted entities on upgrade', $removed);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: could not prune SEO rows for deleted entities: ' . $e->getMessage());
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
