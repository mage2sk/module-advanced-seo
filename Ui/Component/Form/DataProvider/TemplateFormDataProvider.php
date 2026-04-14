<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory;

class TemplateFormDataProvider extends AbstractDataProvider
{
    /**
     * @var array|null
     */
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly SerializerInterface $serializer,
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
            $itemData = $item->getData();
            $this->decodeConditions($itemData);
            $this->loadedData[$item->getId()] = $itemData;
        }

        // For new entities, provide empty defaults keyed by empty string
        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'entity_type' => 'product',
                'scope' => 'default',
                'is_active' => '1',
                'priority' => '10',
                'store_id' => '0',
            ];
        }

        return $this->loadedData;
    }

    /**
     * Extract condition_attribute and condition_value from conditions_serialized
     * so the form fields are populated on edit.
     */
    private function decodeConditions(array &$data): void
    {
        $raw = $data['conditions_serialized'] ?? '';
        if ($raw === '' || $raw === '{}') {
            return;
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\Throwable) {
            return;
        }

        if (!is_array($decoded)) {
            return;
        }

        $conditions = $decoded['conditions'] ?? [];
        if (!is_array($conditions) || $conditions === []) {
            return;
        }

        // Take the first condition for the simple UI fields
        $first = $conditions[0] ?? null;
        if (!is_array($first)) {
            return;
        }

        $data['condition_attribute'] = $first['attribute'] ?? '';
        $data['condition_value'] = $first['value'] ?? '';
    }
}
