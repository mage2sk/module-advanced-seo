<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Product;

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
            $product = $observer->getEvent()->getProduct() ?: $observer->getEvent()->getObject();
            $productId = $product ? (int) $product->getId() : 0;
            if ($productId <= 0) {
                return;
            }

            $this->cache->invalidateEntity(MetaResolverInterface::ENTITY_PRODUCT, $productId);
            $this->orphanCleaner->forEntity(MetaResolverInterface::ENTITY_PRODUCT, $productId);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO product delete observer failed', ['error' => $e->getMessage()]);
        }
    }
}
