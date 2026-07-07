<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Block\Head;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

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

    public function getSpeculationRulesJson(): string
    {
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
