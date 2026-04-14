<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Outputs a `<script type="speculationrules">` JSON block for the
 * Speculation Rules API (Chrome 121+). This enables the browser to
 * prefetch category pages and prerender product pages on hover,
 * dramatically improving perceived navigation speed.
 *
 * Hyva-safe: this is a JSON script tag, not executable JavaScript.
 *
 * @see https://developer.chrome.com/docs/web-platform/speculation-rules
 */
class SpeculationRules extends Template
{
    public const XML_ENABLED = 'panth_seo/advanced/speculation_rules_enabled';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SeoConfig $seoConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->seoConfig->isEnabled()
            && $this->scopeConfig->isSetFlag(
                self::XML_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
    }

    /**
     * Return the Speculation Rules JSON payload.
     *
     * Rules:
     * - Prerender product pages on hover (moderate eagerness)
     * - Prefetch category pages on hover
     * - Exclude admin, checkout, customer, wishlist, and cart paths so no
     *   private / authenticated URLs are prerendered.
     *
     * The Chrome Speculation Rules API requires `href_matches` to be a
     * string pattern (or URL pattern object). To exclude multiple paths we
     * build an `or` of individual `href_matches` clauses inside a `not`.
     *
     * @see https://developer.chrome.com/docs/web-platform/prerender-pages
     */
    public function getSpeculationRulesJson(): string
    {
        // Never prerender these prefixes — they either require a session
        // (private data) or trigger side effects (add-to-cart, logout, etc.).
        $excludedPaths = [
            '/admin/*',
            '/checkout/*',
            '/customer/*',
            '/wishlist/*',
            '/cart/*',
            '/sales/*',
            '/newsletter/*',
            '/paypal/*',
            '/review/*',
        ];

        $excludedClause = [
            'not' => [
                'or' => array_map(
                    static fn (string $pattern): array => ['href_matches' => $pattern],
                    $excludedPaths
                ),
            ],
        ];

        $rules = [
            'prerender' => [
                [
                    'source' => 'document',
                    'where' => [
                        'and' => [
                            ['href_matches' => '/*/*.html'],
                            $excludedClause,
                        ],
                    ],
                    'eagerness' => 'moderate',
                ],
            ],
            'prefetch' => [
                [
                    'source' => 'document',
                    'where' => [
                        'and' => [
                            ['href_matches' => '/*'],
                            $excludedClause,
                        ],
                    ],
                    'eagerness' => 'moderate',
                ],
            ],
        ];

        $json = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json !== false ? $json : '{}';
    }
}
