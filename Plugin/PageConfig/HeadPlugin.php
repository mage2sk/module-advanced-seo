<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\PageConfig;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Text\Truncator;

class HeadPlugin
{
    private ?array $filterableAttributeCodes = null;

    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry $registry,
        private readonly SeoConfig $seoConfig,
        private readonly RequestInterface $request,
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly Truncator $truncator
    ) {
    }

    public function beforePublicBuild(PageConfig $subject): array
    {
        try {
            if (!$this->seoConfig->isEnabled()) {
                return [];
            }

            if ($this->hasActiveFilter()) {
                return [];
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            [$type, $id] = $this->detectEntity();

            $resolved = $type === null
                ? new \Magento\Framework\DataObject()
                : $this->metaResolver->resolve($type, $id, $storeId);

            if ($resolved->getMetaTitle()) {
                $title = (string) $resolved->getMetaTitle();

                if ($this->seoConfig->appendStoreName($storeId)) {
                    try {
                        $storeName = (string) $this->storeManager->getStore($storeId)->getName();
                    } catch (\Throwable) {
                        $storeName = '';
                    }
                    if ($storeName !== '' && !str_contains($title, $storeName)) {
                        $title = $this->composeTitle(
                            $title,
                            $storeName,
                            $this->seoConfig->getTitleMaxLength($storeId)
                        );
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
        } catch (\Throwable) {
        }
        return [];
    }

    private function composeTitle(string $title, string $storeName, int $maxLen): string
    {
        $suffix = ' - ' . $storeName;
        $combined = $title . $suffix;

        if ($maxLen <= 0 || !function_exists('mb_strlen') || mb_strlen($combined, 'UTF-8') <= $maxLen) {
            return $combined;
        }

        $budget = $maxLen - mb_strlen($suffix, 'UTF-8');
        if ($budget <= 3) {
            return mb_substr($combined, 0, $maxLen, 'UTF-8');
        }

        return $this->truncator->truncate($title, $budget) . $suffix;
    }

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
