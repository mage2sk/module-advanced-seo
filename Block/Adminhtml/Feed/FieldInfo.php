<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session as BackendSession;

class FieldInfo extends Template
{
    public function __construct(
        Context $context,
        private readonly BackendSession $backendSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFeedId(): int
    {
        $feedId = (int) $this->getRequest()->getParam('feed_id');
        if ($feedId <= 0) {
            $feedId = (int) $this->backendSession->getData('panth_seo_feed_field_feed_id');
        }
        return $feedId;
    }
}
