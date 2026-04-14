<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * 301-redirect URLs with a trailing slash to the version without.
 *
 * Respects the `panth_seo/canonical/remove_trailing_slash` config flag.
 * Only applies to frontend requests on non-homepage paths.
 *
 * Runs AFTER HomepageRedirectPlugin and LowercaseRedirectPlugin (sortOrder 15).
 */
class TrailingSlashRedirectPlugin
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
    ): ResponseInterface|ResultInterface {
        try {
            if ($this->shouldRedirect($request)) {
                $uri = (string) $request->getRequestUri();
                $stripped = $this->stripTrailingSlash($uri);

                if ($this->seoDebugLogger !== null && $this->config->isDebug()) {
                    $this->seoDebugLogger->debug('panth_seo: redirect.fired', [
                        'from' => $uri,
                        'to' => $stripped,
                        'plugin' => 'TrailingSlashRedirectPlugin',
                    ]);
                }

                $this->response->setRedirect($stripped, 301);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthSEO] Trailing slash redirect plugin failed, proceeding normally',
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

        if (!$this->config->isEnabled() || !$this->config->canonicalRemoveTrailingSlash()) {
            return false;
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';

        // Only redirect if path ends with a trailing slash and is not the root
        return $path !== '/' && $path !== '' && str_ends_with($path, '/');
    }

    /**
     * Remove the trailing slash from the path portion of the URI, preserving query string.
     */
    private function stripTrailingSlash(string $uri): string
    {
        $parsed = parse_url($uri);
        $path = rtrim($parsed['path'] ?? '/', '/');
        if ($path === '') {
            $path = '/';
        }
        $query = $parsed['query'] ?? '';

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
