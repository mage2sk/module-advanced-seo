<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\HreflangResolverInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;

class Hreflang implements ArgumentInterface
{
    public function __construct(
        private readonly HreflangResolverInterface $resolver,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled() && $this->config->isHreflangEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int,array{locale:string,url:string,is_default:bool}>
     */
    public function getAlternates(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        try {
            [$type, $id] = $this->detectEntity();
            $storeId = (int) $this->storeManager->getStore()->getId();
            $alternates = [];
            if ($type !== null) {
                $alternates = $this->resolver->getAlternates($type, $id, $storeId);
            }

            // If no alternates from resolver, add self-referencing x-default
            // This is Google best practice even for single-language sites
            if ($alternates === []) {
                $currentUrl = $this->storeManager->getStore()->getCurrentUrl(false);
                // Strip query params for clean hreflang URL
                $cleanUrl = strtok((string) $currentUrl, '?');
                if ($cleanUrl !== false && $cleanUrl !== '') {
                    $alternates[] = [
                        'locale' => 'x-default',
                        'url' => $cleanUrl,
                        'is_default' => true,
                    ];
                }
            }

            return $alternates;
        } catch (\Throwable) {
            return [];
        }
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
