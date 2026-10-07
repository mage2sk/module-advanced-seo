<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml\Feed;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class TestConnectionButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    public function getButtonData(): array
    {
        $feedId = (int) $this->request->getParam('id');
        if ($feedId === 0) {
            return [];
        }

        $testUrl = $this->urlBuilder->getUrl('panth_seo/feed/testConnection');

        return [
            'label' => __('Test FTP Connection'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "require(['uiRegistry'], function (registry) { "
                . "var source = registry.get('panth_seo_feed_form.panth_seo_feed_form_data_source'); "
                . "var data = source ? (source.get('data') || {}) : {}; "
                . "var body = new URLSearchParams(); "
                . "['delivery_type','delivery_host','delivery_user','delivery_password','delivery_path'].forEach(function (f) { "
                . "  body.append(f, data[f] === undefined || data[f] === null ? '' : String(data[f])); "
                . "}); "
                . "body.append('feed_id', '%d'); "
                . "body.append('form_key', window.FORM_KEY); "
                . "fetch('%s', {method: 'POST', credentials: 'same-origin', "
                . "headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body})"
                . ".then(function (r) { return r.json(); })"
                . ".then(function (j) { alert(j.success ? 'Connection successful!' "
                . ": 'Connection failed: ' + (j.message || 'Unknown error')); })"
                . ".catch(function (e) { alert('Error: ' + e.message); }); "
                . "}); return false;",
                $feedId,
                $testUrl
            ),
            'sort_order' => 35,
        ];
    }
}
