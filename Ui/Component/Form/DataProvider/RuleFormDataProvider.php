<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\CollectionFactory;

class RuleFormDataProvider extends AbstractDataProvider
{
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
            $this->decodeActions($itemData);
            $this->loadedData[$item->getId()] = $itemData;
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'entity_type' => 'product',
                'is_active' => '1',
                'priority' => '10',
                'store_id' => '0',
                'stop_on_match' => '0',
            ];
        }

        return $this->loadedData;
    }

    private function decodeConditions(array &$data): void
    {
        $raw = $data['conditions_serialized'] ?? '';
        if ($raw === '' || $raw === '{}' || $raw === '[]') {
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

        $first = $conditions[0] ?? null;
        if (!is_array($first)) {
            return;
        }

        $data['condition_attribute'] = $first['attribute'] ?? '';
        $data['condition_value'] = $first['value'] ?? '';
    }

    private function decodeActions(array &$data): void
    {
        $raw = $data['actions_serialized'] ?? '';
        if ($raw === '' || $raw === '{}' || $raw === '[]') {
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

        if (!empty($decoded['noindex'])) {
            $data['action_noindex'] = $decoded['noindex'];
        }
        if (!empty($decoded['title_template'])) {
            $data['action_title_template'] = $decoded['title_template'];
        }
        if (!empty($decoded['description_template'])) {
            $data['action_description_template'] = $decoded['description_template'];
        }
        if (!empty($decoded['canonical'])) {
            $data['action_canonical'] = $decoded['canonical'];
        }
    }
}
