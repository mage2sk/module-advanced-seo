<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Form\DataProvider;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Generic form data provider that works with any collection-backed entity.
 *
 * Magento's standard DataProvider (search-result based) calls getCustomAttributes()
 * on items, which fails for plain AbstractModel instances. This provider loads
 * items from the injected collection and keys them by primary ID so the form JS
 * can resolve the correct row.
 *
 * Inject the concrete collection via di.xml virtual type:
 *
 *   <virtualType name="HreflangFormDataProvider"
 *                type="Panth\AdvancedSEO\Ui\Component\Form\DataProvider\GenericFormDataProvider">
 *       <arguments>
 *           <argument name="collection" xsi:type="object">Panth\AdvancedSEO\Model\ResourceModel\HreflangGroup\Collection</argument>
 *       </arguments>
 *   </virtualType>
 */
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

        // For new entities (no items loaded), provide an empty-defaults entry
        // so the form JS does not spin indefinitely waiting for data.
        if (empty($this->loadedData)) {
            $this->loadedData[''] = [];
        }

        return $this->loadedData;
    }
}
