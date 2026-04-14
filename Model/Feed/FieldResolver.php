<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a single field value for a product based on feed field configuration.
 *
 * Supports source types: attribute, static, template, parent_attribute.
 */
class FieldResolver
{
    private const DESCRIPTION_MAX_LENGTH = 5000;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve a field value for a product based on field configuration.
     *
     * @param array $fieldConfig Field mapping row with keys: source_type, source_value, default_value
     * @param ProductInterface&Product $product The product to resolve from
     * @param int $storeId Store context
     * @param (ProductInterface&Product)|null $parent Parent product for parent_attribute resolution
     * @return string Resolved value
     */
    public function resolve(
        array $fieldConfig,
        ProductInterface $product,
        int $storeId,
        ?ProductInterface $parent = null
    ): string {
        $sourceType = $fieldConfig['source_type'] ?? '';
        $sourceValue = $fieldConfig['source_value'] ?? '';
        $defaultValue = $fieldConfig['default_value'] ?? '';

        $resolved = '';

        try {
            $resolved = match ($sourceType) {
                'attribute' => $this->resolveAttribute($product, $sourceValue),
                'static' => $sourceValue,
                'template' => $this->resolveTemplate($sourceValue, $product, $storeId),
                'parent_attribute' => $this->resolveParentAttribute($sourceValue, $product, $parent),
                default => '',
            };
        } catch (\Throwable $e) {
            $this->logger->debug(sprintf(
                'Panth SEO Feed FieldResolver: failed to resolve field "%s" for SKU "%s": %s',
                $fieldConfig['feed_field'] ?? 'unknown',
                $product->getSku(),
                $e->getMessage()
            ));
        }

        // Apply default value if resolved is empty
        if ($resolved === '' && $defaultValue !== '' && $defaultValue !== null) {
            $resolved = $defaultValue;
        }

        return $resolved;
    }

    /**
     * Resolve a product attribute value, handling select/multiselect option labels.
     */
    private function resolveAttribute(Product $product, string $attributeCode): string
    {
        if ($attributeCode === '') {
            return '';
        }

        $value = $product->getData($attributeCode);
        if ($value === null || $value === '' || $value === false) {
            return '';
        }

        // For description fields, strip HTML
        if (in_array($attributeCode, ['description', 'short_description'], true)) {
            return $this->stripHtml((string) $value);
        }

        // Resolve select/multiselect to text labels
        try {
            $textValue = $product->getAttributeText($attributeCode);
            if (is_string($textValue) && $textValue !== '') {
                return $textValue;
            }
            if (is_array($textValue)) {
                $filtered = array_filter($textValue, static fn($v) => $v !== '' && $v !== null);
                if (!empty($filtered)) {
                    return implode(', ', $filtered);
                }
            }
        } catch (\Throwable) {
            // Not a select attribute, use raw value
        }

        return (string) $value;
    }

    /**
     * Resolve a template token to a computed value.
     */
    private function resolveTemplate(string $token, Product $product, int $storeId): string
    {
        return match ($token) {
            'product_url' => $this->getProductUrl($product),
            'product_image_url' => $this->getProductImageUrl($product, $storeId),
            'product_price' => $this->getProductPrice($product, $storeId),
            'product_special_price' => $this->getProductSpecialPrice($product, $storeId),
            'stock_status' => $this->getStockStatus($product),
            'category_path' => $this->getCategoryPath($product, $storeId),
            'product_weight' => $this->getProductWeight($product),
            default => '',
        };
    }

    /**
     * Resolve from parent product if available, otherwise fall back to product itself.
     */
    private function resolveParentAttribute(
        string $attributeCode,
        Product $product,
        ?ProductInterface $parent
    ): string {
        if ($parent instanceof Product) {
            $value = $this->resolveAttribute($parent, $attributeCode);
            if ($value !== '') {
                return $value;
            }
        }
        // Fallback to the product itself
        return $this->resolveAttribute($product, $attributeCode);
    }

    /**
     * Get canonical product URL.
     */
    private function getProductUrl(Product $product): string
    {
        try {
            $url = $product->getProductUrl();
            return $url ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get full media URL for the product's main image.
     */
    private function getProductImageUrl(Product $product, int $storeId): string
    {
        $image = $product->getData('image');
        if (empty($image) || $image === 'no_selection') {
            return '';
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim(
                $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );
            return $baseUrl . '/catalog/product' . $image;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get formatted price with currency, e.g. "34.00 USD".
     */
    private function getProductPrice(Product $product, int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            $currencyCode = $store->getCurrentCurrencyCode();
            $price = (float) $product->getFinalPrice();
            return number_format($price, 2, '.', '') . ' ' . $currencyCode;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get formatted special/sale price if active, e.g. "29.99 USD".
     */
    private function getProductSpecialPrice(Product $product, int $storeId): string
    {
        try {
            $regularPrice = (float) $product->getData('price');
            $specialPrice = $product->getData('special_price');

            if ($specialPrice === null || (float) $specialPrice <= 0 || (float) $specialPrice >= $regularPrice) {
                return '';
            }

            $now = $this->timezone->date(null, null, true);
            $fromDate = $product->getData('special_from_date');
            $toDate = $product->getData('special_to_date');

            $fromValid = ($fromDate === null || $fromDate === '')
                || $this->timezone->date($fromDate, null, true) <= $now;
            $toValid = ($toDate === null || $toDate === '')
                || $this->timezone->date($toDate, null, true) >= $now;

            if (!$fromValid || !$toValid) {
                return '';
            }

            $store = $this->storeManager->getStore($storeId);
            $currencyCode = $store->getCurrentCurrencyCode();
            return number_format((float) $specialPrice, 2, '.', '') . ' ' . $currencyCode;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Get stock status: "in_stock" or "out_of_stock".
     */
    private function getStockStatus(Product $product): string
    {
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
            return $stockItem->getIsInStock() ? 'in_stock' : 'out_of_stock';
        } catch (\Throwable) {
            return 'out_of_stock';
        }
    }

    /**
     * Get deepest category path, e.g. "Root > Gear > Bags".
     */
    private function getCategoryPath(Product $product, int $storeId): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return '';
        }

        $deepestPath = '';
        $deepestDepth = 0;

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
                $pathIds = explode('/', (string) $category->getPath());

                if (count($pathIds) <= 2) {
                    continue;
                }

                if (count($pathIds) > $deepestDepth) {
                    $deepestDepth = count($pathIds);
                    $names = [];
                    $relevantIds = array_slice($pathIds, 2);
                    foreach ($relevantIds as $pathCatId) {
                        try {
                            $pathCat = $this->categoryRepository->get((int) $pathCatId, $storeId);
                            $catName = (string) $pathCat->getName();
                            if ($catName !== '') {
                                $names[] = $catName;
                            }
                        } catch (\Throwable) {
                            continue;
                        }
                    }
                    if (!empty($names)) {
                        $deepestPath = implode(' > ', $names);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $deepestPath;
    }

    /**
     * Get product weight with unit.
     */
    private function getProductWeight(Product $product): string
    {
        $weight = $product->getData('weight');
        if ($weight === null || $weight === '' || (float) $weight <= 0) {
            return '';
        }

        return number_format((float) $weight, 2, '.', '') . ' lbs';
    }

    /**
     * Strip HTML tags and normalize whitespace from text.
     */
    private function stripHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $text = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 3) . '...';
        }

        return $text;
    }
}
