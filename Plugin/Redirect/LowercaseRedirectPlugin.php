<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

class LowercaseRedirectPlugin
{
    public function __construct(
        private readonly SeoConfig $config,
        private readonly HttpResponse $response,
        private readonly LoggerInterface $logger,
        private readonly RedirectGuard $redirectGuard,
        private readonly ?SeoDebugLogger $seoDebugLogger = null
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ): ResponseInterface|\Magento\Framework\Controller\ResultInterface {
        try {
            if ($this->shouldRedirect($request)) {
                $uri = (string) $request->getRequestUri();
                $lowered = $this->lowercaseUri($uri);

                if ($this->seoDebugLogger !== null && $this->config->isDebug()) {
                    $this->seoDebugLogger->debug('panth_seo: redirect.fired', [
                        'from' => $uri,
                        'to' => $lowered,
                        'plugin' => 'LowercaseRedirectPlugin',
                    ]);
                }

                $this->response->setRedirect($lowered, 301);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthSEO] Lowercase redirect plugin failed, proceeding normally',
                ['error' => $e->getMessage()]
            );
        }

        return $proceed($request);
    }

    private function shouldRedirect(RequestInterface $request): bool
    {
        // Centralized safety checks: GET only, non-XHR, frontend, not API/static
        if (!$this->redirectGuard->isSafeToRedirect($request)) {
            return false;
        }

        $uri = (string) $request->getRequestUri();
        if ($uri === '' || $uri === '/') {
            return false;
        }

        if (!$this->config->isEnabled() || !$this->config->isLowercaseRedirectEnabled()) {
            return false;
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        return $path !== '' && $path !== strtolower($path);
    }

    /**
     * Lowercase only the path portion of the URI, preserving query string case.
     */
    private function lowercaseUri(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = strtolower($parsed['path'] ?? '/');
        $query = $parsed['query'] ?? '';

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
