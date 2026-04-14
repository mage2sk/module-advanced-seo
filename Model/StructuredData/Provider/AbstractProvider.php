<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\StructuredDataProviderInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Common helpers shared by structured-data providers.
 */
abstract class AbstractProvider implements StructuredDataProviderInterface
{
    public function __construct(
        protected readonly Registry $registry,
        protected readonly RequestInterface $request,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly Config $config
    ) {
    }

    public function isApplicable(): bool
    {
        return true;
    }

    protected function getCurrentProduct(): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof \Magento\Catalog\Api\Data\ProductInterface ? $product : null;
    }

    protected function getCurrentCategory(): ?\Magento\Catalog\Api\Data\CategoryInterface
    {
        $category = $this->registry->registry('current_category');
        return $category instanceof \Magento\Catalog\Api\Data\CategoryInterface ? $category : null;
    }

    protected function getCurrentCmsPage(): ?\Magento\Cms\Api\Data\PageInterface
    {
        $page = $this->registry->registry('cms_page') ?? $this->registry->registry('current_cms_page');
        return $page instanceof \Magento\Cms\Api\Data\PageInterface ? $page : null;
    }

    protected function getBaseUrl(): string
    {
        try {
            return rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/';
        } catch (\Throwable) {
            return '/';
        }
    }
}
