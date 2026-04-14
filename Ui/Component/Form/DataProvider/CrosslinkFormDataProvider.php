<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\Crosslink\CollectionFactory;

class CrosslinkFormDataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        $items = $this->collection->getItems();

        foreach ($items as $item) {
            $this->loadedData[$item->getId()] = $item->getData();
        }

        // For new entities, provide empty defaults keyed by empty string
        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'is_active'        => 1,
                'in_product'       => 1,
                'in_category'      => 1,
                'in_cms'           => 1,
                'max_replacements' => 1,
                'nofollow'         => 0,
                'priority'         => 0,
                'store_id'         => 0,
            ];
        }

        return $this->loadedData;
    }
}
