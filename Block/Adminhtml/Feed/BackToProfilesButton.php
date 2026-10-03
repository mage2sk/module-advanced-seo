<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class BackToProfilesButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getButtonData(): array
    {
        return [
            'label' => __('Back to Feed Profiles'),
            'on_click' => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('panth_seo/feed/index')),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
