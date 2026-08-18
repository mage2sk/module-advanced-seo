<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
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
    private ?array $filterableAttributeCodes = null;

    public function __construct(
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $config,
        private readonly PageConfig $pageConfig,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
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

            if ($this->isNoRouteRequest() && $this->config->isNoindexNoRoute($storeId)) {
                return '';
            }
            $page    = (int) $this->request->getParam('p', 0);

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

            if ($type === MetaResolverInterface::ENTITY_CATEGORY && $this->hasActiveFilter()) {
                $absolute = rtrim((string) $store->getBaseUrl(), '/') . $currentPath;
                if (!$this->config->canonicalPaginatedToFirst($storeId) && $page > 1) {
                    $absolute .= (str_contains($absolute, '?') ? '&' : '?') . 'p=' . $page;
                }
                return $this->canonicalResolver->normalize($absolute, $storeId);
            }

            if ($type !== null) {
                return $this->canonicalResolver->getCanonicalUrl(
                    $type,
                    $id,
                    $storeId,
                    $params
                );
            }

            if ($this->config->isCanonicalDisabledForNoindex($storeId)
                && $robots !== '' && stripos($robots, 'noindex') !== false
            ) {
                return '';
            }
            if ($this->isIgnoredRequestPath($currentPath, $storeId)) {
                return '';
            }

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

    private function isNoRouteRequest(): bool
    {
        try {
            if (method_exists($this->request, 'getFullActionName')) {
                return (string) $this->request->getFullActionName() === 'cms_noroute_index';
            }
        } catch (\Throwable) {
        }
        return false;
    }

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
        }
        return false;
    }

    private function hasActiveFilter(): bool
    {
        $params = $this->request->getParams();
        foreach ($this->getFilterableAttributeCodes() as $code) {
            if (!empty($params[$code])) {
                return true;
            }
        }
        return false;
    }

    private function getFilterableAttributeCodes(): array
    {
        if ($this->filterableAttributeCodes !== null) {
            return $this->filterableAttributeCodes;
        }
        $codes = [];
        try {
            $coll = $this->attributeCollectionFactory->create();
            $coll->setEntityTypeFilter(4);
            $coll->addFieldToFilter('is_filterable', ['in' => [1, 2]]);
            foreach ($coll as $attr) {
                $codes[] = (string) $attr->getAttributeCode();
            }
        } catch (\Throwable) {
        }
        return $this->filterableAttributeCodes = $codes;
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
}
