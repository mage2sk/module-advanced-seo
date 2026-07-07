<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Ui\DataProvider\AbstractDataProvider;

class GenericFormDataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        AbstractCollection $collection,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collection;
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

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [];
        }

        return $this->loadedData;
    }
}
