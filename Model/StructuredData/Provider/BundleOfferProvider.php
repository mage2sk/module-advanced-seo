<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Bundle\Model\Product\Price as BundlePrice;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * For bundle products, emits structured-data pricing as either:
 *  - AggregateOffer (dynamic pricing): lowPrice / highPrice from the bundle
 *    price range, offerCount = 1.
 *  - Offer (fixed pricing): a single offer with the fixed final price.
 *
 * Only activates when:
 *  - the current product type_id is "bundle"
 *  - config flag `panth_seo/structured_data/configurable_multi_offer` is enabled
 */
class BundleOfferProvider extends AbstractProvider
{
    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly StockRegistryInterface $stockRegistry
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'bundle_offer';
    }

    public function isApplicable(): bool
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return false;
        }

        if ($product->getTypeId() !== BundleType::TYPE_CODE) {
            return false;
        }

        return $this->config->isStructuredDataEnabled('configurable_multi_offer');
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $currency     = $this->resolveCurrency();
        $availability = $this->getAvailability($product);
        $url          = (string) $product->getProductUrl();
        $isFixedPrice = ((int) $product->getPriceType()) === BundlePrice::PRICE_TYPE_FIXED;

        if ($isFixedPrice) {
            return $this->buildFixedPriceNode($product, $currency, $availability, $url);
        }

        return $this->buildDynamicPriceNode($product, $currency, $availability, $url);
    }

    /**
     * Fixed-price bundle: emit a single Offer with the final price.
     *
     * @return array<string,mixed>
     */
    private function buildFixedPriceNode(
        ProductInterface $product,
        string $currency,
        string $availability,
        string $url
    ): array {
        $finalPrice = $this->getFinalPrice($product);
        if ($finalPrice <= 0.0) {
            return [];
        }

        return [
            '@type' => 'Product',
            '@id'   => $url . '#product',
            'name'  => (string) $product->getName(),
            'sku'   => (string) $product->getSku(),
            'url'   => $url,
            'offers' => [
                '@type'         => 'Offer',
                'price'         => number_format($finalPrice, 2, '.', ''),
                'priceCurrency' => $currency,
                'availability'  => $availability,
                'url'           => $url,
            ],
        ];
    }

    /**
     * Dynamic-price bundle: emit an AggregateOffer with lowPrice / highPrice
     * derived from the bundle price range.
     *
     * @return array<string,mixed>
     */
    private function buildDynamicPriceNode(
        ProductInterface $product,
        string $currency,
        string $availability,
        string $url
    ): array {
        [$minPrice, $maxPrice] = $this->getPriceRange($product);

        if ($minPrice <= 0.0 && $maxPrice <= 0.0) {
            return [];
        }

        // Ensure sane boundaries
        if ($minPrice <= 0.0) {
            $minPrice = $maxPrice;
        }
        if ($maxPrice <= 0.0) {
            $maxPrice = $minPrice;
        }

        return [
            '@type' => 'Product',
            '@id'   => $url . '#product',
            'name'  => (string) $product->getName(),
            'sku'   => (string) $product->getSku(),
            'url'   => $url,
            'offers' => [
                '@type'         => 'AggregateOffer',
                'lowPrice'      => number_format($minPrice, 2, '.', ''),
                'highPrice'     => number_format($maxPrice, 2, '.', ''),
                'offerCount'    => 1,
                'priceCurrency' => $currency,
                'availability'  => $availability,
            ],
        ];
    }

    /**
     * Resolve min/max prices for a dynamic-price bundle.
     *
     * Tries `getTotalPrices()` first (Magento\Bundle\Model\Product\Price),
     * then falls back to the PriceInfo final_price amount range.
     *
     * @return array{0: float, 1: float}
     */
    private function getPriceRange(ProductInterface $product): array
    {
        // Strategy 1: Bundle price model getTotalPrices()
        try {
            /** @var BundlePrice $priceModel */
            $priceModel = $product->getPriceModel();
            if ($priceModel instanceof BundlePrice) {
                $totalPrices = $priceModel->getTotalPrices($product, 'min', true);
                $minPrice    = (float) $totalPrices;
                $totalPrices = $priceModel->getTotalPrices($product, 'max', true);
                $maxPrice    = (float) $totalPrices;

                if ($minPrice > 0.0 || $maxPrice > 0.0) {
                    return [$minPrice, $maxPrice];
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        // Strategy 2: PriceInfo final_price with min/max amounts
        try {
            $priceInfo  = $product->getPriceInfo();
            $finalPrice = $priceInfo->getPrice('final_price');

            $minPrice = (float) $finalPrice->getMinimalPrice()->getValue();
            $maxPrice = (float) $finalPrice->getMaximalPrice()->getValue();

            if ($minPrice > 0.0 || $maxPrice > 0.0) {
                return [$minPrice, $maxPrice];
            }
        } catch (\Throwable) {
            // fall through
        }

        // Strategy 3: plain getFinalPrice()
        $finalPrice = $this->getFinalPrice($product);

        return [$finalPrice, $finalPrice];
    }

    private function getFinalPrice(ProductInterface $product): float
    {
        $finalPrice = $product->getFinalPrice();
        if ($finalPrice === null || $finalPrice === false) {
            try {
                $finalPrice = (float) $product->getPriceInfo()
                    ->getPrice('final_price')
                    ->getValue();
            } catch (\Throwable) {
                $finalPrice = 0.0;
            }
        }

        return (float) $finalPrice;
    }

    private function getAvailability(ProductInterface $product): string
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem(
                (int) $product->getId()
            );
            $isInStock = $stockItem->getIsInStock();
        } catch (\Throwable) {
            $isInStock = false;
        }

        return $isInStock
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';
    }

    private function resolveCurrency(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Throwable) {
            return 'USD';
        }
    }
}
