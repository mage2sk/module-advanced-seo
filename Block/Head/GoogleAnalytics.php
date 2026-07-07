<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

class GoogleAnalytics extends Template
{
    public const XML_GA4_ENABLED        = 'panth_seo/analytics/ga4_enabled';
    public const XML_GA4_MEASUREMENT_ID = 'panth_seo/analytics/ga4_measurement_id';
    public const XML_GA4_ENHANCED_ECOM  = 'panth_seo/analytics/ga4_enhanced_ecommerce';

    private const MEASUREMENT_ID_REGEX = '/^[A-Za-z0-9_\-]{1,64}$/';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Registry $registry,
        private readonly CatalogHelper $catalogHelper,
        private readonly SeoConfig $seoConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_GA4_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->getMeasurementId() !== '';
    }

    public function getMeasurementId(): string
    {
        $raw = trim((string) ($this->scopeConfig->getValue(
            self::XML_GA4_MEASUREMENT_ID,
            ScopeInterface::SCOPE_STORE
        ) ?? ''));

        if ($raw === '' || !preg_match(self::MEASUREMENT_ID_REGEX, $raw)) {
            return '';
        }

        return $raw;
    }

    public function isEnhancedEcommerceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_GA4_ENHANCED_ECOM, ScopeInterface::SCOPE_STORE);
    }

    public function getEnhancedEcommerceJs(): string
    {
        if (!$this->isEnhancedEcommerceEnabled()) {
            return '';
        }

        $product = $this->getCurrentProduct();
        if ($product !== null) {
            return $this->buildViewItemEvent($product);
        }

        $category = $this->getCurrentCategory();
        if ($category !== null) {
            return $this->buildViewItemListEvent($category);
        }

        return '';
    }

    private function buildViewItemEvent(ProductInterface $product): string
    {
        $currency = $this->getCurrencyCode();
        $price = $this->resolvePrice($product);

        $item = [
            'item_id'   => (string) $product->getSku(),
            'item_name' => (string) $product->getName(),
            'price'     => $price,
            'currency'  => $currency,
        ];

        $brand = $this->resolveBrand($product);
        if ($brand !== '') {
            $item['item_brand'] = $brand;
        }

        $categoryName = $this->resolveCategoryName($product);
        if ($categoryName !== '') {
            $item['item_category'] = $categoryName;
        }

        $event = [
            'event'    => 'view_item',
            'ecommerce' => [
                'currency' => $currency,
                'value'    => $price,
                'items'    => [$item],
            ],
        ];

        return $this->wrapEvent($event);
    }

    private function buildViewItemListEvent(CategoryInterface $category): string
    {
        $currency = $this->getCurrencyCode();
        $listName = (string) $category->getName();
        $listId   = 'category_' . $category->getId();

        try {
            $layer = $this->getLayout()
                ->getBlock('category.products.list')
                ?->getLayer();
            $collection = $layer?->getProductCollection();
        } catch (\Throwable) {
            $collection = null;
        }

        if ($collection === null) {
            return '';
        }

        try {
            $loadedProducts = $collection->getItems();
        } catch (\Throwable) {
            return '';
        }

        $items = [];
        $index = 0;
        foreach ($loadedProducts as $product) {
            if ($index >= 50) {
                break;
            }

            $item = [
                'item_id'       => (string) $product->getSku(),
                'item_name'     => (string) $product->getName(),
                'price'         => $this->resolvePrice($product),
                'currency'      => $currency,
                'item_list_name' => $listName,
                'item_list_id'  => $listId,
                'index'         => $index,
            ];

            $brand = $this->resolveBrand($product);
            if ($brand !== '') {
                $item['item_brand'] = $brand;
            }

            $categoryName = (string) $category->getName();
            if ($categoryName !== '') {
                $item['item_category'] = $categoryName;
            }

            $items[] = $item;
            $index++;
        }

        if ($items === []) {
            return '';
        }

        $event = [
            'event'    => 'view_item_list',
            'ecommerce' => [
                'item_list_name' => $listName,
                'item_list_id'   => $listId,
                'items'          => $items,
            ],
        ];

        return $this->wrapEvent($event);
    }

    private function wrapEvent(array $event): string
    {
        return "gtag('event', " . json_encode($event['event'], JSON_THROW_ON_ERROR) . ", "
            . json_encode($event['ecommerce'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            . ");";
    }

    private function resolvePrice(ProductInterface $product): float
    {
        try {
            $price = (float) $product->getFinalPrice();
            if ($price <= 0.0) {
                $price = (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
            }
        } catch (\Throwable) {
            $price = 0.0;
        }

        return round($price, 2);
    }

    private function resolveBrand(ProductInterface $product): string
    {
        try {
            return (string) ($product->getAttributeText('manufacturer') ?: $product->getData('brand') ?: '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveCategoryName(ProductInterface $product): string
    {
        try {
            $breadcrumbs = $this->catalogHelper->getBreadcrumbPath();

            $crumbs = array_values($breadcrumbs);
            if (count($crumbs) >= 2) {
                return (string) ($crumbs[count($crumbs) - 2]['label'] ?? '');
            }
        } catch (\Throwable) {
        }

        try {
            $category = $this->getCurrentCategory();
            if ($category !== null) {
                return (string) $category->getName();
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function getCurrentProduct(): ?ProductInterface
    {
        $product = $this->registry->registry('current_product');
        return $product instanceof ProductInterface ? $product : null;
    }

    private function getCurrentCategory(): ?CategoryInterface
    {
        $category = $this->registry->registry('current_category');
        return $category instanceof CategoryInterface ? $category : null;
    }

    private function getCurrencyCode(): string
    {
        try {
            return (string) $this->_storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Throwable) {
            return 'USD';
        }
    }
}
