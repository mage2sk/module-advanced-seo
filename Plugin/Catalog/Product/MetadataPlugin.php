<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Catalog\Product;

use Magento\Catalog\Block\Product\View as ProductView;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Robots\MetaResolver as RobotsMetaResolver;
use Psr\Log\LoggerInterface;

/**
 * Injects resolved meta (title/description/keywords/canonical/robots) into
 * the page config when rendering a product view block. Works in both Luma
 * and Hyva because it targets PageConfig which every theme consumes.
 */
class MetadataPlugin
{
    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly PageConfig $pageConfig,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig,
        private readonly LoggerInterface $logger,
        private readonly ?RobotsMetaResolver $robotsMetaResolver = null
    ) {
    }

    public function afterToHtml(ProductView $subject, string $result): string
    {
        return $result;
    }

    /**
     * Hook _prepareLayout via a wrapping around: apply meta after parent layout prep.
     */
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
                $robots = $resolved->getRobots();
                if ($this->robotsMetaResolver !== null) {
                    $robots = $this->robotsMetaResolver->appendAdvancedDirectives($robots, $storeId);
                }
                $this->pageConfig->setRobots($robots);
            }
            // Canonical is handled by Block\Head\Canonical (via ViewModel\Canonical)
            // which is pagination-aware.  Adding it here via addRemotePageAsset
            // would create a duplicate <link rel="canonical"> tag.
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO product metadata plugin failed', ['error' => $e->getMessage()]);
        }
    }
}
