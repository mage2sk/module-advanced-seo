<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Registry;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class SeoToolbar implements ArgumentInterface
{
    public function __construct(
        private readonly PageConfig $pageConfig,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly SeoConfig $config,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly DesignInterface $design,
        private readonly ResourceConnection $resource,
        private readonly AppState $appState
    ) {
    }

    public function isAllowed(): bool
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isSeoToolbarEnabled()) {
                return false;
            }

            $allowedIps = trim($this->config->getSeoToolbarAllowedIps());

            if ($allowedIps === '') {
                try {
                    return $this->appState->getMode() === AppState::MODE_DEVELOPER;
                } catch (\Throwable) {
                    return false;
                }
            }

            $clientIp = $this->getClientIp();

            $entries = array_filter(
                array_map('trim', explode(',', $allowedIps)),
                static fn (string $e): bool => $e !== ''
            );

            if (in_array('*', $entries, true)) {
                return true;
            }

            if ($clientIp === '') {
                return false;
            }

            foreach ($entries as $entry) {
                if ($entry === '*') {
                    continue;
                }
                if ($this->ipMatches($clientIp, $entry)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

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

        if (strlen($subnetPacked) !== strlen($clientPacked)) {
            return false;
        }

        $bits = strlen($subnetPacked) * 8;
        if ($prefixLen < 0) {
            return false;
        }
        if ($prefixLen > $bits) {
            $prefixLen = $bits;
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

            'meta' => [
                'title'            => $title,
                'title_length'     => mb_strlen($title),
                'description'      => $description,
                'description_length' => mb_strlen($description),
                'keywords'         => $this->getMetaKeywords(),
                'canonical'        => $this->getCanonicalUrl(),
                'base_url'         => $baseUrl,
            ],

            'og_tags' => [],

            'twitter_tags' => [],

            'hreflang' => [],

            'jsonld' => [],

            'jsonld_warnings' => [],

            'headers' => $this->getResponseHeaders(),

            'cookies' => $this->getCookieNames(),

            'score' => $this->getSeoScore($detectedType, $entityId, $storeId),
        ];
    }

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
            $fromServer = $this->request->getServer('HTTP_' . strtoupper(str_replace('-', '_', $name)));
            if (is_string($fromServer) && $fromServer !== '') {
                return $fromServer;
            }
        } catch (\Throwable) {
        }

        if (function_exists('headers_list')) {
            foreach (headers_list() as $line) {
                if (stripos($line, $name . ':') === 0) {
                    return trim(substr($line, strlen($name) + 1));
                }
            }
        }
        return '';
    }

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
