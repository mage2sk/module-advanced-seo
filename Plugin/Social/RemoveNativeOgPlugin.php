<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Social;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Safety-net plugin on AbstractBlock::toHtml().
 *
 * When our own Open Graph tags are enabled (panth_seo/social/og_enabled), this
 * plugin strips the HTML output of any native Magento or Hyva block whose name
 * contains "opengraph" or "og." -- preventing duplicate OG meta tags in the
 * <head> section.
 *
 * The primary removal mechanism is the companion observer
 * {@see \Panth\AdvancedSEO\Observer\Social\RemoveNativeOgObserver} which
 * removes the blocks from the layout entirely.  This plugin exists as a
 * fallback for blocks whose names do not match the patterns the observer
 * targets but still emit OG markup.
 *
 * Only fires in the frontend area.
 */
class RemoveNativeOgPlugin
{
    private const XML_OG_ENABLED = 'panth_seo/social/og_enabled';

    /**
     * Name fragments that identify a native OG block. `'opengraph'` is matched
     * as a substring; `'og.'` is matched as a prefix so it does not false-match
     * blocks such as `catalog.*` which happen to contain `"og."`.
     *
     * @var string[]
     */
    private const OG_BLOCK_IDENTIFIERS = [
        'opengraph',
        'og.',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * Suppress native OG block output when our OG tags are active.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToHtml(AbstractBlock $subject, string $result): string
    {
        if ($result === '') {
            return $result;
        }

        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        if (!$this->isFrontendArea()) {
            return $result;
        }

        if (!$this->isOgEnabled()) {
            return $result;
        }

        $blockName = strtolower((string) $subject->getNameInLayout());
        if ($blockName === '') {
            return $result;
        }

        if ($this->isNativeOgBlock($blockName)) {
            return '';
        }

        return $result;
    }

    /**
     * Check whether the block name matches any known native OG block pattern.
     */
    private function isNativeOgBlock(string $blockName): bool
    {
        foreach (self::OG_BLOCK_IDENTIFIERS as $identifier) {
            $isMatch = $identifier === 'og.'
                ? str_starts_with($blockName, 'og.')
                : str_contains($blockName, $identifier);
            if ($isMatch) {
                return true;
            }
        }

        return false;
    }

    private function isOgEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_OG_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isFrontendArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === 'frontend';
        } catch (\Magento\Framework\Exception\LocalizedException) {
            return false;
        }
    }
}
