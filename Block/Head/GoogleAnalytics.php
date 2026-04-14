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

/**
 * Outputs the GA4 gtag.js snippet and enhanced ecommerce dataLayer events.
 *
 * On product pages: fires a `view_item` event.
 * On category pages: fires a `view_item_list` event.
 * On cart additions: fires `add_to_cart` via Alpine/JS-triggered push.
 *
 * Hyva-safe: this emits standard Google script tags, no jQuery dependency.
 */
class GoogleAnalytics extends Template
{
    public const XML_GA4_ENABLED        = 'panth_seo/analytics/ga4_enabled';
    public const XML_GA4_MEASUREMENT_ID = 'panth_seo/analytics/ga4_measurement_id';
    public const XML_GA4_ENHANCED_ECOM  = 'panth_seo/analytics/ga4_enhanced_ecommerce';

    /**
     * Whitelist for GA4 Measurement IDs. Google's format is `G-XXXXXXXXXX`
     * (alphanumeric, 10+ chars). We intentionally accept a wider charset
     * (alnum, dash, underscore, up to 64 chars) to stay forward-compatible,
     * but any other character rejects the value entirely to prevent HTML/JS
     * injection via a compromised admin session (defence-in-depth).
     */
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

    /**
     * Whether GA4 output is active: master module enabled, GA4 flag is set, and a
     * well-formed measurement ID is present.
     */
    public function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_GA4_ENABLED, ScopeInterface::SCOPE_STORE)
            && $this->getMeasurementId() !== '';
    }

    /**
     * The GA4 measurement ID (e.g. G-XXXXXXXXXX).
     *
     * Returns an empty string for any value that does not match the
     * MEASUREMENT_ID_REGEX whitelist. This guarantees the template can
     * safely emit the value without further validation.
     */
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

    /**
     * Whether enhanced ecommerce events should be emitted.
     */
    public function isEnhancedEcommerceEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_GA4_ENHANCED_ECOM, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Build the enhanced ecommerce event JS snippet for the current page context.
     *
     * Returns empty string when enhanced ecommerce is disabled or no event applies.
     */
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

    /**
     * Build the `view_item` event JS for a product page.
     */
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

    /**
     * Build the `view_item_list` event JS for a category page.
     */
    private function buildViewItemListEvent(CategoryInterface $category): string
    {
        $currency = $this->getCurrencyCode();
        $listName = (string) $category->getName();
        $listId   = 'category_' . $category->getId();

        try {
            /** @var \Magento\Catalog\Model\Layer $layer */
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

        $items = [];
        $index = 0;
        foreach ($collection as $product) {
            if ($index >= 50) {
                break; // Limit to avoid oversized data layer
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

    /**
     * Wrap an event array into a gtag dataLayer push JS statement.
     *
     * @param array<string, mixed> $event
     */
    private function wrapEvent(array $event): string
    {
        return "gtag('event', " . json_encode($event['event'], JSON_THROW_ON_ERROR) . ", "
            . json_encode($event['ecommerce'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            . ");";
    }

    /**
     * Resolve the final price for a product.
     */
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

    /**
     * Resolve brand name from product attributes.
     */
    private function resolveBrand(ProductInterface $product): string
    {
        try {
            return (string) ($product->getAttributeText('manufacturer') ?: $product->getData('brand') ?: '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve the primary category name for a product.
     */
    private function resolveCategoryName(ProductInterface $product): string
    {
        try {
            $breadcrumbs = $this->catalogHelper->getBreadcrumbPath();
            // The last breadcrumb before the product is the category.
            $crumbs = array_values($breadcrumbs);
            if (count($crumbs) >= 2) {
                return (string) ($crumbs[count($crumbs) - 2]['label'] ?? '');
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $category = $this->getCurrentCategory();
            if ($category !== null) {
                return (string) $category->getName();
            }
        } catch (\Throwable) {
            // fall through
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
