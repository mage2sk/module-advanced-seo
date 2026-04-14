<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

class MissingMetaDataProvider extends AbstractDataProvider
{
    private const PRODUCT_EAV = ['name', 'meta_title', 'meta_description'];
    private const CATEGORY_EAV = ['name', 'meta_title', 'meta_description'];

    private bool $initialized = false;
    private string $entityType = 'product';

    private array $attrSetNames = [];

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly RequestInterface $request,
        private readonly BackendSession $backendSession,
        private readonly ResourceConnection $resourceConnection,
        array $meta = [],
        array $data = []
    ) {
        // Create a dummy product collection — will be replaced in initCollection()
        $this->collection = $this->productCollectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    private function resolveEntityType(): string
    {
        // 1. Check request param (direct page load and AJAX with forwarded params)
        $type = (string)$this->request->getParam('type', '');
        if (in_array($type, ['product', 'category'], true)) {
            // Store in session for future AJAX calls
            $this->backendSession->setData('panth_seo_missing_meta_type', $type);
            return $type;
        }

        // 2. Check the HTTP referer URL for the type param (AJAX calls from mui/index/render)
        $referer = (string)($this->request->getServer('HTTP_REFERER') ?? '');
        if ($referer !== '' && preg_match('#/type/(product|category)(?:/|$)#', $referer, $m)) {
            $resolved = $m[1];
            $this->backendSession->setData('panth_seo_missing_meta_type', $resolved);
            return $resolved;
        }

        // 2b. Also check the original request URI (some setups forward the full URI)
        $uri = (string)($this->request->getRequestUri() ?? '');
        if ($uri !== '' && preg_match('#/type/(product|category)(?:/|$)#', $uri, $m)) {
            $this->backendSession->setData('panth_seo_missing_meta_type', $m[1]);
            return $m[1];
        }

        // 3. Fallback to session
        $sessionType = (string)($this->backendSession->getData('panth_seo_missing_meta_type') ?? 'product');
        return in_array($sessionType, ['product', 'category'], true) ? $sessionType : 'product';
    }

    private function initCollection(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        $this->entityType = $this->resolveEntityType();

        if ($this->entityType === 'category') {
            $this->collection = $this->categoryCollectionFactory->create();
            $this->collection->addAttributeToSelect(self::CATEGORY_EAV);
            $this->collection->addFieldToSelect(['path', 'level']);
            $this->collection->addFieldToFilter('level', ['gt' => 1]);
        } else {
            $this->collection = $this->productCollectionFactory->create();
            $this->collection->addAttributeToSelect(self::PRODUCT_EAV);
        }

        // Apply missing meta filter
        $this->collection->addAttributeToFilter([
            ['attribute' => 'meta_title', 'null' => true],
            ['attribute' => 'meta_title', 'eq' => ''],
            ['attribute' => 'meta_description', 'null' => true],
            ['attribute' => 'meta_description', 'eq' => ''],
        ]);
    }

    public function getData(): array
    {
        $this->initCollection();

        $items = [];
        foreach ($this->collection->getItems() as $item) {
            $row = $item->getData();

            if ($this->entityType === 'category') {
                $items[] = [
                    'entity_id' => (int)($row['entity_id'] ?? 0),
                    'sku' => '',
                    'name' => (string)($row['name'] ?? ''),
                    'meta_title' => (string)($row['meta_title'] ?? ''),
                    'meta_description' => (string)($row['meta_description'] ?? ''),
                    'type_id' => 'Level ' . (string)($row['level'] ?? ''),
                    'attribute_set_id' => $this->getCategoryPath($item),
                ];
            } else {
                $items[] = [
                    'entity_id' => (int)($row['entity_id'] ?? 0),
                    'sku' => (string)($row['sku'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'meta_title' => (string)($row['meta_title'] ?? ''),
                    'meta_description' => (string)($row['meta_description'] ?? ''),
                    'type_id' => (string)($row['type_id'] ?? ''),
                    'attribute_set_id' => $this->getAttributeSetName((int)($row['attribute_set_id'] ?? 0)),
                ];
            }
        }

        return [
            'totalRecords' => $this->collection->getSize(),
            'items' => $items,
        ];
    }

    private function getAttributeSetName(int $id): string
    {
        if ($id === 0) {
            return '';
        }
        if (!isset($this->attrSetNames[$id])) {
            try {
                $conn = $this->resourceConnection->getConnection();
                $name = $conn->fetchOne(
                    "SELECT attribute_set_name FROM eav_attribute_set WHERE attribute_set_id = ?",
                    [$id]
                );
                $this->attrSetNames[$id] = $name ?: (string)$id;
            } catch (\Throwable) {
                $this->attrSetNames[$id] = (string)$id;
            }
        }
        return $this->attrSetNames[$id];
    }

    private function getCategoryPath($category): string
    {
        try {
            $path = $category->getData('path') ?? '';
            if ($path === '') {
                return '';
            }
            $pathIds = explode('/', $path);
            // Remove root (1) and default category (2)
            $pathIds = array_filter($pathIds, fn($id) => (int)$id > 2);
            if (empty($pathIds)) {
                return 'Root';
            }
            $conn = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
            $attrId = $conn->fetchOne(
                "SELECT attribute_id FROM eav_attribute WHERE entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category') AND attribute_code = 'name'"
            );
            if (!$attrId) {
                return implode(' / ', $pathIds);
            }
            $names = $conn->fetchPairs(
                "SELECT entity_id, value FROM {$table} WHERE attribute_id = ? AND store_id = 0 AND entity_id IN (" . implode(',', array_map('intval', $pathIds)) . ")",
                [$attrId]
            );
            $result = [];
            foreach ($pathIds as $id) {
                $result[] = $names[(int)$id] ?? 'ID:' . $id;
            }
            return implode(' > ', $result);
        } catch (\Throwable) {
            return '';
        }
    }

    public function addFilter(Filter $filter): void
    {
        $this->initCollection();
        $field = $filter->getField();
        $eavFields = $this->entityType === 'category' ? self::CATEGORY_EAV : self::PRODUCT_EAV;

        if (in_array($field, $eavFields, true)) {
            $this->collection->addAttributeToFilter($field, [$filter->getConditionType() => $filter->getValue()]);
        } elseif ($field === 'entity_id' || $field === 'sku' || $field === 'type_id' || $field === 'attribute_set_id') {
            $this->collection->addFieldToFilter($field, [$filter->getConditionType() => $filter->getValue()]);
        }
    }

    public function addOrder($field, $direction): void
    {
        $this->initCollection();
        $eavFields = $this->entityType === 'category' ? self::CATEGORY_EAV : self::PRODUCT_EAV;

        if (in_array($field, $eavFields, true)) {
            $this->collection->addAttributeToSort($field, $direction);
        } else {
            $this->collection->setOrder($field, $direction);
        }
    }
}
