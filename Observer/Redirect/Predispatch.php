<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Redirect;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;
use Panth\AdvancedSEO\Api\RedirectMatcherInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * controller_action_predispatch observer. Runs the Matcher; on a hit,
 * issues the redirect and stops action dispatch.
 */
class Predispatch implements ObserverInterface
{
    public function __construct(
        private readonly RedirectMatcherInterface $matcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly RedirectGuard $redirectGuard,
        private readonly ?SeoDebugLogger $seoDebugLogger = null
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }
            // Centralized safety: GET only, non-XHR, frontend, not API/static.
            // Prevents this observer from ever turning a POST/PUT/DELETE AJAX
            // call into a broken GET redirect.
            if (!$this->redirectGuard->isSafeToRedirect($this->request)) {
                return;
            }
            if (!$this->isFrontendRequest()) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $path    = (string) $this->request->getPathInfo();

            $rule = $this->matcher->match($path, $storeId);
            if ($rule === null) {
                return;
            }
            if ($this->seoDebugLogger !== null && $this->config->isDebug()) {
                $this->seoDebugLogger->debug('panth_seo: redirect.rule_matched', [
                    'from' => $path,
                    'to' => (string) $rule->getTarget(),
                    'rule_id' => $rule->getRedirectId(),
                    'match_type' => $rule->getMatchType(),
                    'status' => $rule->getStatusCode() ?: 301,
                    'observer' => 'Predispatch',
                ]);
            }
            if ($rule->getMatchType() === RedirectRuleInterface::MATCH_MAINTENANCE) {
                $this->response->setStatusHeader(503, null, 'Service Unavailable');
                $this->response->setHeader('Retry-After', '3600', true);
                $this->response->setBody((string) $rule->getTarget());
                $this->actionFlag->set('', \Magento\Framework\App\ActionInterface::FLAG_NO_DISPATCH, true);
                return;
            }

            $target = (string) $rule->getTarget();
            if ($target === '') {
                return;
            }
            // Block dangerous URI schemes that could be used for phishing/XSS
            if (preg_match('#^(javascript|data|vbscript):#i', $target)) {
                $this->logger->warning('[PanthSEO] redirect blocked: dangerous URI scheme in target', [
                    'target' => mb_substr($target, 0, 200),
                ]);
                return;
            }
            // For absolute URLs, verify the host matches one of our configured store base URLs
            if (preg_match('#^https?://#i', $target)) {
                $targetHost = (string) parse_url($target, PHP_URL_HOST);
                if ($targetHost !== '' && !$this->isAllowedHost($targetHost)) {
                    $this->logger->warning('[PanthSEO] redirect blocked: external host not in store URLs', [
                        'target_host' => $targetHost,
                    ]);
                    return;
                }
            } elseif ($target[0] !== '/') {
                $target = '/' . $target;
            }
            $status = $rule->getStatusCode() ?: 301;
            $this->response->setRedirect($target, $status);
            $this->actionFlag->set('', \Magento\Framework\App\ActionInterface::FLAG_NO_DISPATCH, true);

            $id = $rule->getRedirectId();
            if ($id !== null) {
                $this->matcher->recordHit($id);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] redirect predispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if the given host matches any configured store base URL host.
     * Prevents open-redirect to external domains.
     */
    private function isAllowedHost(string $host): bool
    {
        try {
            foreach ($this->storeManager->getStores() as $store) {
                $baseUrl = $store->getBaseUrl();
                $storeHost = (string) parse_url($baseUrl, PHP_URL_HOST);
                if ($storeHost !== '' && strcasecmp($host, $storeHost) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // If store manager fails, allow the redirect (fail-open for availability)
            return true;
        }
        return false;
    }

    private function isFrontendRequest(): bool
    {
        $areaFront = method_exists($this->request, 'getFrontName') ? (string) $this->request->getFrontName() : '';
        // Magento's adminhtml area uses a dedicated front name; skip non-frontend.
        if ($areaFront === 'admin' || $areaFront === '' && strpos((string) $this->request->getPathInfo(), '/admin') === 0) {
            return false;
        }
        return true;
    }
}
