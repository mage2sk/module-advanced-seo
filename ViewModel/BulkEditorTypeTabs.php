<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class BulkEditorTypeTabs implements ArgumentInterface
{
    private const SESSION_KEY = 'panth_seo_bulkeditor_type';
    private const ALLOWED_TYPES = ['product', 'category', 'cms'];
    private const DEFAULT_TYPE = 'product';

    public function __construct(
        private readonly BackendSession $backendSession
    ) {
    }

    public function getCurrentType(): string
    {
        $type = (string) ($this->backendSession->getData(self::SESSION_KEY) ?? self::DEFAULT_TYPE);

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return self::DEFAULT_TYPE;
        }

        return $type;
    }
}
