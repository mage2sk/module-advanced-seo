<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData;

use Panth\AdvancedSEO\Api\StructuredDataProviderInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Aggregates JSON-LD graph fragments from registered providers into a single
 * `@graph` payload. Runs each provider's getJsonLd(), deduplicates nodes by
 * "@id" (or synthetic key of "@type") so e.g. Organization emitted on every
 * page only appears once, and feeds the merged list to the head template.
 *
 * Providers are passed as a DI list via the `providers` argument.
 */
class Aggregator
{
    /** @var array<string,StructuredDataProviderInterface> */
    private array $providers;

    /**
     * @param array<string,StructuredDataProviderInterface> $providers
     */
    public function __construct(
        array $providers,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?Validator $validator = null
    ) {
        $this->providers = [];
        foreach ($providers as $key => $provider) {
            if ($provider instanceof StructuredDataProviderInterface) {
                $this->providers[(string) $key] = $provider;
            }
        }
    }

    /**
     * Build a single JSON-LD document ready to be embedded in a
     * `<script type="application/ld+json">` tag.
     *
     * @return string JSON string. Empty string if no providers emit anything.
     */
    public function build(): string
    {
        if (!$this->config->isEnabled()) {
            return '';
        }

        $graph = [];
        $seen  = [];
        foreach ($this->providers as $code => $provider) {
            try {
                if (!$provider->isApplicable()) {
                    continue;
                }
                if (!$this->config->isStructuredDataEnabled($provider->getCode())) {
                    continue;
                }
                $node = $provider->getJsonLd();
                if ($node === []) {
                    continue;
                }
                // Providers may return a single node or a list of nodes.
                $nodes = $this->isList($node) ? $node : [$node];
                foreach ($nodes as $item) {
                    if (!is_array($item) || !isset($item['@type'])) {
                        continue;
                    }
                    $id = isset($item['@id']) && is_string($item['@id'])
                        ? $item['@id']
                        : (is_array($item['@type']) ? implode(',', $item['@type']) : (string) $item['@type']);
                    if (isset($seen[$id])) {
                        // Deep-merge to let later nodes augment earlier ones.
                        $seen[$id] = $this->merge($seen[$id], $item);
                        continue;
                    }
                    $seen[$id] = $item;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('[Panth_AdvancedSEO] StructuredData provider "%s" failed: %s', $code, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }

        if ($seen === []) {
            return '';
        }

        foreach ($seen as $node) {
            $graph[] = $node;
        }

        $doc = count($graph) === 1
            ? array_merge(['@context' => 'https://schema.org'], $graph[0])
            : ['@context' => 'https://schema.org', '@graph' => $graph];

        if ($this->config->isDebug() && $this->validator !== null) {
            $errors = $this->validator->validate($doc);
            if ($errors !== []) {
                $this->logger->info(
                    '[Panth_AdvancedSEO] JSON-LD validation issues: ' . implode('; ', $errors)
                );
            }
        }

        $json = json_encode(
            $doc,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return '';
        }
        // Prevent XSS: escape all </ sequences inside JSON-LD payloads so
        // that browsers do not interpret them as closing the <script> tag.
        $json = str_replace('</', '<\/', $json);
        return $json;
    }

    /**
     * @param array<int|string,mixed> $array
     */
    private function isList(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (array_key_exists($k, $a) && is_array($a[$k]) && is_array($v)) {
                $a[$k] = $this->merge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }
}
