<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\ViewModel\OpenGraph as OpenGraphViewModel;

/**
 * Head block rendering Open Graph meta tags.
 */
class OpenGraph extends Template
{
    /** @var string */
    protected $_template = 'Panth_AdvancedSEO::head/opengraph.phtml';

    public function __construct(
        Context $context,
        private readonly OpenGraphViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get resolved Open Graph tags.
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->viewModel->getTags();
    }

    /**
     * Whether Open Graph output is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }
}
