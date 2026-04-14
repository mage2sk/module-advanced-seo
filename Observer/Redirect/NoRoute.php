<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Redirect;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\RedirectMatcherInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Logs 404s on cms_index_noroute dispatch.
 */
class NoRoute implements ObserverInterface
{
    public function __construct(
        private readonly RedirectMatcherInterface $matcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isLog404Enabled()) {
                return;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $path    = (string) $this->request->getPathInfo();
            $referer = (string) ($this->request->getServer('HTTP_REFERER') ?? '');
            $this->matcher->log404($path, $storeId, $referer !== '' ? $referer : null);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] 404 logger failed: ' . $e->getMessage());
        }
    }
}
