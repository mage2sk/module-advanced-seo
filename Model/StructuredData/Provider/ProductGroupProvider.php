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
 * Emits `ProductGroup` + `hasVariant` structured data for configurable products.
 *
 * Each configurable product is represented as a `ProductGroup` with `variesBy`
 * referencing schema.org property URLs derived from super-attribute codes, and
 * `hasVariant` containing one `Product` node per enabled child including its own
 * `Offer`.
 *
 * Only fires when:
 *  - product type_id = "configurable"
 *  - config flag `panth_seo/structured_data/product_group_enabled` is enabled
 */
class ProductGroupProvider extends AbstractProvider
{
    /**
     * Maps well-known Magento attribute codes to their schema.org property URLs.
     */
    private const VARIES_BY_MAP = [
        'color'    => 'https://schema.org/color',
        'size'     => 'https://schema.org/size',
        'material' => 'https://schema.org/material',
    ];

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
        return 'product_group';
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

        return $this->config->isStructuredDataEnabled('product_group_enabled');
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $parentUrl = (string) $product->getProductUrl();
        $parentSku = (string) $product->getSku();

        try {
            $store = $this->storeManager->getStore();
            $currency = (string) $store->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        $superAttributes = $this->getSuperAttributeCodes($product);
        $variesBy = $this->buildVariesBy($superAttributes);

        $children = $this->getVisibleChildren($product);
        if ($children === []) {
            return [];
        }

        $variants = [];
        foreach ($children as $child) {
            $variant = $this->buildVariantNode($child, $parentUrl, $superAttributes, $currency);
            if ($variant !== []) {
                $variants[] = $variant;
            }
        }

        if ($variants === []) {
            return [];
        }

        $node = [
            '@type'          => 'ProductGroup',
            '@id'            => $parentUrl . '#productgroup',
            'productGroupID' => $parentSku,
            'name'           => (string) $product->getName(),
            'url'            => $parentUrl,
        ];

        $description = $this->getProductDescription($product);
        if ($description !== '') {
            $node['description'] = $description;
        }

        $image = $this->getProductImage($product);
        if ($image !== '') {
            $node['image'] = $image;
        }

        $brand = $this->getProductBrand($product);
        if ($brand !== '') {
            $node['brand'] = [
                '@type' => 'Brand',
                'name'  => $brand,
            ];
        }

        if ($variesBy !== []) {
            $node['variesBy'] = $variesBy;
        }

        $node['hasVariant'] = $variants;

        return $node;
    }

    /**
     * Read the super-attribute codes from the configurable type model.
     *
     * @return string[] Attribute codes (e.g. ['color', 'size'])
     */
    private function getSuperAttributeCodes(ProductInterface $product): array
    {
        try {
            $attributes = $this->configurableType->getConfigurableAttributesAsArray($product);
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        foreach ($attributes as $attribute) {
            $code = (string) ($attribute['attribute_code'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Map super attribute codes to schema.org property URLs.
     *
     * @param  string[] $attributeCodes
     * @return string[]
     */
    private function buildVariesBy(array $attributeCodes): array
    {
        $variesBy = [];
        foreach ($attributeCodes as $code) {
            $lower = strtolower($code);
            if (isset(self::VARIES_BY_MAP[$lower])) {
                $variesBy[] = self::VARIES_BY_MAP[$lower];
            }
        }

        return $variesBy;
    }

    /**
     * Build a single variant Product node with its own Offer.
     *
     * @param  string[] $superAttributeCodes
     * @return array<string,mixed>
     */
    private function buildVariantNode(
        ProductInterface $child,
        string $parentUrl,
        array $superAttributeCodes,
        string $currency
    ): array {
        $finalPrice = $this->resolvePrice($child);
        if ($finalPrice <= 0.0) {
            return [];
        }

        $childUrl = (string) $child->getProductUrl();

        $variant = [
            '@type'      => 'Product',
            'sku'        => (string) $child->getSku(),
            'name'       => (string) $child->getName(),
            'url'        => $childUrl !== '' ? $childUrl : null,
        ];

        $image = $this->getProductImage($child);
        if ($image !== '') {
            $variant['image'] = $image;
        }

        // Add variant-specific attribute values (color, size, material).
        foreach ($superAttributeCodes as $code) {
            $lower = strtolower($code);
            if (isset(self::VARIES_BY_MAP[$lower])) {
                $value = $this->getAttributeText($child, $code);
                if ($value !== '') {
                    $variant[$lower] = $value;
                }
            }
        }

        $variant['isVariantOf'] = [
            '@id' => $parentUrl . '#productgroup',
        ];

        $variant['offers'] = [
            '@type'         => 'Offer',
            'price'         => number_format($finalPrice, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability'  => $this->getAvailability($child),
            'itemCondition' => $this->config->getProductConditionSchemaUrl(),
        ];

        return $variant;
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
            if ($status === 1) {
                $visible[] = $child;
            }
        }

        return $visible;
    }

    private function resolvePrice(ProductInterface $product): float
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
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
            $isInStock = $stockItem->getIsInStock();
        } catch (\Throwable) {
            $isInStock = false;
        }

        return $isInStock
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';
    }

    private function getProductDescription(ProductInterface $product): string
    {
        $desc = (string) $product->getData('short_description');
        if ($desc === '') {
            $desc = (string) $product->getData('description');
        }

        $desc = trim(strip_tags($desc));

        return mb_strlen($desc) > 5000 ? mb_substr($desc, 0, 5000) : $desc;
    }

    private function getProductImage(ProductInterface $product): string
    {
        $image = (string) $product->getData('image');
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        try {
            $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            ), '/');
            return $baseUrl . '/catalog/product' . $image;
        } catch (\Throwable) {
            return '';
        }
    }

    private function getProductBrand(ProductInterface $product): string
    {
        $brandAttr = $this->config->getBrandAttribute();
        if ($brandAttr === '') {
            return '';
        }

        return $this->getAttributeText($product, $brandAttr);
    }

    private function getAttributeText(ProductInterface $product, string $attributeCode): string
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $text = $product->getAttributeText($attributeCode);
            if (is_array($text)) {
                $text = implode(', ', $text);
            }
            return trim((string) $text);
        } catch (\Throwable) {
            return trim((string) $product->getData($attributeCode));
        }
    }
}
