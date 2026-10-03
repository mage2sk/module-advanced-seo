<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Catalog\Product;

use Magento\Catalog\Block\Product\View as ProductView;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

class MetadataPlugin
{
    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly PageConfig $pageConfig,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterToHtml(ProductView $subject, string $result): string
    {
        return $result;
    }

    public function aroundSetLayout(
        ProductView $subject,
        callable $proceed,
        \Magento\Framework\View\LayoutInterface $layout
    ) {
        $value = $proceed($layout);
        $this->apply();
        return $value;
    }

    private function apply(): void
    {
        try {
            if (!$this->seoConfig->isEnabled()) {
                return;
            }
            $product = $this->registry->registry('current_product');
            if ($product === null || !$product->getId()) {
                return;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $resolved = $this->metaResolver->resolve(
                MetaResolverInterface::ENTITY_PRODUCT,
                (int) $product->getId(),
                $storeId
            );

            if ($resolved->getMetaTitle() !== null && $resolved->getMetaTitle() !== '') {
                $this->pageConfig->getTitle()->set($resolved->getMetaTitle());
            }
            if ($resolved->getMetaDescription() !== null && $resolved->getMetaDescription() !== '') {
                $this->pageConfig->setDescription($resolved->getMetaDescription());
            }
            if ($resolved->getMetaKeywords() !== null && $resolved->getMetaKeywords() !== '') {
                $this->pageConfig->setKeywords($resolved->getMetaKeywords());
            }
            if ($resolved->getRobots() !== null && $resolved->getRobots() !== '') {
                $this->pageConfig->setRobots($resolved->getRobots());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO product metadata plugin failed', ['error' => $e->getMessage()]);
        }
    }
}
