<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

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
        $id = (int) $this->request->getParam('id');
        if ($id === 0) {
            return [];
        }

        $generateUrl = $this->urlBuilder->getUrl('panth_seo/feed/generate', ['id' => $id]);

        return [
            'label' => __('Generate Now'),
            'class' => 'action-secondary',
            'on_click' => 'confirmSetLocation(\''
                . __('Are you sure you want to generate this feed now? This may take a few minutes for large catalogs.')
                . '\', \'' . $generateUrl . '\')',
            'sort_order' => 30,
        ];
    }
}
