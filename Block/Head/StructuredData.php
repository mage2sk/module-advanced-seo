<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\ViewModel\StructuredData as StructuredDataViewModel;

class StructuredData extends Template
{
    public function __construct(
        Context $context,
        private readonly StructuredDataViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getJson(): string
    {
        return $this->viewModel->getJson();
    }

    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }
}
