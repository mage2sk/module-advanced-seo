<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField\Collection;
use Panth\AdvancedSEO\Model\ResourceModel\FeedField\CollectionFactory;

class FeedFieldFormDataProvider extends AbstractDataProvider
{
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
        private readonly BackendSession $backendSession,
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

        $fieldId = (int) $this->request->getParam('field_id');
        if ($fieldId > 0) {
            $this->collection->addFieldToFilter('field_id', $fieldId);
            $items = $this->collection->getItems();
            foreach ($items as $item) {
                $this->loadedData[$item->getId()] = $item->getData();
            }
        }

        // For new field, provide defaults with feed_id
        if (empty($this->loadedData)) {
            $feedId = (int) $this->request->getParam('feed_id');
            if ($feedId <= 0) {
                $feedId = (int) $this->backendSession->getData('panth_seo_feed_field_feed_id');
            }
            $this->loadedData[''] = [
                'feed_id' => $feedId,
                'source_type' => 'attribute',
                'sort_order' => 0,
                'is_required' => 0,
            ];
        }

        return $this->loadedData;
    }
}
