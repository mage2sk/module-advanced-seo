<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\AdvancedSEO\Model\ResourceModel\HreflangGroup\CollectionFactory;

class HreflangDataProvider extends AbstractDataProvider
{
    private ResourceConnection $resource;
    private bool $memberCountJoined = false;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        ResourceConnection $resource,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        $this->resource = $resource;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $this->joinMemberCount();

        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection() as $item) {
            $items[] = $item->getData();
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    public function addFilter(Filter $filter): void
    {
        $field = $filter->getField();

        if ($field === 'member_count') {
            // member_count is a calculated field, cannot be filtered at DB level easily
            return;
        }

        parent::addFilter($filter);
    }

    private function joinMemberCount(): void
    {
        if ($this->memberCountJoined) {
            return;
        }
        $this->memberCountJoined = true;

        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');
        $this->getCollection()->getSelect()->joinLeft(
            ['m' => $memberTable],
            'main_table.group_id = m.group_id',
            ['member_count' => new \Zend_Db_Expr('COUNT(m.member_id)')]
        )->group('main_table.group_id');
    }
}
