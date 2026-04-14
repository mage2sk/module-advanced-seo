<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class FeedFileLink extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly StoreManagerInterface $storeManager,
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
            $filename = $item[$name] ?? '';
            if ($filename === '') {
                continue;
            }

            try {
                $storeId = (int) ($item['store_id'] ?? 1);
                if ($storeId === 0) {
                    $storeId = 1;
                }
                $store = $this->storeManager->getStore($storeId);
                $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
                $feedUrl = $baseUrl . '/panth_seo/feeds/' . $filename;

                $item[$name] = '<a href="' . htmlspecialchars($feedUrl) . '" target="_blank" style="color:#1979c3;text-decoration:underline;">'
                    . htmlspecialchars($filename) . '</a>';
            } catch (\Throwable) {
                // Keep filename as plain text
            }
        }

        return $dataSource;
    }
}
