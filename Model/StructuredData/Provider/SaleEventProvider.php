<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits sale-specific Offer pricing data on product pages when an active
 * special price is detected, and a SaleEvent node on category pages when
 * category-wide sales are present.
 *
 * On product pages (when special_price is active):
 *  - Merges `validFrom`, `validThrough`, and `priceSpecification`
 *    (UnitPriceSpecification) into the existing Offer node.
 *
 * On category pages:
 *  - Emits a `SaleEvent` node when the category has a scheduled sale
 *    event (read from category attributes `sale_event_name`,
 *    `sale_from_date`, `sale_to_date`).
 *
 * Config path:
 *  - panth_seo/structured_data/sale_event_enabled  (enable/disable)
 */
class SaleEventProvider extends AbstractProvider
{
    private const XML_ENABLED = 'panth_seo/structured_data/sale_event_enabled';

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'sale_event_enabled';
    }

    public function isApplicable(): bool
    {
        if (!$this->isFeatureEnabled()) {
            return false;
        }

        // Applicable on product pages with an active special price,
        // or on category pages with sale metadata.
        $product = $this->getCurrentProduct();
        if ($product !== null) {
            return $this->hasActiveSpecialPrice($product);
        }

        $category = $this->getCurrentCategory();
        if ($category !== null) {
            return $this->hasCategorySaleEvent($category);
        }

        return false;
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product !== null) {
            return $this->buildProductSaleOffer($product);
        }

        $category = $this->getCurrentCategory();
        if ($category !== null) {
            return $this->buildCategorySaleEvent($category);
        }

        return [];
    }

    /**
     * Build the Offer-level sale pricing node for a product with an active special price.
     *
     * @return array<string, mixed>
     */
    private function buildProductSaleOffer(ProductInterface $product): array
    {
        if (!$this->hasActiveSpecialPrice($product)) {
            return [];
        }

        $specialPrice = (float) $product->getData('special_price');
        if ($specialPrice <= 0.0) {
            return [];
        }

        try {
            $store    = $this->storeManager->getStore();
            $currency = (string) $store->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        $url       = (string) $product->getProductUrl();
        $validFrom = $this->formatDate((string) ($product->getData('special_from_date') ?? ''));
        $validThrough = $this->formatDate((string) ($product->getData('special_to_date') ?? ''));

        $offer = [
            '@type' => 'Offer',
            '@id'   => $url . '#offer-sale',
        ];

        if ($validFrom !== '') {
            $offer['validFrom'] = $validFrom;
        }

        if ($validThrough !== '') {
            $offer['validThrough'] = $validThrough;
        }

        $priceSpec = [
            '@type'         => 'UnitPriceSpecification',
            'price'         => number_format($specialPrice, 2, '.', ''),
            'priceCurrency' => $currency,
        ];

        if ($validFrom !== '') {
            $priceSpec['validFrom'] = $validFrom;
        }

        if ($validThrough !== '') {
            $priceSpec['validThrough'] = $validThrough;
        }

        $offer['priceSpecification'] = $priceSpec;

        return $offer;
    }

    /**
     * Build a SaleEvent node for category-wide sales.
     *
     * Reads from category attributes:
     *  - sale_event_name  (string, e.g. "Summer Sale")
     *  - sale_from_date   (date)
     *  - sale_to_date     (date)
     *
     * Falls back to scanning products in the category for active special prices
     * to determine the date range.
     *
     * @return array<string, mixed>
     */
    private function buildCategorySaleEvent(\Magento\Catalog\Api\Data\CategoryInterface $category): array
    {
        $eventName = trim((string) ($category->getData('sale_event_name') ?? ''));
        $fromDate  = $this->formatDate((string) ($category->getData('sale_from_date') ?? ''));
        $toDate    = $this->formatDate((string) ($category->getData('sale_to_date') ?? ''));

        // If the category has explicit sale event data, use it.
        if ($eventName !== '' && $fromDate !== '' && $toDate !== '') {
            return $this->buildSaleEventNode($eventName, $fromDate, $toDate, $category);
        }

        // Fallback: derive the date range from products with active special prices.
        $range = $this->deriveSaleDateRange($category);
        if ($range === null) {
            return [];
        }

        $fallbackName = $eventName !== ''
            ? $eventName
            : (string) $category->getName() . ' Sale';

        return $this->buildSaleEventNode(
            $fallbackName,
            $range['from'],
            $range['through'],
            $category
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSaleEventNode(
        string $name,
        string $startDate,
        string $endDate,
        \Magento\Catalog\Api\Data\CategoryInterface $category
    ): array {
        try {
            $categoryUrl = (string) $category->getUrl();
        } catch (\Throwable) {
            $categoryUrl = '';
        }

        $node = [
            '@type'     => 'SaleEvent',
            'name'      => $name,
            'startDate' => $startDate,
            'endDate'   => $endDate,
        ];

        if ($categoryUrl !== '') {
            $node['url'] = $categoryUrl;
        }

        $description = trim((string) ($category->getData('sale_event_description') ?? ''));
        if ($description !== '') {
            $node['description'] = $description;
        }

        return $node;
    }

    /**
     * Determine whether a product has an active special price right now.
     */
    private function hasActiveSpecialPrice(ProductInterface $product): bool
    {
        $specialPrice = $product->getData('special_price');
        if ($specialPrice === null || $specialPrice === '' || (float) $specialPrice <= 0.0) {
            return false;
        }

        $now = $this->timezone->date()->format('Y-m-d H:i:s');

        $fromDate = (string) ($product->getData('special_from_date') ?? '');
        if ($fromDate !== '') {
            try {
                $fromTs = strtotime($fromDate);
                if ($fromTs !== false && $now < date('Y-m-d H:i:s', $fromTs)) {
                    return false;
                }
            } catch (\Throwable) {
                // Invalid date; treat as unbounded start.
            }
        }

        $toDate = (string) ($product->getData('special_to_date') ?? '');
        if ($toDate !== '') {
            try {
                // special_to_date is inclusive for the entire day.
                $toTs = strtotime($toDate);
                if ($toTs !== false && $now > date('Y-m-d', $toTs) . ' 23:59:59') {
                    return false;
                }
            } catch (\Throwable) {
                // Invalid date; treat as unbounded end.
            }
        }

        return true;
    }

    /**
     * Determine whether a category has explicit sale event attributes.
     */
    private function hasCategorySaleEvent(\Magento\Catalog\Api\Data\CategoryInterface $category): bool
    {
        $eventName = trim((string) ($category->getData('sale_event_name') ?? ''));
        $fromDate  = (string) ($category->getData('sale_from_date') ?? '');
        $toDate    = (string) ($category->getData('sale_to_date') ?? '');

        if ($eventName !== '' && $fromDate !== '' && $toDate !== '') {
            $now = $this->timezone->date()->format('Y-m-d');
            $formattedFrom = $this->formatDate($fromDate);
            $formattedTo   = $this->formatDate($toDate);
            if ($formattedFrom !== '' && $formattedTo !== '' && $now >= $formattedFrom && $now <= $formattedTo) {
                return true;
            }
        }

        // Fallback: check if the category has products with active specials.
        return $this->deriveSaleDateRange($category) !== null;
    }

    /**
     * Scan visible products in a category to find the earliest/latest special price dates.
     *
     * Returns null if no products have active specials, otherwise returns
     * ['from' => 'Y-m-d', 'through' => 'Y-m-d'].
     *
     * @return array{from: string, through: string}|null
     */
    private function deriveSaleDateRange(\Magento\Catalog\Api\Data\CategoryInterface $category): ?array
    {
        try {
            /** @var \Magento\Catalog\Model\Category $category */
            $collection = $category->getProductCollection();
            $collection->addAttributeToSelect(['special_price', 'special_from_date', 'special_to_date']);
            $collection->addFieldToFilter('special_price', ['gt' => 0]);
            $collection->setPageSize(50);
        } catch (\Throwable) {
            return null;
        }

        $now       = $this->timezone->date()->format('Y-m-d H:i:s');
        $earliest  = null;
        $latest    = null;
        $hasActive = false;

        foreach ($collection as $product) {
            if (!$this->hasActiveSpecialPrice($product)) {
                continue;
            }

            $hasActive = true;

            $from = $this->formatDate((string) ($product->getData('special_from_date') ?? ''));
            $to   = $this->formatDate((string) ($product->getData('special_to_date') ?? ''));

            if ($from !== '' && ($earliest === null || $from < $earliest)) {
                $earliest = $from;
            }

            if ($to !== '' && ($latest === null || $to > $latest)) {
                $latest = $to;
            }
        }

        if (!$hasActive) {
            return null;
        }

        return [
            'from'    => $earliest ?? $this->timezone->date()->format('Y-m-d'),
            'through' => $latest ?? $this->timezone->date()->modify('+30 days')->format('Y-m-d'),
        ];
    }

    /**
     * Format a datetime string into ISO 8601 date (Y-m-d).
     */
    private function formatDate(string $dateString): string
    {
        $dateString = trim($dateString);
        if ($dateString === '') {
            return '';
        }

        try {
            $ts = strtotime($dateString);
            if ($ts === false) {
                return '';
            }

            return date('Y-m-d', $ts);
        } catch (\Throwable) {
            return '';
        }
    }

    private function isFeatureEnabled(): bool
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
