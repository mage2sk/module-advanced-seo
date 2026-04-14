<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Router\NoRouteHandlerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Redirect\NotFoundLogger;
use Psr\Log\LoggerInterface;

/**
 * Logs 404 requests when the NoRouteHandler processes them.
 * This fires for ALL 404s regardless of how the no-route is handled.
 */
class NoRouteLoggerPlugin
{
    public function __construct(
        private readonly NotFoundLogger $notFoundLogger,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * After the no-route handler processes, log the 404.
     */
    public function afterProcess(
        NoRouteHandlerInterface $subject,
        bool $result,
        RequestInterface $request
    ): bool {
        try {
            if (!$this->config->isEnabled() || !$this->config->isLog404Enabled()) {
                return $result;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $path = (string) $request->getPathInfo();
            $referer = (string) ($request->getServer('HTTP_REFERER') ?? '');

            $this->notFoundLogger->log($path, $storeId, $referer !== '' ? $referer : null);
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] 404 logger plugin failed: ' . $e->getMessage());
        }

        return $result;
    }
}
