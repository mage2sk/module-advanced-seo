<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Psr\Log\LoggerInterface;

class PruneOrphans
{
    public function __construct(
        private readonly OrphanCleaner $orphanCleaner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $removed = $this->orphanCleaner->sweep();
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO orphan prune failed: ' . $e->getMessage());
            return;
        }

        if ($removed === []) {
            return;
        }

        $this->logger->info('Panth SEO: pruned SEO rows for deleted entities', $removed);
    }
}
