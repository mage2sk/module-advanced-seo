<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * For configurable products, emits an AggregateOffer containing one Offer per
 * visible child variant. Includes price, currency, availability, sku and url
 * for each child. The parent node receives lowPrice / highPrice / offerCount.
 *
 * Only activates when:
 *  - the current product type_id is "configurable"
 *  - config flag `panth_seo/structured_data/configurable_multi_offer` is enabled
 */
class ConfigurableOfferProvider extends AbstractProvider
{
    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly Configurable $configurableType,
        private readonly StockRegistryInterface $stockRegistry
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'configurable_offer';
    }

    public function isApplicable(): bool
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return false;
        }

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
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

        try {
            $store = $this->storeManager->getStore();
            $currency = (string) $store->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        $children = $this->getVisibleChildren($product);
        if ($children === []) {
            return [];
        }

        $offers = [];
        $prices = [];

        foreach ($children as $child) {
            $offer = $this->buildChildOffer($child, $currency);
            if ($offer !== []) {
                $offers[] = $offer;
                $price = (float) ($offer['price'] ?? 0);
                if ($price > 0) {
                    $prices[] = $price;
                }
            }
        }

        if ($offers === [] || $prices === []) {
            return [];
        }

        $lowPrice = min($prices);
        $highPrice = max($prices);

        $url = (string) $product->getProductUrl();

        return [
            '@type' => 'Product',
            '@id'   => $url . '#product',
            'name'  => (string) $product->getName(),
            'sku'   => (string) $product->getSku(),
            'url'   => $url,
            'offers' => [
                '@type'      => 'AggregateOffer',
                'lowPrice'   => number_format($lowPrice, 2, '.', ''),
                'highPrice'  => number_format($highPrice, 2, '.', ''),
                'offerCount' => count($offers),
                'priceCurrency' => $currency,
                'offers'     => $offers,
            ],
        ];
    }

    /**
     * @return ProductInterface[]
     */
    private function getVisibleChildren(ProductInterface $product): array
    {
        try {
            /** @var ProductInterface[] $children */
            $children = $this->configurableType->getUsedProducts($product);
        } catch (\Throwable) {
            return [];
        }

        $visible = [];
        foreach ($children as $child) {
            $status = (int) $child->getStatus();
            // Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED = 1
            if ($status === 1) {
                $visible[] = $child;
            }
        }

        return $visible;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildChildOffer(ProductInterface $child, string $currency): array
    {
        $finalPrice = $child->getFinalPrice();
        if ($finalPrice === null || $finalPrice === false) {
            try {
                $finalPrice = (float) $child->getPriceInfo()->getPrice('final_price')->getValue();
            } catch (\Throwable) {
                $finalPrice = 0.0;
            }
        }
        $finalPrice = (float) $finalPrice;
        if ($finalPrice <= 0.0) {
            return [];
        }

        $availability = $this->getAvailability($child);
        $url = (string) $child->getProductUrl();

        return [
            '@type'         => 'Offer',
            'price'         => number_format($finalPrice, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability'  => $availability,
            'sku'           => (string) $child->getSku(),
            'url'           => $url !== '' ? $url : null,
        ];
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
}
