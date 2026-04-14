<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterUrl;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Reverse of UrlBuilder: given a request path, extracts filter segments
 * after the category path and resolves them to attribute_code => option_id pairs.
 */
class UrlParser
{
    public function __construct(
        private readonly RewriteRepository $rewriteRepository,
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * Parse a request path and extract filter parameters.
     *
     * @param string $requestPath e.g. "men/shirts/color/red/size/xl.html" or "men/shirts/color-red-size-xl.html"
     * @return array{category_id: int, filters: array<string, int>}|null
     *               Returns null if the path does not match a category + filter pattern.
     */
    public function parse(string $requestPath): ?array
    {
        $requestPath = ltrim($requestPath, '/');

        // Strip .html suffix for processing.
        $suffix = '';
        if (str_ends_with($requestPath, '.html')) {
            $requestPath = substr($requestPath, 0, -5);
            $suffix = '.html';
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $segments = explode('/', $requestPath);

        // Try progressively shorter prefixes as the category path.
        // Start from longest to shortest to find the best category match.
        $segmentCount = count($segments);

        for ($i = $segmentCount - 1; $i >= 1; $i--) {
            $categoryPath = implode('/', array_slice($segments, 0, $i)) . $suffix;
            $filterSegments = array_slice($segments, $i);

            $categoryId = $this->resolveCategoryId($categoryPath, $storeId);
            if ($categoryId === null) {
                continue;
            }

            $filters = $this->parseFilterSegments($filterSegments, $storeId);
            if ($filters === null || $filters === []) {
                continue;
            }

            return [
                'category_id' => $categoryId,
                'filters'     => $filters,
            ];
        }

        return null;
    }

    /**
     * Resolve a URL path to a category ID via Magento's URL rewrites.
     */
    private function resolveCategoryId(string $requestPath, int $storeId): ?int
    {
        $rewrite = $this->urlFinder->findOneByData([
            UrlRewrite::REQUEST_PATH => $requestPath,
            UrlRewrite::STORE_ID     => $storeId,
            UrlRewrite::ENTITY_TYPE  => 'category',
        ]);

        if ($rewrite === null) {
            // Also try without suffix.
            $pathNoSuffix = preg_replace('/\.html$/', '', $requestPath);
            if ($pathNoSuffix !== $requestPath) {
                $rewrite = $this->urlFinder->findOneByData([
                    UrlRewrite::REQUEST_PATH => $pathNoSuffix,
                    UrlRewrite::STORE_ID     => $storeId,
                    UrlRewrite::ENTITY_TYPE  => 'category',
                ]);
            }
        }

        return $rewrite !== null ? (int) $rewrite->getEntityId() : null;
    }

    /**
     * Parse filter segments according to the configured format.
     *
     * @param string[] $segments Filter path segments (after category prefix).
     * @return array<string, int>|null attribute_code => option_id map, or null if parsing fails.
     */
    private function parseFilterSegments(array $segments, int $storeId): ?array
    {
        $format = $this->config->getValue(Config::XML_FILTER_URL_FORMAT, $storeId);

        if ($format === UrlBuilder::FORMAT_LONG) {
            return $this->parseLongFormat($segments, $storeId);
        }

        return $this->parseShortFormat($segments, $storeId);
    }

    /**
     * Long format: segments come in pairs — attribute_code, slug, attribute_code, slug, ...
     */
    private function parseLongFormat(array $segments, int $storeId): ?array
    {
        if (count($segments) % 2 !== 0) {
            return null;
        }

        $filters = [];
        for ($i = 0, $count = count($segments); $i < $count; $i += 2) {
            $slug   = $segments[$i + 1];
            $result = $this->rewriteRepository->getBySlug($slug, $storeId);
            if ($result === null) {
                return null; // Unknown slug — not a valid filter URL.
            }

            [$attrCode, $optionId] = $result;

            // Validate that the attribute code in the URL matches the slug's attribute.
            if ($segments[$i] !== $attrCode) {
                return null;
            }

            $filters[$attrCode] = $optionId;
        }

        return $filters;
    }

    /**
     * Short format: single segment like "color-red-size-xl", split by separator.
     */
    private function parseShortFormat(array $segments, int $storeId): ?array
    {
        if (count($segments) !== 1) {
            return null;
        }

        $separator = $this->config->getValue(Config::XML_FILTER_URL_SEPARATOR, $storeId);
        $separator = is_string($separator) && $separator !== '' ? $separator : '-';

        $parts = explode($separator, $segments[0]);
        if (count($parts) < 2 || count($parts) % 2 !== 0) {
            return null;
        }

        $filters = [];
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $attrCode = $parts[$i];
            $slug     = $parts[$i + 1];

            $result = $this->rewriteRepository->getBySlug($slug, $storeId);
            if ($result === null) {
                return null;
            }

            [$resolvedAttr, $optionId] = $result;
            if ($resolvedAttr !== $attrCode) {
                return null;
            }

            $filters[$attrCode] = $optionId;
        }

        return $filters;
    }
}
