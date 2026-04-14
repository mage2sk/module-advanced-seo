<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class BulkEditorDataProvider extends AbstractDataProvider
{
    private string $entityType;
    private bool $collectionInitialized = false;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
        private readonly BackendSession $backendSession,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        // Read type from request first (direct URL param), then session, then referer
        $type = (string) $this->request->getParam('type', '');
        if (!in_array($type, ['product', 'category', 'cms'], true)) {
            // Try parsing from HTTP referer (for AJAX grid reload)
            $referer = (string) ($this->request->getServer('HTTP_REFERER') ?? '');
            if (preg_match('#/type/(product|category|cms)(?:/|$)#', $referer, $m)) {
                $type = $m[1];
            }
        }
        if (!in_array($type, ['product', 'category', 'cms'], true)) {
            $type = (string) ($this->backendSession->getData('panth_seo_bulkeditor_type') ?? 'product');
        }
        if (!in_array($type, ['product', 'category', 'cms'], true)) {
            $type = 'product';
        }
        $this->entityType = $type;
        $this->backendSession->setData('panth_seo_bulkeditor_type', $type);
        $this->collection = $this->createCollection();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    private function createCollection(): mixed
    {
        return match ($this->entityType) {
            'category' => $this->createCategoryCollection(),
            'cms' => $this->createCmsCollection(),
            default => $this->createProductCollection(),
        };
    }

    private function createProductCollection(): mixed
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'meta_title', 'meta_description', 'meta_keyword']);
        return $collection;
    }

    private function createCategoryCollection(): mixed
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'meta_title', 'meta_description', 'meta_keywords']);
        $collection->addFieldToFilter('level', ['gteq' => 2]);
        return $collection;
    }

    private function createCmsCollection(): mixed
    {
        $collection = $this->cmsPageCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        return $collection;
    }

    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection() as $entity) {
            // Unify data keys to match the UI component's visible columns
            // ("sku" column is labelled "SKU / URL Key / Identifier",
            //  "name" column is labelled "Name / Title"). The data provider
            // aliases the per-entity-type source fields to these keys so the
            // same columns render for all three tabs.
            switch ($this->entityType) {
                case 'category':
                    $items[] = [
                        'entity_id' => (int) $entity->getId(),
                        'sku' => (string) ($entity->getData('url_key') ?? ''),
                        'url_key' => (string) ($entity->getData('url_key') ?? ''),
                        'name' => (string) ($entity->getData('name') ?? ''),
                        'meta_title' => (string) ($entity->getData('meta_title') ?? ''),
                        'meta_description' => (string) ($entity->getData('meta_description') ?? ''),
                    ];
                    break;
                case 'cms':
                    $items[] = [
                        'entity_id' => (int) $entity->getId(),
                        'sku' => (string) ($entity->getData('identifier') ?? ''),
                        'identifier' => (string) ($entity->getData('identifier') ?? ''),
                        'name' => (string) ($entity->getData('title') ?? ''),
                        'title' => (string) ($entity->getData('title') ?? ''),
                        'meta_title' => (string) ($entity->getData('meta_title') ?? ''),
                        'meta_description' => (string) ($entity->getData('meta_description') ?? ''),
                    ];
                    break;
                default:
                    $items[] = [
                        'entity_id' => (int) $entity->getId(),
                        'sku' => (string) ($entity->getData('sku') ?? ''),
                        'name' => (string) ($entity->getData('name') ?? ''),
                        'meta_title' => (string) ($entity->getData('meta_title') ?? ''),
                        'meta_description' => (string) ($entity->getData('meta_description') ?? ''),
                    ];
                    break;
            }
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    public function addFilter(Filter $filter): void
    {
        $field = $filter->getField();

        if ($field === 'entity_id') {
            $idField = $this->entityType === 'cms' ? 'page_id' : 'entity_id';
            $this->getCollection()->addFieldToFilter(
                $idField,
                [$filter->getConditionType() => $filter->getValue()]
            );
            return;
        }

        if ($field === 'store_id') {
            $storeId = (int) $filter->getValue();
            if ($storeId > 0 && $this->entityType !== 'cms') {
                $this->getCollection()->setStoreId($storeId);
            }
            return;
        }

        $eavFields = match ($this->entityType) {
            'category' => ['name', 'url_key', 'meta_title', 'meta_description'],
            'cms' => ['title', 'identifier', 'meta_title', 'meta_description'],
            default => ['name', 'sku', 'meta_title', 'meta_description'],
        };

        if (in_array($field, $eavFields, true)) {
            if ($this->entityType === 'cms') {
                $this->getCollection()->addFieldToFilter(
                    $field,
                    [$filter->getConditionType() => $filter->getValue()]
                );
            } else {
                $this->getCollection()->addAttributeToFilter(
                    $field,
                    [$filter->getConditionType() => $filter->getValue()]
                );
            }
            return;
        }

        parent::addFilter($filter);
    }

    public function addOrder($field, $direction)
    {
        if ($field === 'entity_id') {
            $idField = $this->entityType === 'cms' ? 'page_id' : 'entity_id';
            $this->getCollection()->setOrder($idField, $direction);
            return;
        }

        $eavFields = match ($this->entityType) {
            'category' => ['name', 'url_key', 'meta_title', 'meta_description'],
            default => ['name', 'sku', 'meta_title', 'meta_description'],
        };

        if (in_array($field, $eavFields, true)) {
            if ($this->entityType === 'cms') {
                $this->getCollection()->setOrder($field, $direction);
            } else {
                $this->getCollection()->addAttributeToSort($field, $direction);
            }
            return;
        }

        parent::addOrder($field, $direction);
    }
}
