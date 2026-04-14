<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class ManageFieldsButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    public function getButtonData(): array
    {
        $id = (int) $this->request->getParam('id');
        if ($id === 0) {
            return [];
        }

        $fieldsUrl = $this->urlBuilder->getUrl('panth_seo/feed/fields', ['feed_id' => $id]);

        return [
            'label' => __('Manage Field Mapping'),
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $fieldsUrl),
            'sort_order' => 25,
        ];
    }
}
