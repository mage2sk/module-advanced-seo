<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * ViewModel for the Bulk Editor "type" tabs phtml template.
 *
 * Avoids direct ObjectManager usage inside the template by exposing the
 * currently-selected bulk editor entity type (product / category / cms)
 * through dependency injection.
 */
class BulkEditorTypeTabs implements ArgumentInterface
{
    private const SESSION_KEY = 'panth_seo_bulkeditor_type';
    private const ALLOWED_TYPES = ['product', 'category', 'cms'];
    private const DEFAULT_TYPE = 'product';

    public function __construct(
        private readonly BackendSession $backendSession
    ) {
    }

    /**
     * Return the currently-selected bulk editor entity type.
     */
    public function getCurrentType(): string
    {
        $type = (string) ($this->backendSession->getData(self::SESSION_KEY) ?? self::DEFAULT_TYPE);

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return self::DEFAULT_TYPE;
        }

        return $type;
    }
}
