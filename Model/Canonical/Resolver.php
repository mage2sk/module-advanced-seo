<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\Config\Source\LayeredNavCanonical;
use Panth\AdvancedSEO\Model\Config\Source\ProductCanonicalType;
use Panth\AdvancedSEO\Model\Config\Source\TrailingSlashHomepage;
use Psr\Log\LoggerInterface;

class Resolver implements CanonicalResolverInterface
{
    private const XML_STRIP_PARAMS = 'panth_seo/canonical/strip_params';
    private const PAGINATION_PARAM = 'p';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly EavConfig $eavConfig,
        private readonly CustomCanonicalRepository $customCanonicalRepository,
        private readonly ?ConfigurableResource $configurableResource = null,
        private readonly ?CategoryUrlPathGenerator $categoryUrlPathGenerator = null,
        private readonly ?SeoDebugLogger $seoDebugLogger = null
    ) {
    }

    private function debug(string $message, array $context = []): void
    {
        if ($this->seoDebugLogger === null) {
            return;
        }
        if (!$this->config->isDebug()) {
            return;
        }
        $this->seoDebugLogger->debug($message, $context);
    }

    public function getCanonicalUrl(
        string $entityType,
        int $entityId,
        int $storeId,
        array $params = []
    ): string {
        $robots = $params['robots'] ?? '';
        if ($robots !== '' && $this->config->isCanonicalDisabledForNoindex($storeId)
            && stripos($robots, 'noindex') !== false
        ) {
            $this->debug('panth_seo: canonical.suppressed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'decision' => 'noindex_page',
                'robots' => $robots,
            ]);

            return '';
        }

        $currentPath = $params['current_path'] ?? '';
        if ($currentPath !== '' && $this->isIgnoredPage($currentPath, $storeId)) {
            $this->debug('panth_seo: canonical.suppressed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'decision' => 'ignored_path',
                'current_path' => $currentPath,
            ]);

            return '';
        }

        try {
            $customUrl = $this->customCanonicalRepository->find($entityType, $entityId, $storeId);
            if ($customUrl !== null && $customUrl !== '') {
                return $this->normalize($customUrl, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Panth SEO custom canonical lookup failed: ' . $e->getMessage());
        }

        try {
            $url = match ($entityType) {
                MetaResolverInterface::ENTITY_PRODUCT  => $this->productCanonical($entityId, $storeId),
                MetaResolverInterface::ENTITY_CATEGORY => $this->categoryCanonical($entityId, $storeId),
                MetaResolverInterface::ENTITY_CMS      => $this->cmsCanonical($entityId, $storeId),
                default => '',
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO canonical build failed', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);
            $this->debug('panth_seo: canonical.suppressed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'decision' => 'build_failed',
                'exception' => $e->getMessage(),
                'class' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return '';
        }

        if ($url === '') {
            $this->debug('panth_seo: canonical.suppressed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'store_id' => $storeId,
                'decision' => 'no_url_built',
            ]);

            return '';
        }

        $crossDomainStore = $this->config->getCrossDomainCanonicalStore($storeId);
        if ($crossDomainStore > 0 && $crossDomainStore !== $storeId) {
            try {
                $crossUrl = match ($entityType) {
                    MetaResolverInterface::ENTITY_PRODUCT  => $this->productCanonical($entityId, $crossDomainStore),
                    MetaResolverInterface::ENTITY_CATEGORY => $this->categoryCanonical($entityId, $crossDomainStore),
                    MetaResolverInterface::ENTITY_CMS      => $this->cmsCanonical($entityId, $crossDomainStore),
                    default => '',
                };
                if ($crossUrl !== '') {
                    $url = $crossUrl;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Panth SEO cross-domain canonical failed: ' . $e->getMessage());
            }
        }

        $page = isset($params[self::PAGINATION_PARAM]) ? (int) $params[self::PAGINATION_PARAM] : 0;
        if ($page > 1 && !$this->config->canonicalPaginatedToFirst($storeId)) {
            $url = $this->appendQuery($url, [self::PAGINATION_PARAM => (string) $page]);
        }

        $activeFilterAttributes = $params['active_filter_attributes'] ?? [];
        if ($activeFilterAttributes !== [] && $entityType === MetaResolverInterface::ENTITY_CATEGORY) {
            $attributeOverride = $this->resolveLayeredNavCanonicalOverride($activeFilterAttributes);

            if ($attributeOverride !== null && $attributeOverride !== LayeredNavCanonical::USE_GLOBAL) {
                if ($attributeOverride === LayeredNavCanonical::NOINDEX) {
                    $this->debug('panth_seo: canonical.resolved', [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'store_id' => $storeId,
                        'decision' => 'layered_nav_noindex',
                        'url' => '',
                    ]);
                    return '';
                }
                if ($attributeOverride === LayeredNavCanonical::CATEGORY) {
                    return $this->normalize($url, $storeId);
                }
                if ($attributeOverride === LayeredNavCanonical::FILTERED) {
                    $filterParams = $params['active_filter_params'] ?? [];
                    if ($filterParams !== []) {
                        $url = $this->appendQuery($url, $filterParams);
                    }
                    return $this->normalize($url, $storeId);
                }
            }
        }

        $normalized = $this->normalize($url, $storeId);
        $this->debug('panth_seo: canonical.resolved', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'decision' => 'default',
            'url' => $normalized,
        ]);
        return $normalized;
    }

    public function normalize(string $url, int $storeId): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if ($this->isBaseHttps($storeId)) {
            $scheme = 'https';
        }

        if ($this->config->canonicalLowercaseHost($storeId)) {
            $parts['host'] = strtolower($parts['host']);
        }

        $path = $parts['path'] ?? '/';
        $path = $this->applyTrailingSlashPolicy($path, $storeId);

        $queryString = $parts['query'] ?? '';
        $query = [];
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }
        if ($this->config->stripCanonicalQuery($storeId)) {
            $query = [];
        } else {
            foreach ($this->stripList($storeId) as $strip) {
                unset($query[$strip]);
            }
        }

        $rebuilt = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $path;
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }
        return $rebuilt;
    }

    private function productCanonical(int $productId, int $storeId): string
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
        } catch (NoSuchEntityException) {
            return '';
        }

        if ($this->config->isAssociatedProductCanonical($storeId) && $this->configurableResource !== null) {
            $typeId = $product->getTypeId();
            if ($typeId === 'simple' || $typeId === 'virtual') {
                $parentIds = $this->configurableResource->getParentIdsByChild($productId);
                if (!empty($parentIds)) {
                    try {
                        $parent = $this->productRepository->getById((int) reset($parentIds), false, $storeId);
                        $url = $parent->getUrlModel()->getUrl($parent, ['_ignore_category' => true, '_scope' => $storeId]);
                        return $this->rehostToStore((string) $url, $storeId);
                    } catch (NoSuchEntityException) {
                    }
                }
            }
        }

        $canonicalType = $this->config->getProductCanonicalType($storeId);

        if ($canonicalType === ProductCanonicalType::WITHOUT_CATEGORY) {
            $url = $product->getUrlModel()->getUrl($product, ['_ignore_category' => true, '_scope' => $storeId]);
            return $this->rehostToStore((string) $url, $storeId);
        }

        $category = $this->resolveCanonicalCategory($product, $storeId, $canonicalType);

        if ($category === null) {
            $url = $product->getUrlModel()->getUrl($product, ['_ignore_category' => true, '_scope' => $storeId]);
            return $this->rehostToStore((string) $url, $storeId);
        }

        $product->setData('category_id', $category->getId());
        $url = $product->getUrlModel()->getUrl($product, ['_ignore_category' => false, '_scope' => $storeId]);

        return $this->rehostToStore((string) $url, $storeId);
    }

    private function rehostToStore(string $url, int $storeId): string
    {
        if ($url === '') {
            return '';
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return $url;
        }
        $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');
        if ($baseUrl === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['path'])) {
            return $url;
        }

        $path = $parts['path'];
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $baseUrl . '/' . ltrim($path, '/') . $query . $fragment;
    }

    private function resolveCanonicalCategory(
        ProductInterface $product,
        int $storeId,
        string $canonicalType
    ): ?CategoryInterface {
        $categoryIds = $product->getCategoryIds();

        if (empty($categoryIds)) {
            return null;
        }

        $bestCategory = null;
        $bestLevel    = null;

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }

            $level = (int) $category->getLevel();
            if ($level < 2) {
                continue;
            }

            if (!$category->getIsActive()) {
                continue;
            }

            if ($bestLevel === null) {
                $bestCategory = $category;
                $bestLevel    = $level;
                continue;
            }

            if ($canonicalType === ProductCanonicalType::SHORTEST && $level < $bestLevel) {
                $bestCategory = $category;
                $bestLevel    = $level;
            } elseif ($canonicalType === ProductCanonicalType::LONGEST && $level > $bestLevel) {
                $bestCategory = $category;
                $bestLevel    = $level;
            }
        }

        return $bestCategory;
    }

    private function categoryCanonical(int $categoryId, int $storeId): string
    {
        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
        } catch (NoSuchEntityException) {
            return '';
        }

        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return (string) $category->getUrl();
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');

        if ($this->categoryUrlPathGenerator !== null) {
            try {
                $path = (string) $this->categoryUrlPathGenerator->getUrlPathWithSuffix($category, $storeId);
                if ($path !== '') {
                    return $baseUrl . '/' . ltrim($path, '/');
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Panth SEO category canonical path lookup failed: ' . $e->getMessage());
            }
        }

        $rawUrl = (string) $category->getUrl();
        if ($rawUrl === '') {
            return '';
        }
        $parts = parse_url($rawUrl);
        if ($parts === false || !isset($parts['path'])) {
            return $rawUrl;
        }
        $path = $parts['path'];
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $baseUrl . '/' . ltrim($path, '/') . $query;
    }

    private function cmsCanonical(int $pageId, int $storeId): string
    {
        try {
            $page = $this->pageRepository->getById($pageId);
        } catch (NoSuchEntityException) {
            return '';
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return '';
        }
        $identifier = (string) $page->getIdentifier();
        return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/' . ltrim($identifier, '/');
    }

    private function isIgnoredPage(string $currentPath, int $storeId): bool
    {
        $raw = $this->config->getCanonicalIgnorePages($storeId);
        if ($raw === '') {
            return false;
        }

        $tokens = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $patterns = array_filter(array_map('trim', $tokens), static fn ($v) => $v !== '');

        $pathOnly = parse_url($currentPath, PHP_URL_PATH) ?? $currentPath;
        $normalized = '/' . ltrim($pathOnly, '/');
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        foreach ($patterns as $pattern) {
            if (preg_match('#^[A-Za-z0-9_./*\-]{1,255}$#', $pattern) !== 1) {
                continue;
            }
            $pattern = '/' . ltrim($pattern, '/');
            if ($normalized === $pattern) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $normalized)) {
                return true;
            }
        }
        return false;
    }

    private function resolveLayeredNavCanonicalOverride(array $attributeCodes): ?string
    {
        foreach ($attributeCodes as $code) {
            try {
                $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            } catch (\Throwable) {
                continue;
            }

            $value = (string) $attribute->getData('layered_navigation_canonical');
            if ($value !== '' && $value !== LayeredNavCanonical::USE_GLOBAL) {
                return $value;
            }
        }

        return null;
    }

    private function appendQuery(string $url, array $extra): string
    {
        if ($extra === []) {
            return $url;
        }
        $glue = str_contains($url, '?') ? '&' : '?';
        return $url . $glue . http_build_query($extra);
    }

    private function applyTrailingSlashPolicy(string $path, int $storeId): string
    {
        if ($this->isHomepagePath($path)) {
            $homepagePolicy = $this->config->getTrailingSlashHomepage($storeId);

            if ($homepagePolicy === TrailingSlashHomepage::ADD) {
                return str_ends_with($path, '/') ? $path : $path . '/';
            }
            if ($homepagePolicy === TrailingSlashHomepage::REMOVE) {
                return ($path === '/' || $path === '') ? '' : rtrim($path, '/');
            }

            return $path;
        }

        if ($this->config->canonicalRemoveTrailingSlash($storeId) && $path !== '/' && str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $path;
    }

    private function isHomepagePath(string $path): bool
    {
        $normalized = rtrim($path, '/');
        return $normalized === '' || $normalized === '/index.php';
    }

    private function isBaseHttps(int $storeId): bool
    {
        try {
            $base = (string) $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
            return str_starts_with($base, 'https://');
        } catch (\Throwable) {
            return false;
        }
    }

    private function stripList(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_STRIP_PARAMS, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === '') {
            return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'];
        }

        $tokens = preg_split('/[\r\n,]+/', $raw) ?: [];
        $parts  = array_map('trim', $tokens);

        $safe = array_filter($parts, static fn ($v) =>
            $v !== '' && preg_match('/^[A-Za-z0-9_.\-\[\]]{1,64}$/', $v) === 1);
        return array_values($safe);
    }
}
