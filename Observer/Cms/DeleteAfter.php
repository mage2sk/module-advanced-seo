<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Cms;

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
            $page = $observer->getEvent()->getObject() ?: $observer->getEvent()->getPage();
            $pageId = $page ? (int) $page->getId() : 0;
            if ($pageId <= 0) {
                return;
            }

            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_CMS, $pageId);
            $this->orphanCleaner->forEntity(MetaResolverInterface::ENTITY_CMS, $pageId);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO cms delete observer failed', ['error' => $e->getMessage()]);
        }
    }
}
