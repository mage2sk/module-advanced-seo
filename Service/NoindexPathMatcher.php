<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Service;

use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Matches request paths against the configured noindex pattern list so
 * private / customer-scoped pages never emit an indexable robots directive.
 *
 * Private URLs (customer, checkout, wishlist, order history, etc.) have no
 * business being indexed: they are behind robots.txt but crawlers may still
 * discover them via sitemaps, referrers, or past caches. Emitting an explicit
 * `noindex,nofollow` directive at BOTH the HTML meta and X-Robots-Tag HTTP
 * header is the only way to guarantee de-indexing.
 *
 * Patterns are newline-separated path expressions, same format as
 * panth_seo/canonical/canonical_ignore_pages:
 *   - Leading slash is optional; matching is normalized.
 *   - `*` is a wildcard (zero or more characters).
 *   - Blank lines and lines starting with `#` are ignored.
 *
 * The service is stateless aside from a per-store memoized regex so repeated
 * lookups inside a single request are a cheap preg_match call.
 */
class NoindexPathMatcher
{
    /**
     * Fallback default pattern list used when the admin field is blank. These
     * mirror the private URL patterns documented in robots.txt and cover the
     * pages that have no SEO value whatsoever.
     */
    public const DEFAULT_PATTERNS = [
        '/customer/*',
        '/checkout',
        '/checkout/*',
        '/wishlist',
        '/wishlist/*',
        '/sales/*',
        '/contact',
        '/contact/*',
        '/contacts',
        '/contacts/*',
        '/catalogsearch/*',
        '/multishipping/*',
        '/newsletter/manage',
        '/newsletter/manage/*',
        '/review/customer/*',
        '/captcha',
        '/captcha/*',
        '/sendfriend/*',
        '/paypal/*',
        '/downloadable/customer/*',
        '/vault/*',
        '/giftcard/customer/*',
        '/rewards/*',
        '/oauth/*',
        '/connect/*',
    ];

    /**
     * Cached compiled regex, keyed by store id. A value of '' means
     * "no patterns configured" (short-circuit, always false).
     *
     * @var array<int,string>
     */
    private array $compiled = [];

    public function __construct(
        private readonly SeoConfig $config
    ) {
    }

    /**
     * Return true when the given request path matches any of the configured
     * noindex patterns. The path may include a leading slash and query string;
     * only the path component is considered.
     */
    public function isNoindexPath(string $path, ?int $storeId = null): bool
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return false;
        }
        $regex = $this->regexForStore($storeId);
        if ($regex === '') {
            return false;
        }
        return (bool) preg_match($regex, $normalized);
    }

    /**
     * Expose the effective pattern list (admin override or defaults) so
     * callers (tests, debug toolbars, CLI tooling) can introspect the active
     * policy without re-implementing the parsing.
     *
     * @return string[]
     */
    public function getPatterns(?int $storeId = null): array
    {
        $raw = trim($this->config->getNoindexPaths($storeId));
        if ($raw === '') {
            return self::DEFAULT_PATTERNS;
        }
        $patterns = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $patterns[] = $line;
        }
        return $patterns === [] ? self::DEFAULT_PATTERNS : $patterns;
    }

    /**
     * Compile and cache the pattern list into a single alternation regex
     * per store id. Compilation happens at most once per request.
     */
    private function regexForStore(?int $storeId): string
    {
        $key = (int) ($storeId ?? 0);
        if (isset($this->compiled[$key])) {
            return $this->compiled[$key];
        }
        $parts = [];
        foreach ($this->getPatterns($storeId) as $pattern) {
            $normalized = $this->normalizePath($pattern);
            if ($normalized === '') {
                continue;
            }
            // Escape regex metacharacters, then reinstate `*` as `.*`.
            $escaped = preg_quote($normalized, '#');
            $escaped = str_replace('\*', '.*', $escaped);
            $parts[] = '(?:' . $escaped . ')';
        }
        if ($parts === []) {
            return $this->compiled[$key] = '';
        }
        return $this->compiled[$key] = '#^(?:' . implode('|', $parts) . ')/?$#i';
    }

    /**
     * Normalize a URI/path for comparison: strip query + fragment, ensure a
     * single leading slash, and trim trailing slashes (so `/customer/` and
     * `/customer` both match `/customer`).
     */
    private function normalizePath(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $path = parse_url($value, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = $value;
        }
        $path = (string) $path;
        // Collapse multiple slashes, enforce leading slash, drop trailing.
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }
}
