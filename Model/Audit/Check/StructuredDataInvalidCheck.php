<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;

class StructuredDataInvalidCheck implements PageCheckInterface
{
    public const CODE = 'structured_data_invalid';

    private const MERCHANT_KEYS = ['offers', 'review', 'aggregateRating'];

    public function __construct(
        private readonly IssueCatalog $catalog,
        private readonly SchemaAllowList $allowList
    ) {
    }

    public function check(ParsedPage $page, AuditContext $ctx): array
    {
        if (!$page->isOk()) {
            return [];
        }
        $issues = [];
        foreach ($page->jsonLdBlocks as $index => $block) {
            $label = 'JSON-LD block ' . ($index + 1);
            if (!($block['valid'] ?? false)) {
                $issues[] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    $label,
                    'Invalid JSON: ' . (string) ($block['error'] ?? '')
                );
                continue;
            }
            if (!($block['hasContext'] ?? false)) {
                $issues[] = $this->catalog->create(self::CODE, $page->url, '@context', $label . ' has no @context.');
            }
        }

        foreach ($this->mergeById($page->jsonLd) as $node) {
            if (!isset($node['@type'])) {
                if (array_diff(array_keys($node), ['@id']) !== []) {
                    $issues[] = $this->catalog->create(
                        self::CODE,
                        $page->url,
                        '@type',
                        sprintf('Node %s has no @type.', (string) ($node['@id'] ?? '(no @id)'))
                    );
                }
                continue;
            }
            foreach ($this->allowList->findInvalid($node) as $invalid) {
                $element = $invalid['type'] . '.' . $invalid['property'];
                $issues[$element] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    $element,
                    sprintf('"%s" is not a schema.org property of %s.', $invalid['property'], $invalid['type'])
                );
            }
            $types = SchemaAllowList::typeNames($node['@type']);
            if (in_array('Product', $types, true)
                && array_intersect(self::MERCHANT_KEYS, array_keys($node)) === []
            ) {
                $issues['Product.offers'] = $this->catalog->create(
                    self::CODE,
                    $page->url,
                    'Product.offers',
                    'Product has none of offers, review or aggregateRating; Google requires one for merchant listings.'
                );
            }
        }

        return array_values($issues);
    }

    private function mergeById(array $nodes): array
    {
        $merged = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null;
            if ($id === null) {
                $merged[] = $node;
                continue;
            }
            $key = 'id:' . $id;
            $merged[$key] = isset($merged[$key]) ? array_replace_recursive($merged[$key], $node) : $node;
        }

        return array_values($merged);
    }
}
