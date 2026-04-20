<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Social;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Observer on `layout_generate_blocks_after` (frontend area only).
 *
 * Removes native Magento / Hyva Open Graph blocks from the layout so that
 * duplicate OG meta tags are never rendered when our module's own OG output
 * is active.
 *
 * This is the primary removal mechanism.  The companion plugin
 * {@see \Panth\AdvancedSEO\Plugin\Social\RemoveNativeOgPlugin} acts as a
 * safety net for blocks that slip through because their names do not match the
 * well-known patterns listed here.
 */
class RemoveNativeOgObserver implements ObserverInterface
{
    private const XML_OG_ENABLED = 'panth_seo/social/og_enabled';

    /**
     * Well-known native OG block names that Magento core and Hyva may add
     * via layout XML.  Each name is checked with {@see LayoutInterface::getBlock()}
     * before removal to avoid warnings on layouts that do not include them.
     *
     * @var string[]
     */
    private const NATIVE_OG_BLOCKS = [
        'opengraph.general',
        'opengraph.product',
        'opengraph.category',
        'opengraph.cms',
    ];

    /**
     * Name fragments that identify dynamically-named OG blocks. `'opengraph'`
     * is matched as a substring; `'og.'` is matched as a prefix so it does not
     * false-match blocks such as `catalog.*` which happen to contain `"og."`.
     *
     * @var string[]
     */
    private const OG_NAME_PATTERNS = [
        'opengraph',
        'og.',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->seoConfig->isEnabled()) {
            return;
        }

        if (!$this->isOgEnabled()) {
            return;
        }

        /** @var LayoutInterface $layout */
        $layout = $observer->getEvent()->getLayout();
        if (!$layout instanceof LayoutInterface) {
            return;
        }

        $this->removeWellKnownBlocks($layout);
        $this->removeByPattern($layout);
    }

    /**
     * Remove blocks whose names are explicitly listed.
     */
    private function removeWellKnownBlocks(LayoutInterface $layout): void
    {
        foreach (self::NATIVE_OG_BLOCKS as $blockName) {
            if ($layout->getBlock($blockName)) {
                $layout->unsetElement($blockName);
                $this->logger->debug(
                    sprintf('[PanthSEO] Removed native OG block "%s" from layout.', $blockName)
                );
            }
        }
    }

    /**
     * Scan all layout element names for OG-related substrings and remove them.
     *
     * This catches dynamically generated or theme-specific OG blocks whose
     * names are not in the well-known list.
     */
    private function removeByPattern(LayoutInterface $layout): void
    {
        /** @var string[] $allNames */
        $allNames = $layout->getAllBlocks();

        foreach ($allNames as $name => $block) {
            $lowerName = strtolower((string) $name);

            foreach (self::OG_NAME_PATTERNS as $pattern) {
                $isMatch = $pattern === 'og.'
                    ? str_starts_with($lowerName, 'og.')
                    : str_contains($lowerName, $pattern);
                if ($isMatch) {
                    $layout->unsetElement((string) $name);
                    $this->logger->debug(
                        sprintf('[PanthSEO] Removed native OG block "%s" (pattern match) from layout.', $name)
                    );
                    break;
                }
            }
        }
    }

    private function isOgEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_OG_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }
}
