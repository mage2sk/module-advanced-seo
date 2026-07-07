<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Response;

use Magento\Framework\App\Area;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Magento\Framework\View\Page\Config as PageConfig;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CanonicalHeaderPlugin
{
    public function __construct(
        private readonly AppState $appState,
        private readonly PageConfig $pageConfig,
        private readonly SeoConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        try {
            if (!$this->isFrontendArea()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            if (!$this->config->isEnabled($storeId) || !$this->config->isCanonicalEnabled($storeId)) {
                return;
            }

            $canonicalUrl = $this->resolveCanonicalFromPageConfig();
            if ($canonicalUrl === '') {
                return;
            }

            $subject->setHeader('Link', '<' . $canonicalUrl . '>; rel="canonical"', true);
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO CanonicalHeaderPlugin: ' . $e->getMessage());
        }
    }

    private function isFrontendArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveCanonicalFromPageConfig(): string
    {
        try {
            $assets = $this->pageConfig->getAssetCollection()->getAll();
            foreach ($assets as $identifier => $asset) {
                $properties = $asset->getContentType();

                if ($properties === 'canonical') {
                    return $identifier;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
