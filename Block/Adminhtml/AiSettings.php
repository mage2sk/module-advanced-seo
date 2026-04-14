<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class AiSettings extends Template
{
    protected $_template = 'Panth_AdvancedSEO::ai_settings.phtml';

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_seo']);
    }

    public function getUsageUrl(): string
    {
        return $this->getUrl('panth_seo/aisettings/usage');
    }
}
