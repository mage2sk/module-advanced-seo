<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\FilterMeta;

use Magento\Catalog\Controller\Category\View;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\FilterMeta\MetaInjector;
use Psr\Log\LoggerInterface;

class CategoryViewPlugin
{
    public function __construct(
        private readonly MetaInjector $metaInjector,
        private readonly Registry $registry,
        private readonly PageConfig $pageConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * After the category page controller executes, inject filter-specific meta tags.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(View $subject, ResultInterface|null $result): ResultInterface|null
    {
        if ($result === null) {
            return null;
        }

        try {
            if (!$this->isFilterMetaEnabled()) {
                return $result;
            }

            $category = $this->registry->registry('current_category');
            if ($category === null || !$category->getId()) {
                return $result;
            }

            $categoryId = (int) $category->getId();
            $storeId = (int) $this->storeManager->getStore()->getId();

            $this->metaInjector->inject($this->pageConfig, $categoryId, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Panth SEO filter meta injection failed',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }

    private function isFilterMetaEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                'panth_seo/filter_meta/filter_meta_enabled',
                ScopeInterface::SCOPE_STORE
            );
    }
}
