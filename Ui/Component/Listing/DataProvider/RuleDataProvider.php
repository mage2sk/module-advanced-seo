<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

class RuleDataProvider extends DataProvider
{
    private ResourceConnection $resource;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        \Magento\Framework\Api\Search\ReportingInterface $reporting,
        \Magento\Framework\Api\Search\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Api\FilterBuilder $filterBuilder,
        ResourceConnection $resource,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );
        $this->resource = $resource;
    }

    public function getData(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_rule');
        if (!$connection->isTableExists($table)) {
            return ['totalRecords' => 0, 'items' => []];
        }
        $rows = $connection->fetchAll(
            $connection->select()->from($table)->order('priority ASC')
        );
        return ['totalRecords' => count($rows), 'items' => $rows];
    }
}
