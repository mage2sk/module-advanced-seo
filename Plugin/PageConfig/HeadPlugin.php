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

/**
 * Generic safety-net: when publicBuild() assembles the <head>, re-assert the
 * resolved meta so nothing set later in the layout tree can shadow it. Only
 * runs when a catalog or CMS entity is in the registry.
 */
class HeadPlugin
{
    /** @var string[]|null */
    private ?array $filterableAttributeCodes = null;

    public function __construct(
        private readonly MetaResolverInterface $metaResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry $registry,
        private readonly SeoConfig $seoConfig,
        private readonly RequestInterface $request,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    public function beforePublicBuild(PageConfig $subject): array
    {
        try {
            if (!$this->seoConfig->isEnabled()) {
                return [];
            }

            // When a layered-nav filter is active in the URL, the filter-page
            // meta override (Panth_FilterSeo) is the source of truth. Defer
            // here too — otherwise publicBuild() runs after block setLayout
            // and clobbers FilterSeo's title with the parent category meta.
            if ($this->hasActiveFilter()) {
                return [];
            }

            $storeId = (int) $this->storeManager->getStore()->getId();

            [$type, $id] = $this->detectEntity();
            // No catalog/CMS entity in registry (e.g. generic storefront
            // action). Use an empty DataObject as a stand-in so the meta
            // projection below is a no-op instead of throwing.
            $resolved = $type === null
                ? new \Magento\Framework\DataObject()
                : $this->metaResolver->resolve($type, $id, $storeId);

            if ($resolved->getMetaTitle()) {
                $title = (string) $resolved->getMetaTitle();
                // When "Append Store Name to Title" is enabled, append
                // " - {Store Name}" if it is not already present. Templates
                // that already interpolate `{{store.name}}` are left alone so
                // we never double-suffix the store name.
                if ($this->seoConfig->appendStoreName($storeId)) {
                    try {
                        // Use the store view name (e.g. "Default Store View"),
                        // not the group/frontend name (e.g. "Main Website
                        // Store"), so the value matches what templates render
                        // via `{{store.name}}` and the duplicate guard works.
                        $storeName = (string) $this->storeManager->getStore($storeId)->getName();
                    } catch (\Throwable) {
                        $storeName = '';
                    }
                    if ($storeName !== '' && !str_contains($title, $storeName)) {
                        $maxLen = $this->seoConfig->getTitleMaxLength($storeId);
                        $suffix = ' - ' . $storeName;
                        $combined = $title . $suffix;
                        if ($maxLen > 0
                            && function_exists('mb_strlen')
                            && mb_strlen($combined, 'UTF-8') > $maxLen
                        ) {
                            $budget = $maxLen - mb_strlen($suffix, 'UTF-8');
                            if ($budget > 1) {
                                $title = rtrim(mb_substr($title, 0, $budget - 1, 'UTF-8'))
                                    . '…'
                                    . $suffix;
                            } else {
                                $title = mb_substr($combined, 0, $maxLen, 'UTF-8');
                            }
                        } else {
                            $title = $combined;
                        }
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
            // best-effort
        }
        return [];
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

    /**
     * Mirror of MetadataPlugin::hasActiveFilter — getParams() includes both
     * user-supplied $_GET filters and FilterRouter setParam'd codes on
     * pretty URLs, which is exactly the union we need to mean "filter
     * active, defer to FilterSeo".
     */
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

    /**
     * @return string[]
     */
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
            // Empty list = treat as no active filter (safe default).
        }
        return $this->filterableAttributeCodes = $codes;
    }
}
