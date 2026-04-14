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
                "var form = document.getElementById('panth_seo_feed_form'); "
                . "if (!form) { alert('Please save the profile first.'); return; } "
                . "var data = {}; "
                . "['delivery_type','delivery_host','delivery_user','delivery_password','delivery_path','delivery_passive_mode'].forEach(function(f) { "
                . "  var el = form.querySelector('[name=\"' + f + '\"]'); "
                . "  if (el) data[f] = el.value; "
                . "}); "
                . "data['form_key'] = FORM_KEY; "
                . "fetch('%s', {method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data)})"
                . ".then(function(r){return r.json();})"
                . ".then(function(j){alert(j.success ? 'Connection successful!' : 'Connection failed: ' + (j.message||'Unknown error'));})"
                . ".catch(function(e){alert('Error: ' + e.message);});",
                $testUrl
            ),
            'sort_order' => 35,
        ];
    }
}
