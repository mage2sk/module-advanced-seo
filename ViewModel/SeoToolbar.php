<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\HreflangResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\RobotsPolicyInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Social\OpenGraphResolver;
use Panth\AdvancedSEO\Model\Social\TwitterCardResolver;
use Panth\AdvancedSEO\Model\StructuredData\Composite;

/**
 * Collects all SEO signals for the current page and exposes them to the
 * frontend toolbar. Hyva-safe: no jQuery, no RequireJS.
 *
 * The data returned by {@see getData()} is used by the client-side toolbar
 * to render a diagnostic overlay. Any value that requires HTML parsing is
 * intentionally NOT collected here — those values are computed client-side
 * from the rendered DOM so the toolbar does not double-parse the response.
 */
class SeoToolbar implements ArgumentInterface
{
    public function __construct(
        private readonly PageConfig $pageConfig,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly RobotsPolicyInterface $robotsPolicy,
        private readonly HreflangResolverInterface $hreflangResolver,
        private readonly OpenGraphResolver $openGraphResolver,
        private readonly TwitterCardResolver $twitterCardResolver,
        private readonly Composite $structuredDataComposite,
        private readonly SeoConfig $config,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly DesignInterface $design,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Check whether the toolbar should be rendered for the current visitor.
     *
     * Rules:
     *  - Module disabled OR toolbar disabled → deny.
     *  - Allowed-IPs empty  → allow all visitors (explicit opt-in by admin).
     *  - Allowed-IPs non-empty → the client IP must match a literal entry
     *    OR fall inside a CIDR range. Invalid/malformed entries are ignored.
     */
    public function isAllowed(): bool
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isSeoToolbarEnabled()) {
                return false;
            }

            $allowedIps = trim($this->config->getSeoToolbarAllowedIps());

            // Empty list == allow all (per module spec).
            if ($allowedIps === '') {
                return true;
            }

            $clientIp = $this->getClientIp();
            if ($clientIp === '') {
                return false;
            }

            $entries = array_filter(
                array_map('trim', explode(',', $allowedIps)),
                static fn (string $e): bool => $e !== ''
            );

            foreach ($entries as $entry) {
                if ($this->ipMatches($clientIp, $entry)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether $clientIp matches $entry, where $entry is either an IP
     * literal (IPv4/IPv6) or a CIDR block such as "10.0.0.0/8" or "::1/128".
     *
     * Safe against malformed input: returns false on any parse failure and
     * clamps prefix lengths to their legal maxima (32 for IPv4, 128 for IPv6)
     * so "0.0.0.0/0" simply matches every IPv4 address without recursion.
     */
    private function ipMatches(string $clientIp, string $entry): bool
    {
        try {
            $clientPacked = inet_pton($clientIp);
        } catch (\Throwable) {
            return false;
        }
        if ($clientPacked === false) {
            return false;
        }

        // Literal IP compare (normalised via inet_pton so 127.0.0.1 == 127.000.000.001)
        if (!str_contains($entry, '/')) {
            try {
                $entryPacked = inet_pton($entry);
            } catch (\Throwable) {
                return false;
            }
            return $entryPacked !== false && hash_equals($entryPacked, $clientPacked);
        }

        [$subnet, $prefix] = explode('/', $entry, 2);
        if (!ctype_digit($prefix)) {
            return false;
        }
        $prefixLen = (int) $prefix;

        try {
            $subnetPacked = inet_pton($subnet);
        } catch (\Throwable) {
            return false;
        }
        if ($subnetPacked === false) {
            return false;
        }

        // Address families must match (v4-in-v4, v6-in-v6)
        if (strlen($subnetPacked) !== strlen($clientPacked)) {
            return false;
        }

        $bits = strlen($subnetPacked) * 8;
        if ($prefixLen < 0) {
            return false;
        }
        if ($prefixLen > $bits) {
            $prefixLen = $bits; // clamp
        }

        if ($prefixLen === 0) {
            return true;
        }

        $fullBytes = intdiv($prefixLen, 8);
        $remainder = $prefixLen % 8;

        if ($fullBytes > 0 && substr($subnetPacked, 0, $fullBytes) !== substr($clientPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainder !== 0) {
            $mask  = chr(0xFF << (8 - $remainder) & 0xFF);
            $byteA = $subnetPacked[$fullBytes] & $mask;
            $byteB = $clientPacked[$fullBytes] & $mask;
            if ($byteA !== $byteB) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aggregate every SEO signal for the current page.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $title       = $this->getTitle();
        $description = $this->getMetaDescription();
        [$entityType, $entityId] = $this->detectEntity();
        $storeId = 0;
        $storeName = '';
        $baseUrl = '';
        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $storeName = (string) $store->getName();
            $baseUrl = (string) $store->getBaseUrl();
        } catch (\Throwable) {
        }

        $detectedType = $entityType ?? $this->detectRouteType();

        return [
            // --- Section 1: Page Identity ---
            'identity' => [
                'entity_type'    => $detectedType,
                'entity_id'      => $entityId,
                'store_id'       => $storeId,
                'store_name'     => $storeName,
                'theme_name'     => $this->getThemeName(),
                'current_url'    => $this->getCurrentUrl(),
                'request_method' => (string) $this->request->getMethod(),
                'full_action'    => $this->getFullActionName(),
                'module_name'    => (string) $this->request->getModuleName(),
            ],

            // --- Section 2: Meta tags ---
            'meta' => [
                'title'            => $title,
                'title_length'     => mb_strlen($title),
                'description'      => $description,
                'description_length' => mb_strlen($description),
                'keywords'         => $this->getMetaKeywords(),
                'robots'           => $this->getRobots(),
                'canonical'        => $this->getCanonicalUrl(),
                'base_url'         => $baseUrl,
            ],

            // --- Section 3: Open Graph ---
            'og_tags' => $this->getOpenGraphTags(),

            // --- Section 4: Twitter Card ---
            'twitter_tags' => $this->getTwitterTags(),

            // --- Section 5: Hreflang ---
            'hreflang' => $this->getHreflangLinks(),

            // --- Section 6: JSON-LD ---
            'jsonld' => $this->getJsonLdBlocks(),

            // --- Section 11: Schema validation (warnings collected) ---
            'jsonld_warnings' => $this->getJsonLdWarnings(),

            // --- Section 13: HTTP Headers (sniffed) ---
            'headers' => $this->getResponseHeaders(),

            // --- Section 14: Cookies ---
            'cookies' => $this->getCookieNames(),

            // --- Section 15: SEO Score ---
            'score' => $this->getSeoScore($detectedType, $entityId, $storeId),
        ];
    }

    // ------------------------------------------------------------------
    // Individual data collectors
    // ------------------------------------------------------------------

    private function getTitle(): string
    {
        try {
            return (string) $this->pageConfig->getTitle()->get();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getMetaDescription(): string
    {
        try {
            return (string) $this->pageConfig->getDescription();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getMetaKeywords(): string
    {
        try {
            return (string) $this->pageConfig->getKeywords();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getCanonicalUrl(): string
    {
        [$type, $id] = $this->detectEntity();
        if ($type === null) {
            return '';
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            return $this->canonicalResolver->getCanonicalUrl($type, $id, $storeId);
        } catch (\Throwable) {
            return '';
        }
    }

    private function getRobots(): string
    {
        [$type, $id] = $this->detectEntity();
        if ($type === null) {
            try {
                return $this->config->getDefaultMetaRobots();
            } catch (\Throwable) {
                return '';
            }
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            return $this->robotsPolicy->getMetaRobots($type, $id, $storeId);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int, array{locale: string, url: string}>
     */
    private function getHreflangLinks(): array
    {
        [$type, $id] = $this->detectEntity();
        if ($type === null) {
            return [];
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            return $this->hreflangResolver->getAlternates($type, $id, $storeId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return each JSON-LD block the module would emit, with its @type list
     * AND a pretty-printed JSON body for display in the toolbar.
     *
     * @return array<int, array{types: list<string>, pretty: string}>
     */
    private function getJsonLdBlocks(): array
    {
        try {
            $json = $this->structuredDataComposite->build();
            if ($json === '') {
                return [];
            }
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return [];
            }

            // If this is a composite @graph, break it into one block per node
            // so each can be inspected independently.
            $blocks = [];
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $blocks[] = [
                        'types'  => $this->extractTypes($node),
                        'pretty' => (string) json_encode(
                            $node,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                    ];
                }
            } else {
                $blocks[] = [
                    'types'  => $this->extractTypes($decoded),
                    'pretty' => (string) json_encode(
                        $decoded,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ];
            }
            return $blocks;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return a list of human-readable warnings about the JSON-LD payload
     * (missing required @context, missing @type, parse error, etc.).
     *
     * @return list<string>
     */
    private function getJsonLdWarnings(): array
    {
        $warnings = [];
        try {
            $json = $this->structuredDataComposite->build();
            if ($json === '') {
                $warnings[] = 'No JSON-LD emitted for this page.';
                return $warnings;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $warnings[] = 'JSON-LD payload is not a valid JSON object.';
                return $warnings;
            }
            if (!isset($decoded['@context'])) {
                $warnings[] = 'Top-level @context is missing.';
            }
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $i => $node) {
                    if (!is_array($node)) {
                        $warnings[] = '@graph node #' . (int) $i . ' is not an object.';
                        continue;
                    }
                    if (!isset($node['@type'])) {
                        $warnings[] = '@graph node #' . (int) $i . ' is missing @type.';
                    }
                }
            } elseif (!isset($decoded['@type'])) {
                $warnings[] = 'Top-level @type is missing.';
            }
        } catch (\Throwable $e) {
            $warnings[] = 'JSON-LD validation threw: ' . $e->getMessage();
        }
        return $warnings;
    }

    /**
     * @return array<string, string>
     */
    private function getOpenGraphTags(): array
    {
        try {
            return $this->openGraphResolver->resolve();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function getTwitterTags(): array
    {
        try {
            return $this->twitterCardResolver->resolve();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Sniff headers that would be sent on the current response. Because the
     * plugin runs BEFORE the response is flushed to the client, the headers
     * array is already populated by upstream plugins (XRobotsTag, Canonical,
     * LastModified) at that point.
     *
     * @return array<string, string>
     */
    private function getResponseHeaders(): array
    {
        $interesting = [
            'Content-Type',
            'Content-Encoding',
            'Cache-Control',
            'Pragma',
            'Last-Modified',
            'ETag',
            'X-Robots-Tag',
            'Link',
        ];

        $out = [];
        foreach ($interesting as $name) {
            $value = $this->readHeader($name);
            if ($value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    private function readHeader(string $name): string
    {
        try {
            // Try the request's headers first (present on MAGE_MODE=developer
            // with rewrite capture).
            $fromServer = $this->request->getServer('HTTP_' . strtoupper(str_replace('-', '_', $name)));
            if (is_string($fromServer) && $fromServer !== '') {
                return $fromServer;
            }
        } catch (\Throwable) {
        }

        // headers_list() is the authoritative source because every plugin in
        // the chain writes directly to PHP's SAPI headers.
        if (function_exists('headers_list')) {
            foreach (headers_list() as $line) {
                if (stripos($line, $name . ':') === 0) {
                    return trim(substr($line, strlen($name) + 1));
                }
            }
        }
        return '';
    }

    /**
     * @return list<string>
     */
    private function getCookieNames(): array
    {
        try {
            if (!isset($_COOKIE) || !is_array($_COOKIE)) {
                return [];
            }
            $names = array_keys($_COOKIE);
            sort($names);
            return array_map('strval', $names);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Load a SEO score row from panth_seo_score if one exists.
     *
     * @return array{score: int, grade: string, breakdown: array<int,mixed>, issues: list<string>}|null
     */
    private function getSeoScore(string $entityType, int $entityId, int $storeId): ?array
    {
        if ($entityType === '' || $entityType === 'unknown' || $entityId <= 0) {
            return null;
        }
        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_score');
            $select = $conn->select()
                ->from($table)
                ->where('store_id = ?', $storeId)
                ->where('entity_type = ?', $entityType)
                ->where('entity_id = ?', $entityId)
                ->limit(1);
            $row = $conn->fetchRow($select);
            if (!$row || !isset($row['score'])) {
                return null;
            }
            $breakdown = [];
            $issues = [];
            if (!empty($row['breakdown'])) {
                $d = json_decode((string) $row['breakdown'], true);
                if (is_array($d)) {
                    $breakdown = $d;
                }
            }
            if (!empty($row['issues'])) {
                $d = json_decode((string) $row['issues'], true);
                if (is_array($d)) {
                    foreach ($d as $it) {
                        $issues[] = is_string($it) ? $it : (string) json_encode($it);
                    }
                }
            }
            return [
                'score'     => (int) $row['score'],
                'grade'     => (string) ($row['grade'] ?? 'F'),
                'breakdown' => $breakdown,
                'issues'    => $issues,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function extractTypes(array $data): array
    {
        $types = [];
        if (isset($data['@type'])) {
            $t = $data['@type'];
            if (is_array($t)) {
                foreach ($t as $v) {
                    $types[] = (string) $v;
                }
            } else {
                $types[] = (string) $t;
            }
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $types = array_merge($types, $this->extractTypes($node));
                }
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function detectEntity(): array
    {
        $product = $this->registry->registry('current_product');
        if ($product !== null && $product->getId()) {
            return [MetaResolverInterface::ENTITY_PRODUCT, (int) $product->getId()];
        }
        $category = $this->registry->registry('current_category');
        if ($category !== null && $category->getId()) {
            return [MetaResolverInterface::ENTITY_CATEGORY, (int) $category->getId()];
        }
        $cmsPage = $this->registry->registry('cms_page');
        if ($cmsPage !== null && $cmsPage->getId()) {
            return [MetaResolverInterface::ENTITY_CMS, (int) $cmsPage->getId()];
        }
        return [null, 0];
    }

    /**
     * Best-effort detection of the non-entity route (home, search, 404, ...).
     */
    private function detectRouteType(): string
    {
        try {
            $full = $this->getFullActionName();
        } catch (\Throwable) {
            return 'unknown';
        }
        return match (true) {
            $full === 'cms_index_index'   => 'home',
            $full === 'cms_noroute_index' => '404',
            str_starts_with($full, 'catalogsearch_') => 'search',
            str_starts_with($full, 'catalog_product_') => 'product',
            str_starts_with($full, 'catalog_category_') => 'category',
            str_starts_with($full, 'cms_page_')  => 'cms',
            str_starts_with($full, 'checkout_')  => 'checkout',
            str_starts_with($full, 'customer_')  => 'customer',
            default => $full !== '' ? $full : 'unknown',
        };
    }

    private function getFullActionName(): string
    {
        try {
            if (method_exists($this->request, 'getFullActionName')) {
                return (string) $this->request->getFullActionName();
            }
        } catch (\Throwable) {
        }
        return '';
    }

    private function getThemeName(): string
    {
        try {
            $theme = $this->design->getDesignTheme();
            if ($theme !== null) {
                $code = (string) $theme->getCode();
                if ($code === '') {
                    $code = (string) $theme->getThemePath();
                }
                return $code;
            }
        } catch (\Throwable) {
        }
        return '';
    }

    private function getCurrentUrl(): string
    {
        try {
            if (method_exists($this->request, 'getUriString')) {
                return (string) $this->request->getUriString();
            }
        } catch (\Throwable) {
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($host === '') {
            return '';
        }
        return $scheme . '://' . $host . $uri;
    }

    private function getClientIp(): string
    {
        if ($this->request instanceof \Magento\Framework\HTTP\PhpEnvironment\Request) {
            return (string) $this->request->getClientIp();
        }

        $serverParams = $this->request->getServer();
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
            $value = $serverParams->get($header);
            if ($value !== null && $value !== '') {
                $ip = trim(explode(',', (string) $value)[0]);
                if ($ip !== '') {
                    return $ip;
                }
            }
        }
        return '';
    }
}
