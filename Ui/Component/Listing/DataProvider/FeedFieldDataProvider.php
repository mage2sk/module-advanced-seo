<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\DataProvider;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

class FeedFieldDataProvider extends DataProvider
{
    private bool $feedIdFilterApplied = false;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly BackendSession $backendSession,
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
        if (!$this->feedIdFilterApplied) {
            $this->feedIdFilterApplied = true;
            $feedId = $this->resolveFeedId();
            if ($feedId > 0) {
                $this->addFilter(
                    $this->filterBuilder
                        ->setField('feed_id')
                        ->setValue($feedId)
                        ->setConditionType('eq')
                        ->create()
                );
            }
        }

        return parent::getData();
    }

    private function resolveFeedId(): int
    {
        // Try request param first
        $feedId = (int) $this->request->getParam('feed_id');
        if ($feedId > 0) {
            return $feedId;
        }

        // Fall back to session
        return (int) $this->backendSession->getData('panth_seo_feed_field_feed_id');
    }
}
