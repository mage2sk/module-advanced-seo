<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData;

/**
 * A lightweight, dev-mode JSON-LD validator. It enforces a small subset of the
 * schema.org contract rather than the full vocabulary: every node has a
 * `@type`, known types have their required properties, and scalar/url fields
 * look well-formed.
 *
 * The validator is *advisory* — it logs issues via the Aggregator, never
 * blocks rendering.
 */
class Validator
{
    /** @var array<string,array<int,string>> */
    private const REQUIRED = [
        'Product'       => ['name'],
        'Offer'         => ['price', 'priceCurrency'],
        'Organization'  => ['name'],
        'WebSite'       => ['name', 'url'],
        'BreadcrumbList' => ['itemListElement'],
        'FAQPage'       => ['mainEntity'],
        'Article'       => ['headline'],
        'VideoObject'   => ['name', 'thumbnailUrl', 'uploadDate'],
        'Review'        => ['reviewRating', 'author'],
        'AggregateRating' => ['ratingValue', 'reviewCount'],
    ];

    /**
     * @param array<string,mixed> $document
     * @return array<int,string> Non-empty on failure.
     */
    public function validate(array $document): array
    {
        $errors = [];
        $nodes = [];
        if (isset($document['@graph']) && is_array($document['@graph'])) {
            $nodes = $document['@graph'];
        } else {
            $nodes = [$document];
        }
        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                $errors[] = sprintf('Node #%d is not an object', $i);
                continue;
            }
            if (!isset($node['@type'])) {
                $errors[] = sprintf('Node #%d missing @type', $i);
                continue;
            }
            $type = is_array($node['@type']) ? (string) ($node['@type'][0] ?? '') : (string) $node['@type'];
            if (isset(self::REQUIRED[$type])) {
                foreach (self::REQUIRED[$type] as $prop) {
                    if (!array_key_exists($prop, $node) || $node[$prop] === '' || $node[$prop] === null) {
                        $errors[] = sprintf('%s node is missing required property "%s"', $type, $prop);
                    }
                }
            }
            if (isset($node['url']) && is_string($node['url']) && !filter_var($node['url'], FILTER_VALIDATE_URL)) {
                $errors[] = sprintf('%s node has invalid url "%s"', $type, $node['url']);
            }
            if (isset($node['image']) && is_string($node['image']) && !filter_var($node['image'], FILTER_VALIDATE_URL)) {
                $errors[] = sprintf('%s node has invalid image "%s"', $type, $node['image']);
            }
        }
        return $errors;
    }
}
