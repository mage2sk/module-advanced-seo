<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Url;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * When "Use Short Category URL" is enabled, replaces the full hierarchical
 * category URL path (e.g. "men/shoes") with just the category's own url_key
 * (e.g. "shoes").
 *
 * This produces shorter, flatter canonical URLs that many SEO strategies prefer,
 * especially when category names are already unique across the tree.
 *
 * Plugin target: Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator::getUrlPath
 */
class ShortCategoryUrlPlugin
{
    private const XML_PATH_USE_SHORT_CATEGORY_URL = 'panth_seo/canonical/use_short_category_url';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Replace the full parent/child path with only the category's own url_key.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetUrlPath(
        CategoryUrlPathGenerator $subject,
        callable $proceed,
        CategoryInterface $category
    ): string {
        if (!$this->isEnabled((int) $category->getStoreId())) {
            return $proceed($category);
        }

        // Root category (level 0) and the default category (level 1) should
        // never have a URL path -- defer to core behaviour.
        $level = (int) $category->getLevel();
        if ($level <= 1) {
            return $proceed($category);
        }

        $urlKey = (string) $category->getUrlKey();
        if ($urlKey === '') {
            // No url_key set; fall through so Magento can generate/fallback.
            return $proceed($category);
        }

        return $urlKey;
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->seoConfig->isEnabled($storeId ?: null)
            && $this->scopeConfig->isSetFlag(
                self::XML_PATH_USE_SHORT_CATEGORY_URL,
                ScopeInterface::SCOPE_STORE,
                $storeId ?: null
            );
    }
}
