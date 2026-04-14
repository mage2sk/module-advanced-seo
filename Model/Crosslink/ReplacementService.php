<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Crosslink;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Config\Source\CrosslinkReferenceType;
use Panth\AdvancedSEO\Model\ResourceModel\Crosslink\CollectionFactory;

/**
 * Core crosslink replacement engine.
 *
 * Injects internal link anchors into HTML content for active crosslink keywords.
 * Operates ONLY on visible text nodes — never modifies content inside excluded
 * HTML tags (anchors, headings, buttons, scripts, styles, etc.).
 */
class ReplacementService
{
    /** @var array<string, Crosslink[]>  Runtime cache keyed by "{storeId}_{pageType}" */
    private array $crosslinkCache = [];

    /** @var array<string, string|null> Runtime cache for resolved reference URLs */
    private array $resolvedUrlCache = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Process HTML content and inject crosslink anchors.
     *
     * @param string $html       Raw HTML to process.
     * @param string $pageType   One of 'product', 'category', 'cms'.
     * @param int    $storeId    Current store view ID.
     * @return string Modified HTML with crosslink anchors injected.
     */
    public function processContent(string $html, string $pageType, int $storeId): string
    {
        if ($html === '') {
            return $html;
        }

        $crosslinks = $this->loadCrosslinks($pageType, $storeId);
        if (empty($crosslinks)) {
            return $html;
        }

        $maxLinksPerPage = $this->getMaxLinksPerPage($storeId);
        $excludedTags = $this->getExcludedTags($storeId);
        $totalReplacements = 0;

        // Per-keyword replacement counters: track how many times each crosslink
        // has been applied across ALL text segments on the page.  This enforces
        // the row-level `max_replacements` limit globally, not per-segment.
        /** @var array<int, int> $keywordReplacementCounts crosslink_id => count */
        $keywordReplacementCounts = [];

        // Split HTML into segments: tags vs. text nodes.
        // We build a regex that matches any HTML tag so we can isolate text-only segments.
        $segments = $this->splitHtmlSegments($html, $excludedTags);

        $result = '';
        foreach ($segments as $segment) {
            if ($totalReplacements >= $maxLinksPerPage) {
                $result .= $segment['content'];
                continue;
            }

            if ($segment['type'] !== 'text') {
                $result .= $segment['content'];
                continue;
            }

            // Process text node: replace keywords with anchor tags
            $text = $segment['content'];
            foreach ($crosslinks as $crosslink) {
                if ($totalReplacements >= $maxLinksPerPage) {
                    break;
                }

                $crosslinkId = (int) $crosslink->getCrosslinkId();
                $maxPerKeyword = $crosslink->getMaxReplacements();
                $usedForKeyword = $keywordReplacementCounts[$crosslinkId] ?? 0;
                $remainingForKeyword = $maxPerKeyword - $usedForKeyword;

                if ($remainingForKeyword <= 0) {
                    continue;
                }

                $remainingForPage = $maxLinksPerPage - $totalReplacements;
                $allowed = min($remainingForKeyword, $remainingForPage);

                $keyword = $crosslink->getKeyword();
                $escapedKeyword = preg_quote($keyword, '/');
                // Word-boundary, case-insensitive match
                $pattern = '/\b(' . $escapedKeyword . ')\b/iu';

                $count = 0;
                $replaced = preg_replace_callback(
                    $pattern,
                    function (array $matches) use ($crosslink, $allowed, &$count): string {
                        if ($count >= $allowed) {
                            return $matches[0];
                        }
                        $count++;
                        return $this->buildAnchor($crosslink, $matches[0]);
                    },
                    $text
                );
                if ($replaced !== null) {
                    $text = $replaced;
                }

                $keywordReplacementCounts[$crosslinkId] = $usedForKeyword + $count;
                $totalReplacements += $count;
            }

            $result .= $text;
        }

        return $result;
    }

    /**
     * Split HTML into segments, tracking which are inside excluded tags.
     *
     * Returns an array of ['type' => 'text'|'tag'|'excluded', 'content' => string].
     * Text outside any tag is 'text'; HTML tags themselves are 'tag';
     * everything inside an excluded tag (including the tag itself) is 'excluded'.
     *
     * @param string   $html
     * @param string[] $excludedTags
     * @return array<int, array{type: string, content: string}>
     */
    private function splitHtmlSegments(string $html, array $excludedTags): array
    {
        if (empty($excludedTags)) {
            // No exclusions — treat entire content as text, but still skip any HTML tags
            return $this->splitTagsAndText($html);
        }

        $segments = [];
        $offset = 0;
        $length = strlen($html);

        // Build a pattern matching opening tags of excluded elements
        $tagAlternation = implode('|', array_map('preg_quote', $excludedTags));
        // Matches <tagname ...> (self-closing or opening)
        $openPattern = '/<(' . $tagAlternation . ')(\s[^>]*)?>/i';

        while ($offset < $length) {
            // Find the next excluded opening tag
            if (!preg_match($openPattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
                // No more excluded tags — remainder is mixed tags+text
                $remainder = substr($html, $offset);
                if ($remainder !== '' && $remainder !== false) {
                    $segments = array_merge($segments, $this->splitTagsAndText($remainder));
                }
                break;
            }

            $matchPos = (int) $m[0][1];
            $matchedTag = strtolower($m[1][0]);

            // Content before the excluded tag is mixed tags+text
            if ($matchPos > $offset) {
                $before = substr($html, $offset, $matchPos - $offset);
                $segments = array_merge($segments, $this->splitTagsAndText($before));
            }

            // Find the matching closing tag (handles nesting)
            $closingTag = '</' . $matchedTag;
            $searchStart = $matchPos + strlen($m[0][0]);
            $nestLevel = 1;
            $endPos = $searchStart;
            $selfClosingCheck = $m[0][0];

            // Self-closing tags (e.g., <br/>, <img/>): no closing tag needed
            if (str_ends_with(rtrim($selfClosingCheck), '/>')) {
                $segments[] = ['type' => 'excluded', 'content' => $m[0][0]];
                $offset = $matchPos + strlen($m[0][0]);
                continue;
            }

            // Void elements that never have closing tags
            $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
            if (in_array($matchedTag, $voidElements, true)) {
                $segments[] = ['type' => 'excluded', 'content' => $m[0][0]];
                $offset = $matchPos + strlen($m[0][0]);
                continue;
            }

            // Walk forward to find the balanced closing tag
            $openTag = '<' . $matchedTag;
            $pos = $searchStart;
            $found = false;

            while ($pos < $length) {
                // Find next occurrence of either opening or closing tag of this type
                $nextOpen = stripos($html, $openTag, $pos);
                $nextClose = stripos($html, $closingTag, $pos);

                if ($nextClose === false) {
                    // No closing tag found — treat rest as excluded
                    $endPos = $length;
                    $found = true;
                    break;
                }

                // If there's a nested open tag before the close tag
                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    // Verify it's actually an opening tag (followed by space, >, or /)
                    $charAfter = $html[$nextOpen + strlen($openTag)] ?? '';
                    if ($charAfter === ' ' || $charAfter === '>' || $charAfter === '/' || $charAfter === "\t" || $charAfter === "\n") {
                        $nestLevel++;
                    }
                    $pos = $nextOpen + strlen($openTag);
                    continue;
                }

                $nestLevel--;
                if ($nestLevel === 0) {
                    // Find the end of the closing tag
                    $closeEnd = strpos($html, '>', $nextClose);
                    $endPos = $closeEnd !== false ? $closeEnd + 1 : $nextClose + strlen($closingTag) + 1;
                    $found = true;
                    break;
                }

                $pos = $nextClose + strlen($closingTag);
            }

            if (!$found) {
                $endPos = $length;
            }

            $excludedContent = substr($html, $matchPos, $endPos - $matchPos);
            $segments[] = ['type' => 'excluded', 'content' => $excludedContent];
            $offset = $endPos;
        }

        return $segments;
    }

    /**
     * Split a string of HTML (with no excluded-tag nesting concerns) into
     * tag segments and text segments.
     *
     * @param string $html
     * @return array<int, array{type: string, content: string}>
     */
    private function splitTagsAndText(string $html): array
    {
        // Split on HTML tags, preserving them
        $parts = preg_split('/(<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [['type' => 'text', 'content' => $html]];
        }

        $segments = [];
        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '<') {
                $segments[] = ['type' => 'tag', 'content' => $part];
            } elseif ($part !== '') {
                $segments[] = ['type' => 'text', 'content' => $part];
            }
        }

        return $segments;
    }

    /**
     * Build an anchor tag for a crosslink.
     *
     * Resolves the destination URL based on the crosslink's reference type.
     */
    private function buildAnchor(Crosslink $crosslink, string $matchedText): string
    {
        $resolvedUrl = $this->resolveUrl($crosslink);
        if ($resolvedUrl === null) {
            // Could not resolve reference — return the text unlinked
            return $matchedText;
        }

        // Defense-in-depth: block dangerous URL schemes at render time in case
        // a rule was inserted directly into the database bypassing admin Save
        // controller validation.
        if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $resolvedUrl)) {
            return $matchedText;
        }

        $url = htmlspecialchars($resolvedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = htmlspecialchars($crosslink->getUrlTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $attrs = 'href="' . $url . '"';
        if ($title !== '') {
            $attrs .= ' title="' . $title . '"';
        }
        if ($crosslink->isNofollow()) {
            $attrs .= ' rel="nofollow"';
        }

        return '<a ' . $attrs . '>' . htmlspecialchars($matchedText, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . '</a>';
    }

    /**
     * Resolve the final URL for a crosslink based on its reference type.
     *
     * For 'url' type, the stored URL column is used directly.
     * For 'product_sku', the product's URL is looked up via url_rewrite.
     * For 'category_id', the category's URL is looked up via url_rewrite.
     *
     * Results are cached in memory for the duration of the request.
     */
    private function resolveUrl(Crosslink $crosslink): ?string
    {
        $referenceType = $crosslink->getReferenceType();
        $referenceValue = $crosslink->getReferenceValue();

        if ($referenceType === CrosslinkReferenceType::TYPE_URL || $referenceType === '') {
            return $crosslink->getUrl();
        }

        $cacheKey = $referenceType . '::' . ($referenceValue ?? '') . '::' . $crosslink->getStoreId();
        if (array_key_exists($cacheKey, $this->resolvedUrlCache)) {
            return $this->resolvedUrlCache[$cacheKey];
        }

        $resolvedUrl = match ($referenceType) {
            CrosslinkReferenceType::TYPE_PRODUCT_SKU => $this->resolveProductUrl(
                (string) $referenceValue,
                $crosslink->getStoreId()
            ),
            CrosslinkReferenceType::TYPE_CATEGORY_ID => $this->resolveCategoryUrl(
                (int) $referenceValue,
                $crosslink->getStoreId()
            ),
            default => $crosslink->getUrl(),
        };

        $this->resolvedUrlCache[$cacheKey] = $resolvedUrl;

        return $resolvedUrl;
    }

    /**
     * Look up a product's storefront URL from `url_rewrite` by SKU.
     *
     * Joins `catalog_product_entity` to translate SKU -> entity_id, then finds
     * the matching url_rewrite row for that store (or store 0 as fallback).
     */
    private function resolveProductUrl(string $sku, int $storeId): ?string
    {
        if ($sku === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $productTable = $this->resource->getTableName('catalog_product_entity');
        $rewriteTable = $this->resource->getTableName('url_rewrite');

        // Resolve SKU -> entity_id
        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($productTable, ['entity_id'])
                ->where('sku = ?', $sku)
                ->limit(1)
        );

        if ($entityId === false) {
            return null;
        }

        // Find the url_rewrite for this product (prefer store-specific, fallback to store 0)
        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($rewriteTable, ['request_path'])
                ->where('entity_type = ?', 'product')
                ->where('entity_id = ?', (int) $entityId)
                ->where('store_id IN (?)', [0, $storeId])
                ->where('redirect_type = ?', 0)
                ->order('store_id DESC')
                ->limit(1)
        );

        if ($requestPath === false || $requestPath === '') {
            return null;
        }

        return '/' . ltrim((string) $requestPath, '/');
    }

    /**
     * Look up a category's storefront URL from `url_rewrite` by category ID.
     */
    private function resolveCategoryUrl(int $categoryId, int $storeId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $rewriteTable = $this->resource->getTableName('url_rewrite');

        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($rewriteTable, ['request_path'])
                ->where('entity_type = ?', 'category')
                ->where('entity_id = ?', $categoryId)
                ->where('store_id IN (?)', [0, $storeId])
                ->where('redirect_type = ?', 0)
                ->order('store_id DESC')
                ->limit(1)
        );

        if ($requestPath === false || $requestPath === '') {
            return null;
        }

        return '/' . ltrim((string) $requestPath, '/');
    }

    /**
     * Load active crosslinks for the given page type and store, ordered by priority DESC.
     *
     * @return Crosslink[]
     */
    private function loadCrosslinks(string $pageType, int $storeId): array
    {
        $cacheKey = $storeId . '_' . $pageType;
        if (isset($this->crosslinkCache[$cacheKey])) {
            return $this->crosslinkCache[$cacheKey];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('store_id', [['eq' => $storeId], ['eq' => 0]]);

        // Filter by page type placement flag
        $placementField = match ($pageType) {
            'product'  => 'in_product',
            'category' => 'in_category',
            'cms'      => 'in_cms',
            default    => null,
        };

        if ($placementField !== null) {
            $collection->addFieldToFilter($placementField, 1);
        }

        // Time-based activation: only enforce the schedule window when the
        // `crosslink_time_activation` flag is enabled for this store. When
        // disabled, `active_from`/`active_to` are ignored so every active
        // rule is returned regardless of its date range.
        if ($this->isTimeActivationEnabled($storeId)) {
            $collection->getSelect()
                ->where('active_from IS NULL OR active_from <= NOW()')
                ->where('active_to IS NULL OR active_to >= NOW()');
        }

        $collection->setOrder('priority', 'DESC');

        /** @var Crosslink[] $items */
        $items = $collection->getItems();
        $this->crosslinkCache[$cacheKey] = array_values($items);

        return $this->crosslinkCache[$cacheKey];
    }

    /**
     * Check whether time-based activation is enabled for the given store.
     */
    private function isTimeActivationEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            'panth_seo/crosslinks/crosslink_time_activation',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get the maximum number of crosslinks allowed per page.
     */
    private function getMaxLinksPerPage(int $storeId): int
    {
        $value = $this->scopeConfig->getValue(
            'panth_seo/crosslinks/max_links_per_page',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== null ? (int) $value : 10;
    }

    /**
     * Get the list of HTML tags to exclude from crosslink replacement.
     *
     * @return string[]
     */
    private function getExcludedTags(int $storeId): array
    {
        $value = $this->scopeConfig->getValue(
            'panth_seo/crosslinks/excluded_tags',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $raw = $value !== null ? (string) $value : 'h1,h2,h3,h4,h5,h6,a,button,script,style';

        if ($raw === '') {
            return [];
        }

        return array_map('trim', explode(',', $raw));
    }
}
