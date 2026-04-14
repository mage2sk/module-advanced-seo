<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Catalog\Canonical;

use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Suppress Magento native category / product canonical emission when
 * Panth_AdvancedSEO owns canonical output.
 *
 * Magento's Helper\Category and Helper\Product expose canUseCanonicalTag(),
 * which the native catalog head blocks consult before calling
 * Page\Config::addRemotePageAsset(..., 'canonical'). If that native path runs
 * alongside Panth\AdvancedSEO\Block\Head\Canonical, two <link rel="canonical">
 * tags end up in <head> (Magento's plus Panth's), which is a hard SEO bug.
 *
 * This plugin short-circuits the native path whenever Panth canonical is on,
 * so only Panth's pagination-aware, resolver-driven canonical is emitted.
 * The admin UI values for catalog/seo/*_canonical_tag are NOT touched here:
 * the suppression is transparent at runtime. The same plugin class is bound
 * twice in di.xml (once per helper) because Magento plugin methods are keyed
 * by name and both helpers expose the same `canUseCanonicalTag()` method.
 */
class NativeCanonicalSuppressor
{
    public function __construct(
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * After-plugin for both Magento\Catalog\Helper\Category::canUseCanonicalTag
     * and Magento\Catalog\Helper\Product::canUseCanonicalTag.
     *
     * @param object $subject
     * @param bool|int|string|null $result
     * @return bool|int|string|null
     */
    public function afterCanUseCanonicalTag($subject, $result)
    {
        return $this->shouldSuppress() ? false : $result;
    }

    private function shouldSuppress(): bool
    {
        try {
            return $this->seoConfig->isEnabled() && $this->seoConfig->isCanonicalEnabled();
        } catch (\Throwable) {
            return false;
        }
    }
}
