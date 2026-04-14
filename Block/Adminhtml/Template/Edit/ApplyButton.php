<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Template\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class ApplyButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    public function getButtonData(): array
    {
        $templateId = (int) $this->request->getParam('id');
        if ($templateId === 0) {
            return [];
        }

        $applyUrl = $this->urlBuilder->getUrl('panth_seo/template/apply', ['template_id' => $templateId]);

        return [
            'label' => __('Apply Now'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s')",
                __('This will apply the template to all matching entities. Continue?'),
                $applyUrl
            ),
            'sort_order' => 40,
        ];
    }
}
