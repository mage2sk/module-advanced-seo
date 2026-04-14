<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\StructuredData;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Strips native Magento JSON-LD and microdata markup from specific blocks so
 * that our own structured data is the single authoritative source.
 *
 * Target blocks: product.info.main, breadcrumbs, product.price.final.
 *
 * Skips any `<script>` tag carrying the `data-panth-seo` attribute so our own
 * JSON-LD is never accidentally removed.
 *
 * Activated via config: panth_seo/structured_data/remove_native_markup
 */
class RemoveNativeMarkupPlugin
{
    private const XML_ENABLED = 'panth_seo/structured_data/remove_native_markup';

    /**
     * Block names whose output should be sanitised.
     */
    private const TARGET_BLOCKS = [
        'product.info.main',
        'breadcrumbs',
        'product.price.final',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToHtml(AbstractBlock $subject, string $result): string
    {
        if ($result === '') {
            return $result;
        }

        if (!$this->isEnabled()) {
            return $result;
        }

        $blockName = (string) $subject->getNameInLayout();
        if (!in_array($blockName, self::TARGET_BLOCKS, true)) {
            return $result;
        }

        $result = $this->stripNativeJsonLd($result);
        $result = $this->stripMicrodataAttributes($result);

        return $result;
    }

    /**
     * Remove <script type="application/ld+json"> blocks that do NOT carry
     * the `data-panth-seo` marker attribute.
     */
    private function stripNativeJsonLd(string $html): string
    {
        // Match <script type="application/ld+json"...>...</script> blocks.
        // Use a callback so we can inspect each match for our marker attribute.
        $pattern = '/<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is';

        $cleaned = (string) preg_replace_callback($pattern, static function (array $match): string {
            $tag = $match[0];
            // Preserve our own JSON-LD blocks (marked with data-panth-seo)
            if (stripos($tag, 'data-panth-seo') !== false) {
                return $tag;
            }
            return '';
        }, $html);

        return $cleaned !== '' || $html === '' ? $cleaned : $html;
    }

    /**
     * Remove itemprop, itemscope, and itemtype attributes from HTML tags.
     */
    private function stripMicrodataAttributes(string $html): string
    {
        // Remove itemscope (standalone attribute, no value)
        $html = (string) preg_replace('/\s+itemscope(?=[\s>\/])/i', '', $html);

        // Remove itemprop="..." and itemtype="..."
        $html = (string) preg_replace('/\s+(?:itemprop|itemtype)\s*=\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '', $html);

        return $html;
    }

    private function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                self::XML_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
    }
}
