<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Category;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Psr\Log\LoggerInterface;

class DeleteAfter implements ObserverInterface
{
    public function __construct(
        private readonly OrphanCleaner $orphanCleaner,
        private readonly MetaCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $category = $observer->getEvent()->getCategory() ?: $observer->getEvent()->getObject();
            $categoryId = $category ? (int) $category->getId() : 0;
            if ($categoryId <= 0) {
                return;
            }

            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_CATEGORY, $categoryId);
            $this->orphanCleaner->forEntity(MetaResolverInterface::ENTITY_CATEGORY, $categoryId);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO category delete observer failed', ['error' => $e->getMessage()]);
        }
    }
}
