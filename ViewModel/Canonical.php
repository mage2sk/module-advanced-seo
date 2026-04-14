<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class Canonical implements ArgumentInterface
{
    public function __construct(
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $config,
        private readonly PageConfig $pageConfig
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled() && $this->config->isCanonicalEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getCanonicalUrl(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        [$type, $id] = $this->detectEntity();
        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $page    = (int) $this->request->getParam('p', 0);

            // Gather request-scoped context that the resolver uses for
            // `canonical_ignore_pages` and `disable_canonical_for_noindex`.
            $requestUri = (string) $this->request->getRequestUri();
            $currentPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';
            $robots = '';
            try {
                $robots = (string) $this->pageConfig->getRobots();
            } catch (\Throwable) {
                $robots = '';
            }

            $params = [
                'current_path' => $currentPath,
                'robots'       => $robots,
            ];
            if ($page > 0) {
                $params['p'] = $page;
            }

            if ($type !== null) {
                return $this->canonicalResolver->getCanonicalUrl(
                    $type,
                    $id,
                    $storeId,
                    $params
                );
            }

            // Non-entity fallback: short-circuit ignore pages and noindex here
            // too so settings apply uniformly to homepage / search / custom pages.
            if ($this->config->isCanonicalDisabledForNoindex($storeId)
                && $robots !== '' && stripos($robots, 'noindex') !== false
            ) {
                return '';
            }
            if ($this->isIgnoredRequestPath($currentPath, $storeId)) {
                return '';
            }

            // Build the fallback URL, then hand it to the resolver's
            // normalizer so trailing_slash_homepage / lowercase_host /
            // strip_query / strip_params all apply consistently.
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
            $path    = $currentPath;

            if ($path === '/' || $path === '/index.php' || $path === '') {
                return $this->canonicalResolver->normalize($baseUrl . '/', $storeId);
            }

            $query = $this->buildFallbackQuery();

            return $this->canonicalResolver->normalize($baseUrl . $path . $query, $storeId);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Mirror of Resolver::isIgnoredPage so the non-entity fallback also
     * respects the `canonical_ignore_pages` setting.
     */
    private function isIgnoredRequestPath(string $currentPath, int $storeId): bool
    {
        try {
            $raw = $this->config->getCanonicalIgnorePages($storeId);
        } catch (\Throwable) {
            return false;
        }
        if ($raw === '') {
            return false;
        }
        $patterns = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []),
            static fn ($v) => $v !== ''
        );
        $normalized = '/' . ltrim($currentPath, '/');
        foreach ($patterns as $pattern) {
            $pattern = '/' . ltrim(trim($pattern), '/');
            if ($normalized === $pattern) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $normalized)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the query-string suffix for non-entity canonical fallbacks.
     *
     * Currently only the catalog search result controller preserves a
     * whitelisted parameter (`q`) so that `/catalogsearch/result/?q=jacket`
     * and `/catalogsearch/result/?q=shoes` each emit distinct canonicals
     * instead of collapsing to the bare result URL.
     */
    private function buildFallbackQuery(): string
    {
        $fullAction = '';
        $request    = $this->request;
        if (method_exists($request, 'getFullActionName')) {
            try {
                $fullAction = (string) $request->getFullActionName();
            } catch (\Throwable) {
                $fullAction = '';
            }
        }

        // Fall back to assembling the full action name from parts when the
        // concrete request doesn't expose getFullActionName().
        if ($fullAction === ''
            && method_exists($request, 'getModuleName')
            && method_exists($request, 'getControllerName')
            && method_exists($request, 'getActionName')
        ) {
            try {
                $module     = (string) $request->getModuleName();
                $controller = (string) $request->getControllerName();
                $action     = (string) $request->getActionName();
                if ($module !== '' && $controller !== '' && $action !== '') {
                    $fullAction = $module . '_' . $controller . '_' . $action;
                }
            } catch (\Throwable) {
                $fullAction = '';
            }
        }

        if ($fullAction === 'catalogsearch_result_index') {
            $q = trim((string) $this->request->getParam('q', ''));
            if ($q !== '') {
                return '?' . http_build_query(['q' => $q]);
            }
        }

        return '';
    }

    /**
     * Check whether a canonical URL has already been registered via
     * PageConfig::addRemotePageAsset() by an earlier metadata plugin.
     */
    private function hasCanonicalInPageConfig(): bool
    {
        try {
            $assets = $this->pageConfig->getAssetCollection()->getAll();
            foreach ($assets as $asset) {
                if ($asset->getContentType() === 'canonical') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // PageConfig may not be initialized; assume no canonical present.
        }
        return false;
    }

    /**
     * @return array{0:?string,1:int}
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
}
