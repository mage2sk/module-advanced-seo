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

/**
 * Emits a Link: <URL>; rel="canonical" HTTP header on frontend responses.
 *
 * This is a belt-and-suspenders approach: the HTML <link rel="canonical">
 * tag is authoritative, but duplicating the directive in an HTTP header
 * provides coverage for crawlers that inspect headers before parsing HTML
 * and for non-HTML resources that may carry a canonical.
 */
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

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
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

    /**
     * Extract the canonical URL that was already set in the page <head> by
     * the HeadPlugin or Magento's native canonical logic.
     *
     * We read from the page config asset collection so that we reflect
     * whatever canonical was ultimately resolved (custom overrides, etc.)
     * without duplicating the resolution logic.
     */
    private function resolveCanonicalFromPageConfig(): string
    {
        try {
            $assets = $this->pageConfig->getAssetCollection()->getAll();
            foreach ($assets as $identifier => $asset) {
                $properties = $asset->getContentType();
                // Magento stores canonical links with content type 'canonical'
                if ($properties === 'canonical') {
                    return $identifier;
                }
            }
        } catch (\Throwable) {
            // Page config may not be initialized for non-page results.
        }

        return '';
    }
}
