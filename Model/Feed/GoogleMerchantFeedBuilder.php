<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Generates a Google Merchant Center compatible XML product feed (RSS 2.0 with g: namespace).
 *
 * Uses streaming XMLWriter for memory efficiency. Products are loaded in batches
 * to keep memory footprint constant regardless of catalog size.
 */
class GoogleMerchantFeedBuilder
{
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';
    private const BATCH_SIZE = 500;
    private const DESCRIPTION_MAX_LENGTH = 5000;
    private const MAX_ADDITIONAL_IMAGES = 10;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly GalleryReadHandler $galleryReadHandler,
        private readonly ConfigurableResource $configurableResource,
        private readonly CurrencyFactory $currencyFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly TimezoneInterface $timezone,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Build the complete Google Shopping XML feed for a given store.
     *
     * @return string The XML content
     */
    public function build(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);
        $currencyCode = $store->getCurrentCurrencyCode();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::GOOGLE_NS);

        $xml->startElement('channel');
        $xml->writeElement('title', (string) $store->getName());
        $xml->writeElement('link', $store->getBaseUrl());
        $xml->writeElement('description', 'Google Shopping product feed for ' . $store->getName());

        $page = 1;
        do {
            $collection = $this->getProductCollection($storeId, $page);
            $products = $collection->getItems();

            foreach ($products as $product) {
                try {
                    $this->writeProductItem($xml, $product, $store, $currencyCode);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        sprintf(
                            'Panth SEO Google Feed: failed to write product SKU "%s": %s',
                            $product->getSku(),
                            $e->getMessage()
                        )
                    );
                }
            }

            $page++;
        } while (count($products) >= self::BATCH_SIZE);

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Build the feed and write directly to a file path for CLI/cron usage.
     *
     * @return string The file path written
     */
    public function buildToFile(int $storeId, string $filePath): string
    {
        $store = $this->storeManager->getStore($storeId);
        $currencyCode = $store->getCurrentCurrencyCode();

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $xml = new \XMLWriter();
        $xml->openUri($filePath);
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::GOOGLE_NS);

        $xml->startElement('channel');
        $xml->writeElement('title', (string) $store->getName());
        $xml->writeElement('link', $store->getBaseUrl());
        $xml->writeElement('description', 'Google Shopping product feed for ' . $store->getName());

        $page = 1;
        do {
            $collection = $this->getProductCollection($storeId, $page);
            $products = $collection->getItems();

            foreach ($products as $product) {
                try {
                    $this->writeProductItem($xml, $product, $store, $currencyCode);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        sprintf(
                            'Panth SEO Google Feed: failed to write product SKU "%s": %s',
                            $product->getSku(),
                            $e->getMessage()
                        )
                    );
                }
            }

            $xml->flush();
            $page++;
        } while (count($products) >= self::BATCH_SIZE);

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();
        $xml->flush();

        return $filePath;
    }

    /**
     * Load a paged product collection with all required attributes.
     */
    private function getProductCollection(int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_BOTH,
        ]]);
        $collection->addAttributeToSelect([
            'name',
            'short_description',
            'description',
            'url_key',
            'image',
            'small_image',
            'price',
            'special_price',
            'special_from_date',
            'special_to_date',
            'manufacturer',
        ]);

        // Add brand attribute if configured differently
        $brandAttr = $this->config->getBrandAttribute($storeId);
        if ($brandAttr !== '' && $brandAttr !== 'manufacturer') {
            $collection->addAttributeToSelect($brandAttr);
        }

        // Add GTIN attribute if configured
        $gtinAttr = $this->config->getGtinAttribute($storeId);
        if ($gtinAttr !== '') {
            $collection->addAttributeToSelect($gtinAttr);
        }

        // Add MPN attribute if configured
        $mpnAttr = $this->config->getMpnAttribute($storeId);
        if ($mpnAttr !== '') {
            $collection->addAttributeToSelect($mpnAttr);
        }

        // Add google product category attribute if configured
        $googleCatAttr = $this->config->getMerchantFeedGoogleCategoryAttribute($storeId);
        if ($googleCatAttr !== '') {
            $collection->addAttributeToSelect($googleCatAttr);
        }

        $collection->addUrlRewrite();
        $collection->addFinalPrice();
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        // Exclude out-of-stock products unless config says include them
        if (!$this->config->isMerchantFeedIncludeOutOfStock($storeId)) {
            $collection->joinField(
                'qty',
                'cataloginventory_stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            );
            $collection->joinField(
                'is_in_stock',
                'cataloginventory_stock_item',
                'is_in_stock',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            );
            $collection->addFieldToFilter('is_in_stock', ['eq' => 1]);
        }

        return $collection;
    }

    /**
     * Write a single <item> element for a product.
     */
    private function writeProductItem(
        \XMLWriter $xml,
        Product $product,
        StoreInterface $store,
        string $currencyCode
    ): void {
        $storeId = (int) $store->getId();

        $xml->startElement('item');

        // g:id - SKU
        $this->writeGElement($xml, 'id', $product->getSku());

        // g:title - product name
        $this->writeGElement($xml, 'title', (string) $product->getName());

        // g:description - short_description stripped of HTML, max 5000 chars
        $description = $this->getCleanDescription($product);
        if ($description !== '') {
            $this->writeGElement($xml, 'description', $description);
        }

        // g:link - product URL
        $productUrl = $product->getProductUrl();
        if ($productUrl) {
            $this->writeGElement($xml, 'link', $productUrl);
        }

        // g:image_link - base image URL
        $imageUrl = $this->getProductImageUrl($product, $store);
        if ($imageUrl !== '') {
            $this->writeGElement($xml, 'image_link', $imageUrl);
        }

        // g:additional_image_link - gallery images
        $this->writeAdditionalImages($xml, $product, $store);

        // g:price - final price with currency
        $this->writePriceElements($xml, $product, $currencyCode, $storeId);

        // g:availability
        $this->writeGElement($xml, 'availability', $this->getAvailability($product));

        // g:brand
        $brand = $this->getBrand($product, $storeId);
        if ($brand !== '') {
            $this->writeGElement($xml, 'brand', $brand);
        }

        // g:gtin
        $gtin = $this->getProductAttributeValue($product, $this->config->getGtinAttribute($storeId));
        if ($gtin !== '') {
            $this->writeGElement($xml, 'gtin', $gtin);
        }

        // g:mpn
        $mpn = $this->getProductAttributeValue($product, $this->config->getMpnAttribute($storeId));
        if ($mpn !== '') {
            $this->writeGElement($xml, 'mpn', $mpn);
        }

        // g:condition
        $condition = $this->config->getMerchantFeedDefaultCondition($storeId);
        $this->writeGElement($xml, 'condition', $condition);

        // g:product_type - category breadcrumb path
        $productType = $this->getCategoryBreadcrumb($product, $storeId);
        if ($productType !== '') {
            $this->writeGElement($xml, 'product_type', $productType);
        }

        // g:google_product_category — always emit something. Fall through to
        // a safe default so the feed is never missing the field entirely.
        $googleCatAttr = $this->config->getMerchantFeedGoogleCategoryAttribute($storeId);
        $googleCat = '';
        if ($googleCatAttr !== '') {
            $googleCat = $this->getProductAttributeValue($product, $googleCatAttr);
        }
        if ($googleCat === '') {
            $googleCat = 'Apparel & Accessories > Jewelry';
        }
        $this->writeGElement($xml, 'google_product_category', $googleCat);

        // g:shipping
        $this->writeShippingElement($xml, $storeId, $currencyCode);

        // g:item_group_id - parent SKU for configurable children
        $this->writeItemGroupId($xml, $product);

        // g:identifier_exists — explicit true when at least one identifier is
        // present, false when none are. Google recommends emitting it either
        // way so the feed is unambiguous.
        if ($gtin === '' && $mpn === '' && $brand === '') {
            $this->writeGElement($xml, 'identifier_exists', 'false');
        } else {
            $this->writeGElement($xml, 'identifier_exists', 'true');
        }

        $xml->endElement(); // item
    }

    /**
     * Write a g: namespaced element.
     */
    private function writeGElement(\XMLWriter $xml, string $name, string $value): void
    {
        $xml->startElementNs('g', $name, null);
        $xml->text($value);
        $xml->endElement();
    }

    /**
     * Get clean description from short_description, fallback to description.
     */
    private function getCleanDescription(Product $product): string
    {
        $text = (string) $product->getData('short_description');
        if ($text === '') {
            $text = (string) $product->getData('description');
        }
        if ($text === '') {
            return '';
        }

        // Strip HTML tags
        $text = strip_tags($text);
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Truncate to max length
        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $text = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 3) . '...';
        }

        return $text;
    }

    /**
     * Get the full URL for the product base image.
     */
    private function getProductImageUrl(Product $product, StoreInterface $store): string
    {
        $image = $product->getData('image');
        if (empty($image) || $image === 'no_selection') {
            return '';
        }

        $baseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        return $baseUrl . '/catalog/product' . $image;
    }

    /**
     * Write additional image link elements from product gallery.
     */
    private function writeAdditionalImages(\XMLWriter $xml, Product $product, StoreInterface $store): void
    {
        try {
            $this->galleryReadHandler->execute($product);
        } catch (\Throwable) {
            return;
        }

        $gallery = $product->getMediaGalleryImages();
        if ($gallery === null || $gallery->getSize() === 0) {
            return;
        }

        $baseImage = $product->getData('image');
        $count = 0;

        foreach ($gallery as $image) {
            if ($count >= self::MAX_ADDITIONAL_IMAGES) {
                break;
            }

            $file = $image->getData('file') ?? $image->getData('value');
            if (empty($file) || $file === $baseImage) {
                continue;
            }

            $baseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
            $imageUrl = $baseUrl . '/catalog/product' . $file;
            $this->writeGElement($xml, 'additional_image_link', $imageUrl);
            $count++;
        }
    }

    /**
     * Write price, sale_price, and sale_price_effective_date elements.
     */
    private function writePriceElements(
        \XMLWriter $xml,
        Product $product,
        string $currencyCode,
        int $storeId
    ): void {
        $regularPrice = (float) $product->getData('price');
        $specialPrice = $product->getData('special_price');
        $finalPrice = (float) $product->getFinalPrice();

        $now = $this->timezone->date(null, null, true);
        $hasActiveSpecialPrice = false;

        if ($specialPrice !== null && (float) $specialPrice > 0 && (float) $specialPrice < $regularPrice) {
            $specialFromDate = $product->getData('special_from_date');
            $specialToDate = $product->getData('special_to_date');

            $fromValid = ($specialFromDate === null || $specialFromDate === '')
                || $this->timezone->date($specialFromDate, null, true) <= $now;
            $toValid = ($specialToDate === null || $specialToDate === '')
                || $this->timezone->date($specialToDate, null, true) >= $now;

            if ($fromValid && $toValid) {
                $hasActiveSpecialPrice = true;
            }
        }

        if ($hasActiveSpecialPrice) {
            // g:price shows the regular price
            $this->writeGElement($xml, 'price', $this->formatPrice($regularPrice, $currencyCode));
            // g:sale_price shows the special/final price
            $this->writeGElement($xml, 'sale_price', $this->formatPrice($finalPrice, $currencyCode));

            // g:sale_price_effective_date in ISO 8601 format
            $specialFromDate = $product->getData('special_from_date');
            $specialToDate = $product->getData('special_to_date');
            $effectiveDate = $this->formatSalePriceEffectiveDate($specialFromDate, $specialToDate);
            if ($effectiveDate !== '') {
                $this->writeGElement($xml, 'sale_price_effective_date', $effectiveDate);
            }
        } else {
            $this->writeGElement($xml, 'price', $this->formatPrice($finalPrice, $currencyCode));
        }
    }

    /**
     * Format a price value with currency code: "29.99 USD"
     */
    private function formatPrice(float $price, string $currencyCode): string
    {
        return number_format($price, 2, '.', '') . ' ' . $currencyCode;
    }

    /**
     * Format sale_price_effective_date as ISO 8601 range.
     */
    private function formatSalePriceEffectiveDate(?string $fromDate, ?string $toDate): string
    {
        if (($fromDate === null || $fromDate === '') && ($toDate === null || $toDate === '')) {
            return '';
        }

        // Google's spec for sale_price_effective_date is ISO 8601 minute
        // resolution (no seconds) and requires BOTH endpoints — partial ranges
        // ("from/" or "/to") are rejected. Format `Y-m-d\TH:iO` matches.
        $from = ($fromDate !== null && $fromDate !== '')
            ? $this->timezone->date($fromDate, null, true)->format('Y-m-d\TH:iO')
            : '';
        $to = ($toDate !== null && $toDate !== '')
            ? $this->timezone->date($toDate, null, true)->format('Y-m-d\TH:iO')
            : '';

        if ($from !== '' && $to !== '') {
            return $from . '/' . $to;
        }

        return '';
    }

    /**
     * Determine product availability for Google's enumeration.
     */
    private function getAvailability(Product $product): string
    {
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
        } catch (\Throwable) {
            return 'out_of_stock';
        }

        if (!$stockItem->getIsInStock()) {
            return 'out_of_stock';
        }

        $backorders = (int) $stockItem->getBackorders();
        if ($backorders > 0 && (float) $stockItem->getQty() <= 0) {
            return 'backorder';
        }

        return 'in_stock';
    }

    /**
     * Get brand value from configured attribute.
     */
    private function getBrand(Product $product, int $storeId): string
    {
        $brandAttr = $this->config->getBrandAttribute($storeId);
        if ($brandAttr === '') {
            $brandAttr = 'manufacturer';
        }

        $brand = $this->getProductAttributeValue($product, $brandAttr);

        // Fall back to panth_seo/structured_data/default_brand when the
        // configured product attribute is empty, so feeds for stores with a
        // single brand (typical for jewellery / single-brand boutiques) don't
        // ship empty <g:brand> entries.
        if ($brand === '') {
            $brand = $this->config->getDefaultBrand($storeId);
        }

        return $brand;
    }

    /**
     * Get a product attribute value, resolving select/multiselect to text.
     */
    private function getProductAttributeValue(Product $product, string $attributeCode): string
    {
        if ($attributeCode === '') {
            return '';
        }

        $value = $product->getData($attributeCode);
        if ($value === null || $value === '' || $value === false) {
            return '';
        }

        // Try to get the text representation for select/multiselect
        try {
            $textValue = $product->getAttributeText($attributeCode);
            if (is_string($textValue) && $textValue !== '') {
                return $textValue;
            }
            if (is_array($textValue)) {
                $filtered = array_filter($textValue, static fn ($v) => $v !== '' && $v !== null);
                if (!empty($filtered)) {
                    return implode(', ', $filtered);
                }
            }
        } catch (\Throwable) {
            // Not a select attribute; fall through to raw value
        }

        return (string) $value;
    }

    /**
     * Build category breadcrumb path, e.g. "Apparel > Women > Dresses".
     */
    private function getCategoryBreadcrumb(Product $product, int $storeId): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return '';
        }

        // Pick the deepest category (highest number of path parts)
        $deepestPath = '';
        $deepestDepth = 0;

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
                $pathIds = explode('/', (string) $category->getPath());

                // Skip root categories (depth < 2 means it's the root or default)
                if (count($pathIds) <= 2) {
                    continue;
                }

                if (count($pathIds) > $deepestDepth) {
                    $deepestDepth = count($pathIds);
                    $names = [];
                    // Skip the first two (root and default category)
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
     * Write shipping element if shipping country and price are configured.
     */
    private function writeShippingElement(\XMLWriter $xml, int $storeId, string $currencyCode): void
    {
        $country = $this->config->getMerchantFeedShippingCountry($storeId);
        $price = $this->config->getMerchantFeedShippingPrice($storeId);

        if ($country === '' || $price === '') {
            return;
        }

        $xml->startElementNs('g', 'shipping', null);

        $xml->startElementNs('g', 'country', null);
        $xml->text($country);
        $xml->endElement();

        $xml->startElementNs('g', 'price', null);
        $xml->text(number_format((float) $price, 2, '.', '') . ' ' . $currencyCode);
        $xml->endElement();

        $xml->endElement(); // g:shipping
    }

    /**
     * Write g:item_group_id for configurable product children.
     */
    private function writeItemGroupId(\XMLWriter $xml, Product $product): void
    {
        if ($product->getTypeId() !== Type::DEFAULT_TYPE) {
            return;
        }

        try {
            $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
            if (!empty($parentIds)) {
                $parentId = reset($parentIds);
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()
                    ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['sku'])
                    ->where('entity_id = ?', $parentId);
                $parentSku = $connection->fetchOne($select);
                if ($parentSku) {
                    $this->writeGElement($xml, 'item_group_id', (string) $parentSku);
                }
            }
        } catch (\Throwable) {
            // Not a child of a configurable; skip
        }
    }
}
