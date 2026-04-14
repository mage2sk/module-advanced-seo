<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\SitemapProfile\CollectionFactory;

class SitemapProfileFormDataProvider extends AbstractDataProvider
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
            // Convert entity_types comma-separated string to array for multiselect/checkboxes
            if (isset($itemData['entity_types']) && is_string($itemData['entity_types'])) {
                $itemData['entity_types'] = explode(',', $itemData['entity_types']);
            }
            $this->loadedData[$item->getId()] = $itemData;
        }

        // For new entities, provide sensible defaults
        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'is_active' => '1',
                'store_id' => '0',
                'entity_types' => ['product', 'category', 'cms'],
                'include_images' => '1',
                'include_video' => '0',
                'include_hreflang' => '0',
                'max_urls_per_file' => '50000',
                'changefreq_product' => 'weekly',
                'changefreq_category' => 'weekly',
                'changefreq_cms' => 'monthly',
                'priority_product' => '0.8',
                'priority_category' => '0.6',
                'priority_cms' => '0.5',
                'priority_homepage' => '1.0',
                'exclude_out_of_stock' => '0',
                'exclude_noindex' => '1',
                'output_path' => 'sitemap/panth/{store_code}/',
                'cron_enabled' => '0',
                'cron_schedule' => '0 2 * * *',
            ];
        }

        return $this->loadedData;
    }
}
