<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;

class AiAnswerReadinessCheck implements PageCheckInterface
{
    public const CODE = 'ai_answer_readiness';

    public function __construct(private readonly IssueCatalog $catalog)
    {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isIndexable()) {
            return [];
        }
        $issues  = [];
        $missing = [];
        if (!$page->hasFaqOrHowTo) {
            $missing[] = 'no FAQ or HowTo block';
        }
        if (!$page->hasVisibleDate) {
            $missing[] = 'no visible "last updated" date';
        }
        if ($missing !== []) {
            $issues[] = $this->catalog->create(
                self::CODE,
                $page->url,
                implode(', ', $missing),
                'Answer engines prefer pages with question/answer blocks and a visible freshness date: '
                . implode(' and ', $missing) . '.'
            );
        }

        foreach ($page->jsonLd as $node) {
            if (!in_array('Product', SchemaAllowList::typeNames($node['@type'] ?? null), true)) {
                continue;
            }
            $offers = $node['offers'] ?? [];
            $offers = is_array($offers) && !array_is_list($offers) ? [$offers] : (array) $offers;
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $price = $offer['price'] ?? ($offer['lowPrice'] ?? null);
                $hasLogistics = isset($offer['shippingDetails']) || isset($offer['hasMerchantReturnPolicy']);
                if ($price !== null && is_numeric($price) && (float) $price === 0.0 && $hasLogistics) {
                    $issues[] = $this->catalog->create(
                        self::CODE,
                        $page->url,
                        'Product -> SoftwareApplication',
                        'Free product typed as Product with shipping/return data. Emit SoftwareApplication for '
                        . 'downloadable software (products flagged panth_is_software).'
                    );
                    break 2;
                }
            }
        }

        return $issues;
    }
}
