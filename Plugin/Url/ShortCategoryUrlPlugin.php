<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Url;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class ShortCategoryUrlPlugin
{
    private const XML_PATH_USE_SHORT_CATEGORY_URL = 'panth_seo/canonical/use_short_category_url';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function aroundGetUrlPath(
        CategoryUrlPathGenerator $subject,
        callable $proceed,
        CategoryInterface $category
    ): string {
        if (!$this->isEnabled((int) $category->getStoreId())) {
            return $proceed($category);
        }

        $level = (int) $category->getLevel();
        if ($level <= 1) {
            return $proceed($category);
        }

        $urlKey = (string) $category->getUrlKey();
        if ($urlKey === '') {
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
