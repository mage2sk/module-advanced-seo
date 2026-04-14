<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class MissingMetaActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly BackendSession $backendSession,
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

        $type = (string)($this->backendSession->getData('panth_seo_missing_meta_type') ?? 'product');

        foreach ($dataSource['data']['items'] as &$item) {
            $entityId = (int)($item['entity_id'] ?? 0);
            if ($entityId === 0) {
                continue;
            }

            if ($type === 'category') {
                $item[$this->getData('name')] = [
                    'edit' => [
                        'href' => $this->urlBuilder->getUrl(
                            'catalog/category/edit',
                            ['id' => $entityId]
                        ),
                        'label' => __('Edit Category'),
                        'target' => '_blank',
                    ],
                ];
            } else {
                $item[$this->getData('name')] = [
                    'edit' => [
                        'href' => $this->urlBuilder->getUrl(
                            'catalog/product/edit',
                            ['id' => $entityId]
                        ),
                        'label' => __('Edit Product'),
                        'target' => '_blank',
                    ],
                ];
            }
        }

        return $dataSource;
    }
}
