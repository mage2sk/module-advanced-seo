<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Redirect;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Redirect\AutoRedirectService;
use Psr\Log\LoggerInterface;

/**
 * Observer on `cms_page_delete_before`.
 *
 * Creates a 301 redirect from the CMS page identifier to the homepage.
 */
class CmsPageDeleteBefore implements ObserverInterface
{
    public function __construct(
        private readonly AutoRedirectService $autoRedirectService,
        private readonly SeoConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isAutoRedirectEnabled()) {
                return;
            }

            /** @var PageInterface $page */
            $event = $observer->getEvent();
            $page = $event->getObject() ?? $event->getPage();
            // model_delete_before fires for EVERY model; strictly gate on type.
            if (!$page instanceof PageInterface || !$page->getId()) {
                return;
            }

            $identifier = (string) $page->getIdentifier();
            if ($identifier === '' || $identifier === 'home' || $identifier === 'no-route') {
                return;
            }

            $storeIds = $page->getStoreId();
            if (!is_array($storeIds)) {
                $storeIds = [(int) $storeIds];
            }

            foreach ($storeIds as $storeId) {
                $this->autoRedirectService->createRedirect($identifier, '/', (int) $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthSEO] CmsPageDeleteBefore observer failed',
                ['error' => $e->getMessage()]
            );
        }
    }
}
