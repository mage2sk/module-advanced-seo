<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterUrl;

use Panth\AdvancedSEO\Helper\Config;

/**
 * Builds SEO-friendly filter URLs from a category URL and a set of active filters.
 *
 * Supports two formats:
 *   - short: /category/color-red-size-xl.html
 *   - long:  /category/color/red/size/xl.html
 */
class UrlBuilder
{
    public const FORMAT_SHORT = 'short';
    public const FORMAT_LONG  = 'long';

    public function __construct(
        private readonly RewriteRepository $rewriteRepository,
        private readonly Config $config
    ) {
    }

    /**
     * Build a friendly URL for the given category + active filters.
     *
     * @param string $categoryUrl   Full category URL (e.g. "https://example.com/men/shirts.html")
     * @param array  $activeFilters Array of ['attribute_code' => string, 'option_id' => int]
     * @param int    $storeId
     * @return string The rewritten URL, or the original if no slugs are found.
     */
    public function build(string $categoryUrl, array $activeFilters, int $storeId): string
    {
        if ($activeFilters === []) {
            return $categoryUrl;
        }

        $slugPairs = $this->resolveSlugPairs($activeFilters, $storeId);
        if ($slugPairs === []) {
            return $categoryUrl;
        }

        $format    = $this->getFormat($storeId);
        $separator = $this->getSeparator($storeId);

        $filterSegment = $this->buildFilterSegment($slugPairs, $format, $separator);

        return $this->injectSegment($categoryUrl, $filterSegment);
    }

    /**
     * Build a URL that removes a specific filter from the current set.
     *
     * @param string $categoryUrl
     * @param array  $activeFilters   All currently active filters.
     * @param string $removeAttrCode  Attribute to remove.
     * @param int    $removeOptionId  Option to remove.
     * @param int    $storeId
     * @return string
     */
    public function buildWithout(
        string $categoryUrl,
        array $activeFilters,
        string $removeAttrCode,
        int $removeOptionId,
        int $storeId
    ): string {
        $remaining = array_filter($activeFilters, static function (array $f) use ($removeAttrCode, $removeOptionId) {
            return $f['attribute_code'] !== $removeAttrCode || (int) $f['option_id'] !== $removeOptionId;
        });

        return $this->build($categoryUrl, array_values($remaining), $storeId);
    }

    /**
     * Resolve attribute+option pairs to [attribute_slug, option_slug] via the repository.
     *
     * @return array<int, array{string, string}>
     */
    private function resolveSlugPairs(array $activeFilters, int $storeId): array
    {
        $pairs = [];
        foreach ($activeFilters as $filter) {
            $attrCode = $filter['attribute_code'];
            $optionId = (int) $filter['option_id'];

            $slug = $this->rewriteRepository->getSlug($attrCode, $optionId, $storeId);
            if ($slug === null) {
                continue;
            }

            $pairs[] = [$attrCode, $slug];
        }

        // Sort by attribute code for consistent URLs.
        usort($pairs, static fn(array $a, array $b) => strcmp($a[0], $b[0]));

        return $pairs;
    }

    /**
     * Assemble the filter segment string.
     */
    private function buildFilterSegment(array $slugPairs, string $format, string $separator): string
    {
        if ($format === self::FORMAT_LONG) {
            $parts = [];
            foreach ($slugPairs as [$attrCode, $slug]) {
                $parts[] = $attrCode . '/' . $slug;
            }
            return implode('/', $parts);
        }

        // Short format: "color-red-size-xl"
        $parts = [];
        foreach ($slugPairs as [$attrCode, $slug]) {
            $parts[] = $attrCode . $separator . $slug;
        }
        return implode($separator, $parts);
    }

    /**
     * Insert the filter segment into the URL path, before the .html suffix.
     */
    private function injectSegment(string $url, string $segment): string
    {
        $parsed = parse_url($url);
        $path   = $parsed['path'] ?? '/';

        $suffix = '';
        if (str_ends_with($path, '.html')) {
            $path   = substr($path, 0, -5);
            $suffix = '.html';
        }

        $path = rtrim($path, '/') . '/' . $segment . $suffix;

        $result = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $result .= ':' . $parsed['port'];
        }
        $result .= $path;

        return $result;
    }

    private function getFormat(?int $storeId): string
    {
        $value = $this->config->getValue(
            Config::XML_FILTER_URL_FORMAT,
            $storeId
        );
        return $value === self::FORMAT_LONG ? self::FORMAT_LONG : self::FORMAT_SHORT;
    }

    private function getSeparator(?int $storeId): string
    {
        $value = $this->config->getValue(
            Config::XML_FILTER_URL_SEPARATOR,
            $storeId
        );
        return is_string($value) && $value !== '' ? $value : '-';
    }
}
