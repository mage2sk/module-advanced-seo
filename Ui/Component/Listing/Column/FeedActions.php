<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class FeedActions extends Column
{
    public const URL_PATH_EDIT     = 'panth_seo/feed/edit';
    public const URL_PATH_DELETE   = 'panth_seo/feed/delete';
    public const URL_PATH_GENERATE = 'panth_seo/feed/generate';
    public const URL_PATH_FIELDS   = 'panth_seo/feed/fields';

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
            $id = $item['feed_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $item[$name]['edit'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['id' => $id]),
                'label' => (string) __('Edit'),
            ];
            $item[$name]['fields'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_FIELDS, ['feed_id' => $id]),
                'label' => (string) __('Manage Fields'),
            ];
            $item[$name]['generate'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_GENERATE, ['id' => $id]),
                'label' => (string) __('Generate Now'),
                'confirm' => [
                    'title'   => (string) __('Generate Feed'),
                    'message' => (string) __('Are you sure you want to generate this feed now? This may take a few minutes for large catalogs.'),
                ],
            ];
            // Download link (only if file_url exists)
            $fileUrl = $item['file_url'] ?? '';
            if ($fileUrl !== '') {
                $item[$name]['download'] = [
                    'href'   => $fileUrl,
                    'label'  => (string) __('Download'),
                    'target' => '_blank',
                ];
            }
            $item[$name]['delete'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['id' => $id]),
                'label' => (string) __('Delete'),
                'confirm' => [
                    'title'   => (string) __('Delete Feed Profile'),
                    'message' => (string) __('Are you sure you want to delete this feed profile?'),
                ],
            ];
        }
        return $dataSource;
    }
}
