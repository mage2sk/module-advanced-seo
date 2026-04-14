<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class FieldBackButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request,
        private readonly BackendSession $backendSession
    ) {
    }

    public function getButtonData(): array
    {
        $feedId = (int) $this->request->getParam('feed_id');
        if ($feedId <= 0) {
            $feedId = (int) $this->backendSession->getData('panth_seo_feed_field_feed_id');
        }

        $url = $feedId > 0
            ? $this->urlBuilder->getUrl('panth_seo/feed/fields', ['feed_id' => $feedId])
            : $this->urlBuilder->getUrl('panth_seo/feed/index');

        return [
            'label' => __('Back'),
            'on_click' => sprintf("location.href = '%s';", $url),
            'class' => 'back',
            'sort_order' => 10,
        ];
    }
}
