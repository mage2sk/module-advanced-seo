<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class FeedFieldActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $fieldId = $item['field_id'] ?? null;
            $feedId = $item['feed_id'] ?? null;
            if ($fieldId === null) {
                continue;
            }
            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl('panth_seo/feed/newField', [
                    'field_id' => $fieldId,
                    'feed_id' => $feedId,
                ]),
                'label' => (string) __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl('panth_seo/feed/deleteField', [
                    'field_id' => $fieldId,
                    'feed_id' => $feedId,
                ]),
                'label' => (string) __('Delete'),
                'confirm' => [
                    'title' => (string) __('Delete field mapping'),
                    'message' => (string) __('Are you sure you want to delete this field mapping?'),
                ],
            ];
        }
        return $dataSource;
    }
}
