<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Builds a rich Product + Offer node for the current product page.
 *
 * Includes Brand, Audience, AggregateRating (when reviews are enabled),
 * itemCondition, availability, priceValidUntil, hasMerchantReturnPolicy and
 * shippingDetails stubs (values can be overridden via config). Falls back
 * gracefully on every missing attribute.
 */
class ProductProvider extends AbstractProvider
{
    private const XML_LIMITED_STOCK_THRESHOLD = 'panth_seo/structured_data/limited_stock_threshold';

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ReviewFactory $reviewFactory,
        private readonly ?StockRegistryInterface $stockRegistry = null,
        private readonly ?ScopeConfigInterface $scopeConfig = null
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'product';
    }

    public function isApplicable(): bool
    {
        return $this->getCurrentProduct() !== null;
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }
        try {
            $store = $this->storeManager->getStore();
            $currency = (string) $store->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        $url = (string) $product->getProductUrl();
        $image = $this->buildImages($product);

        $node = [
            '@type' => 'Product',
            '@id'   => $url . '#product',
            'name'  => (string) $product->getName(),
            'sku'   => (string) $product->getSku(),
            'url'   => $url,
        ];
        if ($image !== []) {
            $node['image'] = count($image) === 1 ? $image[0] : $image;
        }

        $description = trim(strip_tags((string) ($product->getData('meta_description') ?: $product->getData('short_description') ?: $product->getData('description'))));
        if ($description !== '') {
            $node['description'] = mb_substr($description, 0, 5000);
        }

        $mpnAttr = $this->config->getMpnAttribute() ?: 'mpn';
        $mpn = (string) ($product->getData($mpnAttr) ?? '');
        if ($mpn !== '') {
            $node['mpn'] = $mpn;
        }
        $gtinAttr = $this->config->getGtinAttribute();
        $gtin = $gtinAttr !== ''
            ? (string) ($product->getData($gtinAttr) ?? '')
            : (string) ($product->getData('gtin') ?? $product->getData('gtin13') ?? $product->getData('ean') ?? '');
        if ($gtin !== '') {
            $node['gtin'] = $gtin;
        }

        $brandAttr = $this->config->getBrandAttribute() ?: 'manufacturer';
        $brandName = (string) ($product->getAttributeText($brandAttr) ?: $product->getData('brand') ?: '');
        if ($brandName !== '') {
            $node['brand'] = [
                '@type' => 'Brand',
                'name'  => $brandName,
            ];
        }

        $audience = (string) ($product->getAttributeText('gender') ?: $product->getData('target_audience') ?: '');
        if ($audience !== '') {
            $node['audience'] = [
                '@type' => 'PeopleAudience',
                'audienceType' => $audience,
            ];
        }

        $offer = $this->buildOffer($product, $currency, $url);
        if ($offer !== []) {
            $node['offers'] = $offer;
        }

        $rating = $this->buildRating($product);
        if ($rating !== []) {
            $node['aggregateRating'] = $rating;
        }

        // Content freshness signals
        $dateModified = $this->formatIso8601((string) ($product->getData('updated_at') ?? ''));
        if ($dateModified !== '') {
            $node['dateModified'] = $dateModified;
        }
        $datePublished = $this->formatIso8601((string) ($product->getData('created_at') ?? ''));
        if ($datePublished !== '') {
            $node['datePublished'] = $datePublished;
        }

        return $node;
    }

    /**
     * Format a MySQL datetime string as ISO 8601.
     */
    private function formatIso8601(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($datetime);
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<int,string>
     */
    private function buildImages(ProductInterface $product): array
    {
        $urls = [];
        try {
            $main = $this->imageHelper->init($product, 'product_base_image')->getUrl();
            if ($main !== '') {
                $urls[] = $main;
            }
        } catch (\Throwable) {
        }

        try {
            $gallery = $product->getMediaGalleryImages();
            if ($gallery && method_exists($gallery, 'getItems')) {
                foreach ($gallery->getItems() as $item) {
                    $u = (string) $item->getUrl();
                    if ($u !== '' && !in_array($u, $urls, true)) {
                        $urls[] = $u;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $urls;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOffer(ProductInterface $product, string $currency, string $url): array
    {
        $finalPrice = $product->getFinalPrice();
        if ($finalPrice === null || $finalPrice === false) {
            // Complex product: price may live on price info.
            try {
                $finalPrice = (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
            } catch (\Throwable) {
                $finalPrice = 0.0;
            }
        }
        $finalPrice = (float) $finalPrice;
        if ($finalPrice <= 0.0) {
            return [];
        }

        $availability = $this->resolveAvailability($product);

        $offer = [
            '@type' => 'Offer',
            'url'   => $url,
            'price' => number_format($finalPrice, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability'  => $availability,
            'itemCondition' => $this->config->getProductConditionSchemaUrl(),
            'seller' => ['@id' => rtrim($this->getBaseUrl(), '/') . '/#organization'],
        ];

        $priceValidUntil = $this->resolvePriceValidUntil($product);
        if ($priceValidUntil !== '') {
            $offer['priceValidUntil'] = $priceValidUntil;
        }

        // Only add inline return policy stub if the dedicated ReturnPolicyProvider is NOT enabled
        // (i.e. return_policy_days is 0 or unset — meaning no dedicated provider is active).
        if ($this->config->getReturnPolicyDays() <= 0) {
            $offer['hasMerchantReturnPolicy'] = [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'US',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => 30,
                'returnMethod' => 'https://schema.org/ReturnByMail',
                'returnFees' => 'https://schema.org/FreeReturn',
            ];
        }

        // Only add inline shipping stub if the dedicated DeliveryMethodProvider is NOT enabled
        // (i.e. delivery_methods config is empty — meaning no dedicated provider is active).
        if (trim($this->config->getDeliveryMethods()) === '') {
            $offer['shippingDetails'] = [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => [
                    '@type' => 'MonetaryAmount',
                    'value' => '0',
                    'currency' => $currency,
                ],
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => 'US',
                ],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0,
                        'maxValue' => 1,
                        'unitCode' => 'DAY',
                    ],
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 2,
                        'maxValue' => 5,
                        'unitCode' => 'DAY',
                    ],
                ],
            ];
        }

        return $offer;
    }

    /**
     * Determine the priceValidUntil date for the offer.
     *
     * Priority: product special_to_date (if set and in the future) > config default > empty.
     */
    private function resolvePriceValidUntil(ProductInterface $product): string
    {
        $specialTo = (string) ($product->getSpecialToDate() ?? '');
        if ($specialTo !== '') {
            try {
                $ts = strtotime($specialTo);
                if ($ts !== false && $ts > time()) {
                    return date('Y-m-d', $ts);
                }
            } catch (\Throwable) {
                // fall through to config default
            }
        }

        $configDefault = $this->config->getPriceValidUntilDefault();
        if ($configDefault !== '') {
            try {
                $ts = strtotime($configDefault);
                if ($ts !== false) {
                    return date('Y-m-d', $ts);
                }
            } catch (\Throwable) {
                // invalid config value — fall through
            }
        }

        // Final fallback: one year from today (Google requires a non-empty value)
        return date('Y-m-d', strtotime('+1 year'));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRating(ProductInterface $product): array
    {
        try {
            // Preserve any existing RatingSummary on the shared product instance — Magento's
            // Review::getEntitySummary() overwrites it with a DataObject, which breaks
            // downstream templates (e.g. Hyva review summary.phtml) that expect an int/float.
            $hadOriginal = $product->hasData('rating_summary');
            $original = $hadOriginal ? $product->getData('rating_summary') : null;

            $review = $this->reviewFactory->create();
            $review->getEntitySummary($product, (int) $this->storeManager->getStore()->getId());
            $summary = $product->getRatingSummary();

            // Restore the original value (or unset it) so we never mutate the shared product.
            if ($hadOriginal) {
                $product->setData('rating_summary', $original);
            } else {
                $product->unsetData('rating_summary');
            }

            if (!$summary instanceof \Magento\Framework\DataObject) {
                return [];
            }
            $reviewCount = (int) $summary->getReviewsCount();
            $rating = (float) $summary->getRatingSummary();
            if ($reviewCount <= 0 || $rating <= 0) {
                return [];
            }
            return [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($rating / 20.0, 2, '.', ''), // Magento scale 0-100
                'bestRating' => '5',
                'worstRating' => '1',
                'reviewCount' => $reviewCount,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Determine granular schema.org availability beyond simple InStock/OutOfStock.
     *
     * Priority:
     *   1. Product disabled or not visible individually → Discontinued
     *   2. news_from_date in the future                → PreOrder
     *   3. Salable + available                         → InStock (or LimitedAvailability)
     *   4. Qty = 0 but backorders enabled              → BackOrder
     *   5. Anything else out of stock                  → OutOfStock
     */
    private function resolveAvailability(ProductInterface $product): string
    {
        try {
            // Discontinued: disabled or not visible individually.
            $status = (int) $product->getStatus();
            $visibility = (int) $product->getVisibility();
            if ($status === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED
                || $visibility === \Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE
            ) {
                return 'https://schema.org/Discontinued';
            }

            // PreOrder: news_from_date is in the future.
            $newsFrom = (string) ($product->getData('news_from_date') ?? '');
            if ($newsFrom !== '') {
                $ts = strtotime($newsFrom);
                if ($ts !== false && $ts > time()) {
                    return 'https://schema.org/PreOrder';
                }
            }

            // Stock-based checks require StockRegistryInterface.
            if ($this->stockRegistry !== null) {
                $stockItem = $this->stockRegistry->getStockItem(
                    (int) $product->getId()
                );

                $qty = (float) $stockItem->getQty();
                $isInStock = (bool) $stockItem->getIsInStock();
                $backorders = (int) $stockItem->getBackorders();

                // Out of stock with backorders enabled → BackOrder.
                if (!$isInStock && $backorders > 0) {
                    return 'https://schema.org/BackOrder';
                }
                if ($isInStock && $qty <= 0 && $backorders > 0) {
                    return 'https://schema.org/BackOrder';
                }

                // Out of stock, no backorders → OutOfStock.
                if (!$isInStock) {
                    return 'https://schema.org/OutOfStock';
                }

                // LimitedAvailability: in stock but below threshold.
                $threshold = $this->getLimitedStockThreshold();
                if ($qty > 0 && $qty < $threshold) {
                    return 'https://schema.org/LimitedAvailability';
                }

                // In stock with sufficient quantity.
                if ($product->isSalable() || $isInStock) {
                    return 'https://schema.org/InStock';
                }
            }

            // Fallback when StockRegistry is unavailable.
            if ($product->isAvailable()) {
                return 'https://schema.org/InStock';
            }
        } catch (\Throwable) {
            // On any exception, fall through to safe default.
        }

        return 'https://schema.org/OutOfStock';
    }

    /**
     * Read the limited-stock threshold from config (default 5).
     */
    private function getLimitedStockThreshold(): int
    {
        if ($this->scopeConfig === null) {
            return 5;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        $value = $this->scopeConfig->getValue(
            self::XML_LIMITED_STOCK_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? max(1, (int) $value) : 5;
    }
}
