<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Redirect;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Service\RedirectGuard;
use Psr\Log\LoggerInterface;

/**
 * 301-redirect common homepage aliases (/index.php, /home, /cms/index, etc.) to the store root.
 *
 * Runs BEFORE LowercaseRedirectPlugin (sortOrder 5 vs 10) so the redirect
 * happens on the original URI before any case-normalisation.
 *
 * Two subtle bugs this class has been hardened against:
 *
 *   (1) `/index.php` path detection. When the client literally requests
 *       `/index.php`, nginx's FastCGI SCRIPT_NAME rewrite consumes the path
 *       segment so Magento's Http request arrives with an empty
 *       `getPathInfo()`. The old code treated that as "no path, nothing to
 *       redirect" and returned false. The new {@see self::extractPath()}
 *       falls back to parsing `getRequestUri()` (and `$_SERVER['REQUEST_URI']`
 *       as a last resort) so `/index.php` is detected correctly.
 *
 *   (2) HEAD requests were dropped by the shared {@see RedirectGuard}.
 *       {@see RedirectGuard::isSafeToRedirect()} enforces a strict GET-only
 *       rule (because blindly redirecting a POST would silently downgrade
 *       it to GET). That is correct for lowercase / trailing-slash
 *       redirects, but it broke HEAD requests for homepage aliases —
 *       crawlers and monitoring tools often HEAD `/home` and `/index.php`,
 *       and they MUST see a 301 just like GET. HEAD is body-less and
 *       idempotent, so a 301 response is always safe. We therefore run
 *       the rest of the guard's checks (admin, XHR, API/static prefixes,
 *       frontend area) but explicitly allow HEAD in addition to GET for
 *       this one plugin. See {@see self::isSafeForHomepageRedirect()}.
 *
 * We also set {@see HttpResponse::setNoCacheHeaders()} on the 301 so that
 * it is never stored in Magento FPC / Varnish — otherwise a cached redirect
 * could pin `/home` → `/` forever and block future admin-side edits.
 */
class HomepageRedirectPlugin
{
    /**
     * Path patterns that should be treated as homepage aliases.
     * Matched against the trimmed, lowercase request path (without trailing slash).
     *
     * @var string[]
     */
    private const HOMEPAGE_ALIASES = [
        '/index.php',
        '/home',
        '/cms/index',
        '/cms/index/index',
    ];

    /**
     * HTTP methods that can safely receive a 301 redirect. HEAD is included
     * because it is body-less and idempotent; crawlers / monitoring probes
     * should see the same 301 a GET would see.
     */
    private const REDIRECTABLE_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly SeoConfig $config,
        private readonly HttpResponse $response,
        private readonly StoreManagerInterface $storeManager,
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
                $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/';

                if ($this->seoDebugLogger !== null && $this->config->isDebug()) {
                    $this->seoDebugLogger->debug('panth_seo: redirect.fired', [
                        'from' => (string) $request->getRequestUri(),
                        'to' => $baseUrl,
                        'plugin' => 'HomepageRedirectPlugin',
                    ]);
                }

                $this->response->setRedirect($baseUrl, 301);
                // Ensure the 301 itself is never cached by Magento FPC, Varnish,
                // or any downstream CDN — otherwise a single cached entry would
                // pin the redirect forever and block future admin-side edits.
                $this->response->setNoCacheHeaders();
                $this->response->setHeader('X-Magento-Tags', '', true);
                $this->response->sendHeaders();

                return $this->response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PanthSEO] Homepage redirect plugin failed, proceeding normally',
                ['error' => $e->getMessage()]
            );
        }

        return $proceed($request);
    }

    private function shouldRedirect(RequestInterface $request): bool
    {
        // (a) Feature flag: both module kill-switch and homepage-specific flag.
        if (!$this->config->isEnabled() || !$this->config->isHomepageRedirectEnabled()) {
            return false;
        }

        // (b) Allow only idempotent methods (GET / HEAD). We intentionally do
        // NOT delegate to RedirectGuard::isSafeToRedirect() here because its
        // strict "GET-only" rule incorrectly drops HEAD requests — crawlers
        // and health checks commonly HEAD `/home` and `/index.php`, and they
        // MUST see the 301.
        $method = strtoupper((string) $request->getMethod());
        if (!in_array($method, self::REDIRECTABLE_METHODS, true)) {
            return false;
        }

        // (c) Everything else — XHR detection, admin-front detection, area
        // check, API/static path prefix skip — is the same logic the other
        // SEO redirects use, so reuse RedirectGuard for it. To work around
        // the GET-only check we ran in (b), we spoof the method to GET for
        // this single call and restore it immediately afterwards. This keeps
        // HEAD requests eligible while still enforcing every other safety rail.
        $safe = $this->isSafeForHomepageRedirect($request);
        if (!$safe) {
            return false;
        }

        $path = $this->extractPath($request);
        if ($path === '' || $path === '/') {
            return false;
        }

        // Normalise: strip trailing slash, lowercase for comparison
        $normalised = strtolower(rtrim($path, '/'));

        return in_array($normalised, self::HOMEPAGE_ALIASES, true);
    }

    /**
     * Run the shared {@see RedirectGuard} checks but without its GET-only
     * rule. The guard reads the method via `$request->getMethod()` which
     * Laminas caches on the request object at construction time, so we
     * temporarily overwrite it with GET for the duration of the call and
     * restore it in `finally`. The guard performs only read operations on
     * the request, so this workaround is side-effect free externally.
     */
    private function isSafeForHomepageRedirect(RequestInterface $request): bool
    {
        if (!method_exists($request, 'setMethod') || !method_exists($request, 'getMethod')) {
            // Unknown request type — fall back to the strict guard.
            return $this->redirectGuard->isSafeToRedirect($request);
        }

        $originalMethod = (string) $request->getMethod();
        if (strtoupper($originalMethod) === 'GET') {
            return $this->redirectGuard->isSafeToRedirect($request);
        }

        try {
            $request->setMethod('GET');
            return $this->redirectGuard->isSafeToRedirect($request);
        } finally {
            $request->setMethod($originalMethod);
        }
    }

    /**
     * Extract the path portion of the request in a way that is robust across
     * nginx+FPM rewrite stacks, where `getPathInfo()` can arrive empty when
     * the client literally requested `/index.php`.
     *
     * Strategy:
     *   1. Prefer getPathInfo() when non-empty (the normal case).
     *   2. Otherwise parse the raw request URI's path component.
     *   3. As a last resort, read `$_SERVER['REQUEST_URI']` — always present
     *      in the FPM worker, even when Magento's Http request hasn't been
     *      fully hydrated yet.
     */
    private function extractPath(RequestInterface $request): string
    {
        $path = (string) $request->getPathInfo();
        if ($path !== '' && $path !== '/') {
            return $path;
        }

        $uri = (string) $request->getRequestUri();
        if ($uri === '' || $uri === '/') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }

        if ($uri === '') {
            return $path;
        }

        // Strip the query string, leaving only the path component.
        $parsed = parse_url($uri, PHP_URL_PATH);
        if (!is_string($parsed) || $parsed === '') {
            return $path;
        }

        return $parsed;
    }
}
