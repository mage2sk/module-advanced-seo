<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Catalog\Category;

use Magento\Catalog\Block\Category\View as CategoryView;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

class MetadataPlugin
{
    private ?array $filterableAttributeCodes = null;

    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly PageConfig $pageConfig,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $seoConfig,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    public function aroundSetLayout(CategoryView $subject, callable $proceed, LayoutInterface $layout)
    {
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

            if ($this->hasActiveFilter()) {
                return;
            }

            $category = $this->registry->registry('current_category');
            if ($category === null || !$category->getId()) {
                return;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            $resolved = $this->metaResolver->resolve(
                MetaResolverInterface::ENTITY_CATEGORY,
                (int) $category->getId(),
                $storeId
            );

            if ($resolved->getMetaTitle()) {
                $this->pageConfig->getTitle()->set($resolved->getMetaTitle());
            }
            if ($resolved->getMetaDescription()) {
                $this->pageConfig->setDescription($resolved->getMetaDescription());
            }
            if ($resolved->getMetaKeywords()) {
                $this->pageConfig->setKeywords($resolved->getMetaKeywords());
            }
            if ($resolved->getRobots()) {
                $this->pageConfig->setRobots($resolved->getRobots());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO category metadata plugin failed', ['error' => $e->getMessage()]);
        }
    }

    private function hasActiveFilter(): bool
    {
        $params = $this->request->getParams();
        foreach ($this->getFilterableAttributeCodes() as $code) {
            if (!empty($params[$code])) {
                return true;
            }
        }
        return false;
    }

    private function getFilterableAttributeCodes(): array
    {
        if ($this->filterableAttributeCodes !== null) {
            return $this->filterableAttributeCodes;
        }
        $codes = [];
        try {
            $coll = $this->attributeCollectionFactory->create();
            $coll->setEntityTypeFilter(4);
            $coll->addFieldToFilter('is_filterable', ['in' => [1, 2]]);
            foreach ($coll as $attr) {
                $codes[] = (string) $attr->getAttributeCode();
            }
        } catch (\Throwable) {
        }
        return $this->filterableAttributeCodes = $codes;
    }
}
