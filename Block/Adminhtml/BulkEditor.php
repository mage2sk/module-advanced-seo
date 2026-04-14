<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class BulkEditor extends Template
{
    protected $_template = 'Panth_AdvancedSEO::bulk_editor.phtml';

    public function __construct(
        Context $context,
        private readonly ProductCollectionFactory $productCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getProducts(int $limit = 50, int $page = 1): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'meta_title', 'meta_description', 'meta_keyword']);
        $collection->setPageSize($limit);
        $collection->setCurPage($page);

        $rows = [];
        foreach ($collection as $product) {
            $rows[] = [
                'entity_id' => (int)$product->getId(),
                'name' => (string)$product->getName(),
                'sku' => (string)$product->getSku(),
                'meta_title' => (string)$product->getMetaTitle(),
                'meta_description' => (string)$product->getMetaDescription(),
                'meta_keyword' => (string)$product->getMetaKeyword(),
            ];
        }
        return $rows;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('panth_seo/bulkeditor/save');
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('panth_seo/bulkeditor/massGenerate');
    }
}
