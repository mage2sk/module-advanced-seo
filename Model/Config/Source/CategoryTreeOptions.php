<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class CategoryTreeOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('name');
        $collection->addFieldToFilter('level', ['gt' => 0]);
        $collection->setOrder('path', 'ASC');

        $nameMap = [];
        foreach ($collection as $category) {
            $nameMap[(int) $category->getId()] = (string) $category->getName();
        }

        $options = [];
        foreach ($collection as $category) {
            $level = (int) $category->getLevel();
            if ($level < 1) {
                continue;
            }

            $pathIds = array_map('intval', explode('/', (string) $category->getPath()));
            $pathNames = [];
            foreach ($pathIds as $pathId) {
                if (isset($nameMap[$pathId])) {
                    $pathNames[] = $nameMap[$pathId];
                }
            }

            $label = implode(' > ', $pathNames) . ' (ID: ' . $category->getId() . ')';

            $options[] = [
                'value' => $category->getId(),
                'label' => $label,
            ];
        }

        return $options;
    }
}
