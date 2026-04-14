<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\AdvancedSEO\ViewModel\TwitterCard as TwitterCardViewModel;

/**
 * Head block rendering Twitter Card meta tags.
 */
class TwitterCard extends Template
{
    /** @var string */
    protected $_template = 'Panth_AdvancedSEO::head/twittercard.phtml';

    public function __construct(
        Context $context,
        private readonly TwitterCardViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get resolved Twitter Card tags.
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->viewModel->getTags();
    }

    /**
     * Whether Twitter Card output is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }
}
