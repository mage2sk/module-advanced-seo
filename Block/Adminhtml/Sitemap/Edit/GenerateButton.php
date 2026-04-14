<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Sitemap\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class GenerateButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    public function getButtonData(): array
    {
        $id = (int)$this->request->getParam('id');
        if ($id === 0) {
            return [];
        }

        return [
            'label' => __('Generate Now'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "confirmSetLocation('%s', '%s')",
                __('This will trigger sitemap generation for this profile. Continue?'),
                $this->urlBuilder->getUrl('*/*/generate', ['id' => $id])
            ),
            'sort_order' => 30,
        ];
    }
}
