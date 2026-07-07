<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\FeedProfile\CollectionFactory;

class FeedFormDataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly SerializerInterface $serializer,
        private readonly StoreManagerInterface $storeManager,
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
            $this->decodeJsonFields($itemData);
            $this->convertMultiselectFields($itemData);
            $this->computeFeedUrl($itemData);
            $this->loadedData[$item->getId()] = $itemData;
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'feed_type'     => 'google_shopping',
                'store_id'      => '1',
                'output_format' => 'xml',
                'is_active'     => '1',
                'filename'      => 'google_feed.xml',
                'delivery_country' => 'US',
                'currency'      => 'USD',
                'cron_enabled'  => '0',
                'cron_schedule' => '0 1 * * *',
                'include_out_of_stock' => '0',
                'include_disabled' => '0',
                'include_not_visible' => '0',
                'delivery_enabled' => '0',
                'delivery_type' => 'ftp',
                'delivery_passive_mode' => '1',
                'compress'      => '',
            ];
        }

        return $this->loadedData;
    }

    private function convertMultiselectFields(array &$data): void
    {
        foreach (['category_ids', 'attribute_set_ids'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $data[$field] = explode(',', $value);
            }
        }
    }

    private function computeFeedUrl(array &$data): void
    {
        $filename = trim((string) ($data['filename'] ?? ''));
        if ($filename === '') {
            return;
        }

        try {
            $storeId = (int) ($data['store_id'] ?? 1);
            if ($storeId === 0) {
                $storeId = 1;
            }
            $store = $this->storeManager->getStore($storeId);
            $mediaUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
            $data['feed_file_url'] = $mediaUrl . '/panth_seo/feeds/' . $filename;
        } catch (\Throwable) {
        }
    }

    private function decodeJsonFields(array &$data): void
    {
        foreach (['field_mapping', 'conditions_serialized'] as $jsonField) {
            $raw = $data[$jsonField] ?? '';
            if ($raw !== '' && $raw !== '{}' && $raw !== '[]') {
                try {
                    $this->serializer->unserialize($raw);
                } catch (\Throwable) {
                    $data[$jsonField] = '';
                }
            }
        }
    }
}
