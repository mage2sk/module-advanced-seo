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

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

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

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        return $filePath;
    }

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

        $brandAttr = $this->config->getBrandAttribute($storeId);
        if ($brandAttr !== '' && $brandAttr !== 'manufacturer') {
            $collection->addAttributeToSelect($brandAttr);
        }

        $gtinAttr = $this->config->getGtinAttribute($storeId);
        if ($gtinAttr !== '') {
            $collection->addAttributeToSelect($gtinAttr);
        }

        $mpnAttr = $this->config->getMpnAttribute($storeId);
        if ($mpnAttr !== '') {
            $collection->addAttributeToSelect($mpnAttr);
        }

        $googleCatAttr = $this->config->getMerchantFeedGoogleCategoryAttribute($storeId);
        if ($googleCatAttr !== '') {
            $collection->addAttributeToSelect($googleCatAttr);
        }

        $collection->addUrlRewrite();
        $collection->addFinalPrice();
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

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

    private function writeProductItem(
        \XMLWriter $xml,
        Product $product,
        StoreInterface $store,
        string $currencyCode
    ): void {
        $storeId = (int) $store->getId();

        $xml->startElement('item');

        $this->writeGElement($xml, 'id', $product->getSku());

        $this->writeGElement($xml, 'title', (string) $product->getName());

        $description = $this->getCleanDescription($product);
        if ($description !== '') {
            $this->writeGElement($xml, 'description', $description);
        }

        $productUrl = $product->getProductUrl();
        if ($productUrl) {
            $this->writeGElement($xml, 'link', $productUrl);
        }

        $imageUrl = $this->getProductImageUrl($product, $store);
        if ($imageUrl !== '') {
            $this->writeGElement($xml, 'image_link', $imageUrl);
        }

        $this->writeAdditionalImages($xml, $product, $store);

        $this->writePriceElements($xml, $product, $currencyCode, $storeId);

        $this->writeGElement($xml, 'availability', $this->getAvailability($product));

        $brand = $this->getBrand($product, $storeId);
        if ($brand !== '') {
            $this->writeGElement($xml, 'brand', $brand);
        }

        $gtin = $this->getProductAttributeValue($product, $this->config->getGtinAttribute($storeId));
        if ($gtin !== '') {
            $this->writeGElement($xml, 'gtin', $gtin);
        }

        $mpn = $this->getProductAttributeValue($product, $this->config->getMpnAttribute($storeId));
        if ($mpn !== '') {
            $this->writeGElement($xml, 'mpn', $mpn);
        }

        $condition = $this->config->getMerchantFeedDefaultCondition($storeId);
        $this->writeGElement($xml, 'condition', $condition);

        $productType = $this->getCategoryBreadcrumb($product, $storeId);
        if ($productType !== '') {
            $this->writeGElement($xml, 'product_type', $productType);
        }

        $googleCatAttr = $this->config->getMerchantFeedGoogleCategoryAttribute($storeId);
        $googleCat = '';
        if ($googleCatAttr !== '') {
            $googleCat = $this->getProductAttributeValue($product, $googleCatAttr);
        }
        if ($googleCat === '') {
            $googleCat = 'Apparel & Accessories > Jewelry';
        }
        $this->writeGElement($xml, 'google_product_category', $googleCat);

        $this->writeShippingElement($xml, $storeId, $currencyCode);

        $this->writeItemGroupId($xml, $product);

        if ($gtin === '' && $mpn === '' && $brand === '') {
            $this->writeGElement($xml, 'identifier_exists', 'false');
        } else {
            $this->writeGElement($xml, 'identifier_exists', 'true');
        }

        $xml->endElement();
    }

    private function writeGElement(\XMLWriter $xml, string $name, string $value): void
    {
        $xml->startElementNs('g', $name, null);
        $xml->text($value);
        $xml->endElement();
    }

    private function getCleanDescription(Product $product): string
    {
        $text = (string) $product->getData('short_description');
        if ($text === '') {
            $text = (string) $product->getData('description');
        }
        if ($text === '') {
            return '';
        }

        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $text = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 3) . '...';
        }

        return $text;
    }

    private function getProductImageUrl(Product $product, StoreInterface $store): string
    {
        $image = $product->getData('image');
        if (empty($image) || $image === 'no_selection') {
            return '';
        }

        $baseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        return $baseUrl . '/catalog/product' . $image;
    }

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
            $this->writeGElement($xml, 'price', $this->formatPrice($regularPrice, $currencyCode));

            $this->writeGElement($xml, 'sale_price', $this->formatPrice($finalPrice, $currencyCode));

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

    private function formatPrice(float $price, string $currencyCode): string
    {
        return number_format($price, 2, '.', '') . ' ' . $currencyCode;
    }

    private function formatSalePriceEffectiveDate(?string $fromDate, ?string $toDate): string
    {
        if (($fromDate === null || $fromDate === '') && ($toDate === null || $toDate === '')) {
            return '';
        }

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

    private function getBrand(Product $product, int $storeId): string
    {
        $brandAttr = $this->config->getBrandAttribute($storeId);
        if ($brandAttr === '') {
            $brandAttr = 'manufacturer';
        }

        $brand = $this->getProductAttributeValue($product, $brandAttr);

        if ($brand === '') {
            $brand = $this->config->getDefaultBrand($storeId);
        }

        return $brand;
    }

    private function getProductAttributeValue(Product $product, string $attributeCode): string
    {
        if ($attributeCode === '') {
            return '';
        }

        $value = $product->getData($attributeCode);
        if ($value === null || $value === '' || $value === false) {
            return '';
        }

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
        }

        return (string) $value;
    }

    private function getCategoryBreadcrumb(Product $product, int $storeId): string
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

        $xml->endElement();
    }

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
        }
    }
}
