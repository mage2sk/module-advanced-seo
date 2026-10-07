<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;

class TemplateDataProvider extends DataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        \Magento\Framework\Api\FilterBuilder $filterBuilder,
        private readonly ResourceConnection $resource,
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
    }

    public function getData(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_template');
        if (!$connection->isTableExists($table)) {
            return ['totalRecords' => 0, 'items' => []];
        }
        $rows = $connection->fetchAll($connection->select()->from($table));
        return ['totalRecords' => count($rows), 'items' => $rows];
    }
}
