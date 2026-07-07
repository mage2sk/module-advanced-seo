<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\ViewModel\Canonical as CanonicalViewModel;

class Canonical extends Template
{
    public function __construct(
        Context $context,
        private readonly CanonicalViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }

    public function getCanonicalUrl(): string
    {
        return $this->viewModel->getCanonicalUrl();
    }
}
