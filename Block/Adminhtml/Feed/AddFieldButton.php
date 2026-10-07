<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class AddFieldButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request,
        private readonly BackendSession $backendSession
    ) {
    }

    public function getButtonData(): array
    {
        $feedId = $this->resolveFeedId();

        return [
            'label' => __('Add Field'),
            'class' => 'primary',
            'on_click' => sprintf(
                "location.href = '%s';",
                $this->urlBuilder->getUrl('panth_seo/feed/newField', ['feed_id' => $feedId])
            ),
            'sort_order' => 20,
        ];
    }

    private function resolveFeedId(): int
    {
        $feedId = (int) $this->request->getParam('feed_id');
        if ($feedId > 0) {
            return $feedId;
        }
        return (int) $this->backendSession->getData('panth_seo_feed_field_feed_id');
    }
}
