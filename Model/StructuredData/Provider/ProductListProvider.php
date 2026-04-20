<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits an `ItemList` schema.org node on category pages containing `ListItem`
 * entries for each product in the current product listing.
 *
 * Only active when:
 *  - The request is a category page (current_category is set).
 *  - Config `panth_seo/structured_data/enable_product_list_schema` is enabled.
 *
 * The list is capped at 20 items to keep the JSON-LD payload small and avoid
 * excessive processing on large categories.
 */
class ProductListProvider extends AbstractProvider
{
    /**
     * Maximum number of products to include in the ItemList.
     */
    private const MAX_ITEMS = 20;

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly LayerResolver $layerResolver
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'productList';
    }

    public function isApplicable(): bool
    {
        if ($this->getCurrentCategory() === null) {
            return false;
        }

        return $this->config->isProductListSchemaEnabled();
    }

    public function getJsonLd(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $items = $this->buildListItems();
        if ($items === []) {
            return [];
        }

        $categoryUrl = (string) $category->getUrl();

        return [
            '@type'           => 'ItemList',
            '@id'             => $categoryUrl . '#item-list',
            'name'            => (string) $category->getName(),
            'url'             => $categoryUrl,
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
    }

    /**
     * Build ListItem entries from the current category product collection.
     *
     * @return list<array<string,mixed>>
     */
    private function buildListItems(): array
    {
        try {
            $layer      = $this->layerResolver->get();
            $collection = $layer->getProductCollection();
            $collection->setPageSize(self::MAX_ITEMS);
            $collection->setCurPage(1);
            // Force DISTINCT + explicit load under try/catch — the layer
            // collection can contain duplicate entity_id rows (stock / index
            // joins on multi-source catalogs), which trips the collection's
            // "Item with the same ID already exists" guard and 500s the page.
            $collection->getSelect()->distinct(true);
            $products = $collection->getItems();
        } catch (\Throwable) {
            return [];
        }

        $items    = [];
        $position = 1;

        foreach ($products as $product) {
            $name = (string) $product->getName();
            $url  = (string) $product->getProductUrl();

            if ($name === '' || $url === '') {
                continue;
            }

            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'url'      => $url,
                'name'     => $name,
            ];

            $position++;

            if ($position > self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }
}
