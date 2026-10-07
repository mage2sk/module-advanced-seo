<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class AuditContext
{
    private array $normalizedStatuses = [];

    public function __construct(
        public readonly int $storeId = 0,
        public readonly string $baseUrl = '',
        public readonly array $pages = [],
        public readonly array $statuses = [],
        public readonly array $redirectMap = [],
        public readonly array $probes = [],
        public readonly array $resourceBodies = [],
        public readonly ?RobotsTxt $robots = null,
        public readonly int $robotsStatus = 0,
        public readonly ?ProbeResult $llmsTxt = null,
        public readonly array $sitemapUrls = [],
        public readonly bool $sitemapSampled = false,
        public readonly bool $sitemapFetched = false,
        public readonly array $hostChecks = [],
        public readonly bool $crawlComplete = false,
        public readonly float $slowPageMs = 3000.0,
        public readonly int $largeHtmlBytes = 307200,
        public readonly bool $minifyJs = true,
        public readonly bool $minifyCss = true,
        public readonly array $noindexPathRules = [],
        public readonly ?EntityLookupInterface $entityLookup = null,
        public readonly bool $externalChecksCapped = false
    ) {
        foreach ($statuses as $url => $status) {
            $this->normalizedStatuses[UrlHelper::normalize((string) $url)] = (int) $status;
        }
    }

    public function host(): string
    {
        return UrlHelper::host($this->baseUrl);
    }

    public function page(string $url): ?ParsedPage
    {
        return $this->pages[$url] ?? $this->pages[UrlHelper::normalize($url)] ?? null;
    }

    public function probe(string $url): ?ProbeResult
    {
        return $this->probes[$url] ?? $this->probes[UrlHelper::normalize($url)] ?? null;
    }

    public function statusOf(string $url): ?int
    {
        $normalized = UrlHelper::normalize($url);
        if (isset($this->normalizedStatuses[$normalized])) {
            return $this->normalizedStatuses[$normalized];
        }
        $probe = $this->probe($url);

        return $probe?->status;
    }

    public function finalUrlOf(string $url): string
    {
        $hops = $this->redirectMap[$url] ?? $this->redirectMap[UrlHelper::normalize($url)] ?? [];
        if ($hops !== []) {
            $last = (string) end($hops);
            $next = $this->redirectMap[$last] ?? [];

            return $next !== [] ? (string) end($next) : $last;
        }
        $probe = $this->probe($url);
        if ($probe !== null && $probe->location() !== '') {
            return UrlHelper::resolve($probe->location(), $url) ?? $probe->location();
        }

        return '';
    }

    public function entityLabel(string $url): string
    {
        if ($this->entityLookup === null) {
            return '';
        }
        try {
            $entity = $this->entityLookup->entityForUrl($url, $this->storeId);
        } catch (\Throwable) {
            return '';
        }
        if ($entity === null) {
            return '';
        }

        return sprintf('%s #%d%s', $entity['type'], $entity['id'], ($entity['label'] ?? '') !== '' ? ' "' . $entity['label'] . '"' : '');
    }
}
