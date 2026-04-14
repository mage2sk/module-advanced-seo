<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Crosslink;

use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Crosslink\ReplacementService;

/**
 * Lightweight decorator that wraps any CMS template filter instance.
 *
 * All calls are delegated to the inner filter via __call(). Only the
 * filter() method is overridden to post-process the output with crosslinks.
 *
 * This class deliberately does NOT extend \Magento\Framework\Filter\Template
 * to avoid constructor coupling. FilterProvider callers only invoke filter()
 * and setVariables(), both of which are handled here.
 */
class CrosslinkFilterDecorator
{
    private object $innerFilter;
    private ReplacementService $replacementService;
    private StoreManagerInterface $storeManager;
    private string $pageType;

    public function __construct(
        object $innerFilter,
        ReplacementService $replacementService,
        StoreManagerInterface $storeManager,
        string $pageType
    ) {
        $this->innerFilter = $innerFilter;
        $this->replacementService = $replacementService;
        $this->storeManager = $storeManager;
        $this->pageType = $pageType;
    }

    /**
     * Delegate filter() to the inner filter, then apply crosslink replacements.
     */
    public function filter($value): string
    {
        /** @var string $result */
        $result = $this->innerFilter->filter($value);
        $storeId = (int) $this->storeManager->getStore()->getId();

        return $this->replacementService->processContent((string) $result, $this->pageType, $storeId);
    }

    /**
     * Delegate setVariables() to the inner filter.
     */
    public function setVariables(array $variables): static
    {
        $this->innerFilter->setVariables($variables);
        return $this;
    }

    /**
     * Delegate setStrictMode() to the inner filter.
     */
    public function setStrictMode(bool $strictMode): bool
    {
        return $this->innerFilter->setStrictMode($strictMode);
    }

    /**
     * Delegate isStrictMode() to the inner filter.
     */
    public function isStrictMode(): bool
    {
        return $this->innerFilter->isStrictMode();
    }

    /**
     * Proxy all other method calls to the inner filter.
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->innerFilter->$method(...$args);
    }
}
