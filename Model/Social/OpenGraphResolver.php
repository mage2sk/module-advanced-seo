<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Social;

use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\Data\ResolvedMetaInterface;

/**
 * Resolves Open Graph meta-tag data from the current page context.
 */
class OpenGraphResolver
{
    public const XML_DEFAULT_OG_IMAGE = 'panth_seo/social/default_og_image';

    public function __construct(
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CanonicalResolverInterface $canonicalResolver,
        private readonly MetaResolverInterface $metaResolver,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ?PageConfig $pageConfig = null
    ) {
    }

    /**
     * Resolve Open Graph tags for the current page.
     *
     * @return array<string, string> Keyed by OG property name (e.g. "og:title").
     */
    public function resolve(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
        } catch (\Throwable) {
            return [];
        }

        [$entityType, $entityId] = $this->detectEntity();

        // Load the resolved meta for this entity so we can fall back to its
        // title/description when the entity itself has no own value. This
        // guarantees og:title / og:description are populated using the same
        // template-rendered text the page <title> and meta description use.
        $resolvedMeta = $this->loadResolvedMeta($entityType, $entityId, $storeId);

        $tags = [];
        $tags['og:type'] = $this->resolveType($entityType);
        $tags['og:title'] = $this->resolveTitle($entityType, $resolvedMeta);
        $tags['og:description'] = $this->resolveDescription($entityType, $resolvedMeta);
        $tags['og:image'] = $this->resolveImage($entityType);
        $tags['og:url'] = $this->resolveUrl($entityType, $entityId, $storeId);
        $tags['og:site_name'] = $this->resolveSiteName();

        return array_filter($tags, static fn (string $v): bool => $v !== '');
    }

    /**
     * Load the resolved meta DTO for the current entity, or null if none.
     */
    private function loadResolvedMeta(?string $entityType, int $entityId, int $storeId): ?ResolvedMetaInterface
    {
        if ($entityType === null || $entityId === 0) {
            return null;
        }
        try {
            return $this->metaResolver->resolve($entityType, $entityId, $storeId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map entity type to OG type value.
     */
    private function resolveType(?string $entityType): string
    {
        return match ($entityType) {
            MetaResolverInterface::ENTITY_PRODUCT => 'product',
            MetaResolverInterface::ENTITY_CMS => 'article',
            default => 'website',
        };
    }

    /**
     * Resolve title from current entity or page config.
     *
     * Falls back through: explicit entity meta_title -> resolved meta from
     * MetaResolver (template-rendered) -> entity name -> store name.
     */
    private function resolveTitle(?string $entityType, ?ResolvedMetaInterface $resolvedMeta): string
    {
        $product = $this->registry->registry('current_product');
        if ($entityType === MetaResolverInterface::ENTITY_PRODUCT && $product instanceof Product) {
            $own = (string) $product->getMetaTitle();
            if ($own !== '') {
                return $own;
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaTitle() !== '') {
                return (string) $resolvedMeta->getMetaTitle();
            }
            return (string) $product->getName();
        }

        $category = $this->registry->registry('current_category');
        if ($entityType === MetaResolverInterface::ENTITY_CATEGORY && $category !== null) {
            $own = (string) $category->getMetaTitle();
            if ($own !== '') {
                return $own;
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaTitle() !== '') {
                return (string) $resolvedMeta->getMetaTitle();
            }
            return (string) $category->getName();
        }

        $cmsPage = $this->registry->registry('cms_page');
        if ($entityType === MetaResolverInterface::ENTITY_CMS && $cmsPage instanceof CmsPage) {
            $own = (string) $cmsPage->getMetaTitle();
            if ($own !== '') {
                return $own;
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaTitle() !== '') {
                return (string) $resolvedMeta->getMetaTitle();
            }
            return (string) $cmsPage->getTitle();
        }

        // Fallback for pages without a catalog/CMS entity (e.g. custom
        // controllers such as Panth_Testimonials, Panth_Faq, static routes):
        // prefer the page title set on the PageConfig by the controller so
        // the og:title reflects the actual page rather than the store name.
        // Only fall back to the store name when no controller-set title
        // exists. This keeps AdvancedSEO the single source of og:* emission
        // while still surfacing route-specific titles.
        $pageTitle = $this->getPageConfigTitle();
        if ($pageTitle !== '') {
            return $pageTitle;
        }
        try {
            return (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Read the current page title from PageConfig, if a controller/plugin
     * has already set one. Returns '' when PageConfig is unavailable or
     * untitled so callers can fall back.
     */
    private function getPageConfigTitle(): string
    {
        if ($this->pageConfig === null) {
            return '';
        }
        try {
            $title = (string) $this->pageConfig->getTitle()->get();
        } catch (\Throwable) {
            return '';
        }
        return trim($title);
    }

    /**
     * Read the current meta description from PageConfig, if a controller/
     * plugin has already set one. Returns '' when unavailable.
     */
    private function getPageConfigDescription(): string
    {
        if ($this->pageConfig === null) {
            return '';
        }
        try {
            $desc = (string) $this->pageConfig->getDescription();
        } catch (\Throwable) {
            return '';
        }
        return trim($desc);
    }

    /**
     * Resolve meta description from current entity.
     *
     * Falls back through: explicit entity meta_description -> resolved meta
     * from MetaResolver (template-rendered with current store context) ->
     * entity description -> store default description.
     */
    private function resolveDescription(?string $entityType, ?ResolvedMetaInterface $resolvedMeta): string
    {
        $product = $this->registry->registry('current_product');
        if ($entityType === MetaResolverInterface::ENTITY_PRODUCT && $product instanceof Product) {
            $own = (string) $product->getMetaDescription();
            if ($own !== '') {
                return $this->truncate($own, 200);
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaDescription() !== '') {
                return $this->truncate((string) $resolvedMeta->getMetaDescription(), 200);
            }
            return $this->truncate((string) $product->getShortDescription(), 200);
        }

        $category = $this->registry->registry('current_category');
        if ($entityType === MetaResolverInterface::ENTITY_CATEGORY && $category !== null) {
            $own = (string) $category->getMetaDescription();
            if ($own !== '') {
                return $this->truncate($own, 200);
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaDescription() !== '') {
                return $this->truncate((string) $resolvedMeta->getMetaDescription(), 200);
            }
            return $this->truncate((string) $category->getDescription(), 200);
        }

        $cmsPage = $this->registry->registry('cms_page');
        if ($cmsPage instanceof CmsPage) {
            $own = (string) $cmsPage->getMetaDescription();
            if ($own !== '') {
                return $this->truncate($own, 200);
            }
            if ($resolvedMeta !== null && (string) $resolvedMeta->getMetaDescription() !== '') {
                return $this->truncate((string) $resolvedMeta->getMetaDescription(), 200);
            }
            return '';
        }

        // Prefer a description the controller has already set on the page
        // (Panth_Testimonials, Panth_Faq, custom landing routes) so the
        // og:description mirrors what the visible <meta name="description">
        // shows. Only fall back to the store default when nothing is set.
        $pageDesc = $this->getPageConfigDescription();
        if ($pageDesc !== '') {
            return $this->truncate($pageDesc, 200);
        }
        try {
            $store = $this->storeManager->getStore();
            $defaultDesc = $store->getConfig('design/head/default_description');
            return $defaultDesc ? $this->truncate((string) $defaultDesc, 200) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve image URL with progressive fallbacks:
     *   product image -> category image -> first product in category -> store logo
     *   -> Magento default product placeholder -> empty.
     */
    private function resolveImage(?string $entityType): string
    {
        try {
            $product = $this->registry->registry('current_product');
            if ($entityType === MetaResolverInterface::ENTITY_PRODUCT && $product instanceof Product) {
                $image = $product->getImage();
                if ($image && $image !== 'no_selection') {
                    $store = $this->storeManager->getStore();
                    $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
                    return $mediaUrl . '/catalog/product' . $image;
                }
            }

            $category = $this->registry->registry('current_category');
            if ($entityType === MetaResolverInterface::ENTITY_CATEGORY && $category !== null) {
                // 1. Category's own image
                $categoryImage = $category->getImageUrl();
                if ($categoryImage) {
                    return (string) $categoryImage;
                }
                // 2. First product image from this category
                $firstProductImage = $this->getFirstProductImageInCategory((int) $category->getId());
                if ($firstProductImage !== '') {
                    return $firstProductImage;
                }
            }

            // 3. Admin-configured default OG image (panth_seo/social/default_og_image)
            $defaultOgImage = $this->getDefaultOgImageUrl();
            if ($defaultOgImage !== '') {
                return $defaultOgImage;
            }

            // 4. Store logo
            $logo = $this->getStoreLogoUrl();
            if ($logo !== '') {
                return $logo;
            }

            // 5. Magento default product placeholder image
            return $this->getPlaceholderImageUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get the URL of the first product image in the given category.
     */
    private function getFirstProductImageInCategory(int $categoryId): string
    {
        try {
            $category = $this->registry->registry('current_category');
            if ($category === null || (int) $category->getId() !== $categoryId) {
                return '';
            }
            $productCollection = $category->getProductCollection();
            if ($productCollection === null) {
                return '';
            }
            $productCollection->addAttributeToSelect('image')
                ->addFieldToFilter('image', ['notnull' => true])
                ->addFieldToFilter('image', ['neq' => 'no_selection'])
                ->setPageSize(1)
                ->setCurPage(1);
            foreach ($productCollection as $product) {
                $image = $product->getImage();
                if ($image && $image !== 'no_selection') {
                    $store = $this->storeManager->getStore();
                    $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
                    return $mediaUrl . '/catalog/product' . $image;
                }
            }
        } catch (\Throwable) {
            // intentionally empty
        }
        return '';
    }

    /**
     * Get the admin-configured default OG image URL (panth_seo/social/default_og_image).
     *
     * The stored value is a media-relative path saved by the Image backend under
     * `panth_seo/og/`. We reject any value containing path-traversal sequences
     * and return an absolute media URL when safe.
     */
    private function getDefaultOgImageUrl(): string
    {
        try {
            $value = $this->scopeConfig->getValue(self::XML_DEFAULT_OG_IMAGE, ScopeInterface::SCOPE_STORE);
            if ($value === null || $value === '') {
                return '';
            }
            $relative = (string) $value;
            // Defensive: reject path traversal, backslashes, null bytes, absolute paths.
            if (str_contains($relative, '..')
                || str_contains($relative, "\0")
                || str_contains($relative, '\\')
                || str_starts_with($relative, '/')) {
                return '';
            }
            // Accept absolute URLs already persisted (rare) only if http(s).
            if (preg_match('#^https?://#i', $relative) === 1) {
                return $relative;
            }
            $store = $this->storeManager->getStore();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
            return $mediaUrl . '/' . ltrim($relative, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get the Magento default product placeholder image URL as the last resort.
     */
    private function getPlaceholderImageUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
            $placeholder = $store->getConfig('catalog/placeholder/image_placeholder');
            if ($placeholder) {
                return $mediaUrl . '/catalog/product/placeholder/' . ltrim((string) $placeholder, '/');
            }
            // Magento always ships a default placeholder at this path
            return $mediaUrl . '/catalog/product/placeholder/default/image.jpg';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve canonical URL for the current entity.
     */
    private function resolveUrl(?string $entityType, int $entityId, int $storeId): string
    {
        if ($entityType === null || $entityId === 0) {
            // Fallback (e.g. CMS home, routes without a detected entity): normalize the
            // current URL through the canonical resolver so the `___store` query param
            // and other noise params are stripped — matching product/category behaviour.
            try {
                $currentUrl = (string) $this->storeManager->getStore()->getCurrentUrl(false);
                return $this->canonicalResolver->normalize($currentUrl, $storeId);
            } catch (\Throwable) {
                return '';
            }
        }

        try {
            return $this->canonicalResolver->getCanonicalUrl($entityType, $entityId, $storeId);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve store name from configuration.
     */
    private function resolveSiteName(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get the store logo URL from theme configuration.
     */
    private function getStoreLogoUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $logoSrc = $store->getConfig('design/header/logo_src');
            if ($logoSrc) {
                $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
                return $mediaUrl . '/logo/' . ltrim((string) $logoSrc, '/');
            }
        } catch (\Throwable) {
            // intentionally empty
        }

        return '';
    }

    /**
     * Detect the current entity type and ID from the registry.
     *
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
     * Truncate a string to max length, stripping HTML tags first.
     */
    private function truncate(string $text, int $maxLength): string
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
