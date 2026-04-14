<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Service;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

/**
 * Centralized safety checks for any frontend SEO redirect (trailing-slash,
 * lowercase, homepage, custom rules, etc.).
 *
 * A misfired 301/302 can be catastrophic: on non-GET requests browsers silently
 * convert the method to GET, which turns every AJAX/API POST into a broken GET
 * and 404s it. On XHR requests, a 301 can cause fetch() to follow a redirect
 * the caller never expected.
 *
 * Every SEO redirect path MUST call `isSafeToRedirect()` before issuing a
 * redirect — this is the ONE source of truth.
 */
class RedirectGuard
{
    /**
     * URL path prefixes that should NEVER be redirected by SEO plugins,
     * because they are API endpoints, asset paths, or handle their own routing.
     */
    private const SKIP_PREFIXES = [
        '/rest/',
        '/soap/',
        '/graphql',
        '/V1/',
        '/static/',
        '/media/',
        '/pub/',
        '/errors/',
        '/health_check',
        '/sitemap',
        '/robots.txt',
        '/favicon.ico',
    ];

    public function __construct(
        private readonly State $appState,
        private readonly BackendHelper $backendHelper
    ) {
    }

    /**
     * Returns true only when it is genuinely safe to issue a 301/302 redirect
     * in response to this request.
     */
    public function isSafeToRedirect(RequestInterface $request): bool
    {
        // (1) Only GET. Redirecting a non-GET makes browsers convert it to
        // GET, silently breaking every POST/PUT/DELETE/PATCH AJAX call.
        if (strtoupper((string) $request->getMethod()) !== 'GET') {
            return false;
        }

        // (2) Never redirect XHR/fetch requests. The caller is JS — it did
        // not ask to be bounced to a different URL, and following a redirect
        // can change CORS, caching, and response handling in subtle ways.
        if ($this->isAjax($request)) {
            return false;
        }

        // (3) Never redirect admin requests.
        if ($this->isAdmin($request)) {
            return false;
        }

        // (4) Only run in frontend area (if area is already set).
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return false;
            }
        } catch (\Throwable) {
            // Area not set yet — fall through (we'll still run the other checks).
        }

        // (5) Skip known API/asset/special path prefixes.
        $uri = (string) $request->getRequestUri();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (stripos($uri, $prefix) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect XHR / fetch requests.
     *
     * Regression fix (2026-04-11): inside the `aroundDispatch` plugin context
     * the Magento `Http` request is not always fully hydrated yet. The Laminas
     * Headers collection that backs `$request->getHeader()` is only populated
     * lazily in `_initRequest()`, which runs INSIDE the dispatch loop — AFTER
     * our aroundDispatch plugins wake up. On CLI-hydrated workers with
     * aggressive OPcache priming, `$request->isAjax()` also reports `false`
     * for the same reason: it delegates to the same empty Headers collection
     * via Laminas' `isXmlHttpRequest()`.
     *
     * The one place the raw HTTP header is GUARANTEED to be present at this
     * lifecycle point is the PHP `$_SERVER` superglobal, which FPM populates
     * from the FastCGI params before any PHP code runs. We therefore read
     * `$_SERVER['HTTP_X_REQUESTED_WITH']` (and siblings) as the FIRST attempt
     * and only fall back to the Magento/Laminas helpers after that — the
     * previous order (helpers first, `$_SERVER` last) caused the XHR header
     * to be silently dropped when the helpers returned empty strings, which
     * in turn let SEO redirects fire on XHR/fetch calls.
     */
    private function isAjax(RequestInterface $request): bool
    {
        // (a) RAW $_SERVER first — always populated by PHP-FPM before any
        // framework code runs, so it is the only reliable source inside
        // aroundDispatch plugins. See the class-level docblock for why the
        // previous order (helpers first) dropped XHR detection.
        $xhrHeader = $this->readHeader($request, 'X-Requested-With', 'HTTP_X_REQUESTED_WITH');
        if (strcasecmp($xhrHeader, 'XMLHttpRequest') === 0) {
            return true;
        }

        // (b) Fetch with JSON content type (badges, carts, etc.).
        $contentType = $this->readHeader($request, 'Content-Type', 'CONTENT_TYPE');
        if ($contentType !== '' && stripos($contentType, 'application/json') !== false) {
            return true;
        }

        // (c) Sec-Fetch-Mode: cors / same-origin (set by browsers on fetch/XHR).
        // `navigate` means a real top-level navigation — do NOT treat as AJAX.
        $secFetchMode = $this->readHeader($request, 'Sec-Fetch-Mode', 'HTTP_SEC_FETCH_MODE');
        if ($secFetchMode !== '' && strcasecmp($secFetchMode, 'navigate') !== 0) {
            return true;
        }

        // (d) Shortcut: Magento's Http request exposes a first-class isAjax()
        // which also covers `?ajax=1` / `?isAjax=1` query params. We call it
        // LAST because (1) its return value is unreliable inside aroundDispatch
        // (see docblock) and (2) the query-param branch is the only thing it
        // adds over the header checks above.
        if (method_exists($request, 'isAjax')) {
            try {
                if ($request->isAjax()) {
                    return true;
                }
            } catch (\Throwable) {
                // Ignore — we've already done the important checks.
            }
        }

        return false;
    }

    /**
     * Read an HTTP header using every access pattern exposed by Magento's
     * request object, preferring `$_SERVER` first because it is the only
     * source that is GUARANTEED to be populated inside `aroundDispatch`
     * plugins (the Laminas Headers collection is hydrated lazily later in
     * the dispatch loop, so `getHeader()` / `getServer()` / `getServerValue()`
     * can all return empty strings at this lifecycle point).
     *
     * @param RequestInterface $request
     * @param string $headerName    Canonical header name, e.g. "X-Requested-With"
     * @param string $serverKey     Matching `$_SERVER` key, e.g. "HTTP_X_REQUESTED_WITH"
     */
    private function readHeader(RequestInterface $request, string $headerName, string $serverKey): string
    {
        // 1. $_SERVER superglobal — populated by PHP-FPM from FastCGI params
        // BEFORE any userland PHP runs. Always present, always authoritative.
        $value = (string) ($_SERVER[$serverKey] ?? '');
        if ($value !== '') {
            return $value;
        }

        // 2. getServer() — Magento's Http request wraps $_SERVER. Useful when
        // the request object has been mutated (tests, internal sub-requests).
        if (method_exists($request, 'getServer')) {
            try {
                $value = (string) $request->getServer($serverKey, '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore and try next access pattern.
            }
        }

        // 3. getServerValue() — alias on the PhpEnvironment\Request base class.
        if (method_exists($request, 'getServerValue')) {
            try {
                $value = (string) $request->getServerValue($serverKey, '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore and try next access pattern.
            }
        }

        // 4. getHeader() — only works after `_initRequest()` has populated the
        // Laminas Headers collection, which happens INSIDE the dispatch loop
        // (i.e. AFTER our aroundDispatch plugins run). Kept as a last resort
        // so we still benefit from it in late-lifecycle callers.
        if (method_exists($request, 'getHeader')) {
            try {
                $value = (string) $request->getHeader($headerName);
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Ignore.
            }
        }

        return '';
    }

    /**
     * Detect admin requests by front name.
     */
    private function isAdmin(RequestInterface $request): bool
    {
        $uri = (string) $request->getRequestUri();
        try {
            $adminFront = (string) $this->backendHelper->getAreaFrontName();
            if ($adminFront !== '' && (
                stripos($uri, '/' . $adminFront . '/') === 0
                || stripos($uri, '/' . $adminFront) === 0
            )) {
                return true;
            }
        } catch (\Throwable) {
            // Backend helper not available yet — fall through
        }
        if (stripos($uri, '/admin') === 0) {
            return true;
        }
        return false;
    }
}
