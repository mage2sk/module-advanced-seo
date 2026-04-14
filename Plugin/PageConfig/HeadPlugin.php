<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\PageConfig;

use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Robots\MetaResolver as RobotsMetaResolver;
use Panth\AdvancedSEO\Service\NoindexPathMatcher;

/**
 * Generic safety-net: when publicBuild() assembles the <head>, re-assert the
 * resolved meta so nothing set later in the layout tree can shadow it. Only
 * runs when a catalog or CMS entity is in the registry.
 */
class HeadPlugin
{
    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly SeoConfig $seoConfig,
        private readonly ?RobotsMetaResolver $robotsMetaResolver = null,
        private readonly ?NoindexPathMatcher $noindexPathMatcher = null
    ) {
    }

    public function beforePublicBuild(PageConfig $subject): array
    {
        try {
            if (!$this->seoConfig->isEnabled()) {
                return [];
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            // Private / customer-scoped paths (checkout, wishlist, account,
            // etc.) must always carry noindex,nofollow on the meta tag so the
            // HTML mirrors what the X-Robots-Tag HTTP header emits. This has
            // to run BEFORE the entity branch so nothing later can shadow it.
            if ($this->noindexPathMatcher !== null) {
                $requestUri = (string) $this->request->getRequestUri();
                if ($this->noindexPathMatcher->isNoindexPath($requestUri, $storeId)) {
                    $subject->setRobots('noindex,nofollow');
                    return [];
                }
            }

            [$type, $id] = $this->detectEntity();
            // No catalog/CMS entity in registry (e.g. generic storefront
            // action). We still want to project the admin-configured default
            // meta robots + advanced directives onto the page, so use an
            // empty DataObject as a stand-in so the robots branch below still
            // runs via the SeoConfig fallback.
            $resolved = $type === null
                ? new \Magento\Framework\DataObject()
                : $this->metaResolver->resolve($type, $id, $storeId);

            if ($resolved->getMetaTitle()) {
                $title = (string) $resolved->getMetaTitle();
                // When "Append Store Name to Title" is enabled, append
                // " - {Store Name}" if it is not already present. Templates
                // that already interpolate `{{store.name}}` are left alone so
                // we never double-suffix the store name.
                if ($this->seoConfig->appendStoreName($storeId)) {
                    try {
                        // Use the store view name (e.g. "Default Store View"),
                        // not the group/frontend name (e.g. "Main Website
                        // Store"), so the value matches what templates render
                        // via `{{store.name}}` and the duplicate guard works.
                        $storeName = (string) $this->storeManager->getStore($storeId)->getName();
                    } catch (\Throwable) {
                        $storeName = '';
                    }
                    if ($storeName !== '' && !str_contains($title, $storeName)) {
                        $maxLen = $this->seoConfig->getTitleMaxLength($storeId);
                        $suffix = ' - ' . $storeName;
                        $combined = $title . $suffix;
                        if ($maxLen > 0
                            && function_exists('mb_strlen')
                            && mb_strlen($combined, 'UTF-8') > $maxLen
                        ) {
                            $budget = $maxLen - mb_strlen($suffix, 'UTF-8');
                            if ($budget > 1) {
                                $title = rtrim(mb_substr($title, 0, $budget - 1, 'UTF-8'))
                                    . '…'
                                    . $suffix;
                            } else {
                                $title = mb_substr($combined, 0, $maxLen, 'UTF-8');
                            }
                        } else {
                            $title = $combined;
                        }
                    }
                }
                $subject->getTitle()->set($title);
            }
            if ($resolved->getMetaDescription()) {
                $subject->setDescription($resolved->getMetaDescription());
            }
            if ($resolved->getMetaKeywords()) {
                $subject->setKeywords($resolved->getMetaKeywords());
            }
            // Always set robots — even when the entity-specific resolver has
            // no value — so the admin-configured default (`panth_seo/robots/
            // default_meta`) and Google directives (max-image-preview,
            // max-snippet) reach the HTML on every page. When we have an
            // entity and the Robots\MetaResolver is wired in, use it directly
            // so URL-pattern rules (layered nav, catalog search) win over a
            // stored per-entity `index,follow` baked into panth_seo_resolved.
            if ($this->robotsMetaResolver !== null && $type !== null) {
                $robots = $this->robotsMetaResolver->resolve($type, $id, $storeId);
            } else {
                $robots = (string) $resolved->getRobots();
                if ($robots === '') {
                    $robots = $this->seoConfig->getDefaultMetaRobots($storeId);
                }
            }
            $robots = $this->sanitizeRobots($robots);
            if ($this->robotsMetaResolver !== null) {
                $robots = $this->robotsMetaResolver->appendAdvancedDirectives($robots, $storeId);
            }
            $subject->setRobots($robots);
        } catch (\Throwable) {
            // best-effort
        }
        return [];
    }

    /**
     * Strip anything that is not a valid meta robots directive token. This
     * hardens the output against an admin injecting HTML (e.g.
     * `</meta><script>`) into `panth_seo/robots/default_meta`. We allow only
     * the whitelist of directive keywords plus the `max-*:value` Google
     * directives, joined with commas.
     */
    private function sanitizeRobots(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'index,follow';
        }
        $allowed = [
            'index', 'noindex', 'follow', 'nofollow', 'none', 'all',
            'noarchive', 'nosnippet', 'noimageindex', 'notranslate',
            'noodp', 'noydir', 'unavailable_after',
        ];
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (in_array($part, $allowed, true)) {
                $clean[] = $part;
                continue;
            }
            // max-image-preview:(none|standard|large), max-snippet:(-?\d+),
            // max-video-preview:(-?\d+), unavailable_after:<rfc850>
            if (preg_match('/^max-image-preview:(none|standard|large)$/', $part)) {
                $clean[] = $part;
                continue;
            }
            if (preg_match('/^max-(snippet|video-preview):-?\d+$/', $part)) {
                $clean[] = $part;
                continue;
            }
        }
        if ($clean === []) {
            return 'index,follow';
        }
        return implode(',', array_values(array_unique($clean)));
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
