<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Meta;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Applies SEO meta templates to catalog search result pages.
 *
 * Previously this plugin hooked into
 * `Magento\CatalogSearch\Controller\Result\Index::afterExecute()`, but the
 * Magento core controller's `execute()` method returns `void` (it renders
 * the layout directly and never hands back a `ResultPage`). That made the
 * `afterExecute` callback a no-op on every search result request, which is
 * why both the fallback meta description and the `noindex,follow` robots
 * directive were silently missing from the rendered HTML.
 *
 * The plugin now intercepts `Magento\Framework\View\Page\Config::publicBuild`
 * which is guaranteed to run once per frontend request, immediately before
 * the `<head>` is rendered. We detect search result pages by path and apply:
 *
 *   1. An admin-configured `panth_seo_template` row (entity_type=search) if
 *      one exists for the current store. Supports `{{search_query}}` in
 *      both title and description patterns.
 *   2. A sensible fallback meta description so SERP snippets are never empty
 *      when no template is configured (or the template lacks a description).
 *   3. A robots directive when the matching template row defines one, taken
 *      from the per-template `robots` column.
 *
 * The description/robots are written to the PageConfig instance that is
 * passed into `publicBuild` (which is the same instance the head renderer
 * reads from via `getMetadata()`), so the values cannot be silently
 * overwritten by a later-running PageConfig plugin or layout update.
 */
class SearchResultMetaPlugin
{
    private const ENTITY_TYPE = 'search';

    /**
     * Default meta description used when no admin-configured template exists
     * (or when the configured template has an empty description pattern).
     *
     * The `%s` placeholder receives the escaped search query.
     */
    private const DEFAULT_DESCRIPTION = 'Find %s and related products in our store. Browse our full selection.';

    /**
     * Re-entrancy guard: `publicBuild` can be called multiple times during a
     * single request (each `getTitle()`/`getMetadata()` call indirectly
     * triggers `build()`). We only need to apply the search meta once.
     */
    private bool $applied = false;

    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly SeoConfig $seoConfig,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return array<int,mixed>
     */
    public function beforePublicBuild(PageConfig $subject): array
    {
        if ($this->applied) {
            return [];
        }

        try {
            if (!$this->seoConfig->isEnabled()) {
                return [];
            }

            if (!$this->isSearchResultRequest()) {
                return [];
            }

            // Mark applied early so that calls into the PageConfig below
            // (which internally call `build()`, re-triggering this plugin)
            // do not re-enter and cause an infinite loop.
            $this->applied = true;

            $storeId  = (int) $this->storeManager->getStore()->getId();
            $template = $this->loadTemplate($storeId);

            $searchQuery = (string) $this->request->getParam('q', '');
            $context     = [
                'store_id'     => $storeId,
                'search_query' => $searchQuery,
            ];

            $titlePattern = (string) ($template['meta_title'] ?? '');
            if ($titlePattern !== '') {
                $renderedTitle = $this->templateRenderer->render($titlePattern, null, $context);
                if ($renderedTitle !== '') {
                    $subject->getTitle()->set($renderedTitle);
                }
            }

            $renderedDesc = '';
            $descPattern  = (string) ($template['meta_description'] ?? '');
            if ($descPattern !== '') {
                $renderedDesc = $this->templateRenderer->render($descPattern, null, $context);
            }

            // Fallback: ensure search result pages always expose a meta
            // description so SERP snippets are never empty, even when no
            // admin-configured template (or an empty description pattern)
            // is in place.
            if ($renderedDesc === '') {
                $renderedDesc = $this->buildDefaultDescription($searchQuery);
            }

            if ($renderedDesc !== '') {
                $subject->setDescription($renderedDesc);
            }

            // Robots directive: only applied when the template row itself
            // defines a value. The per-entity `robots` column is still the
            // sole source for search meta.
            $robotsValue = (string) ($template['robots'] ?? '');
            if ($robotsValue !== '') {
                $subject->setRobots($robotsValue);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Panth SEO search result meta plugin failed',
                ['error' => $e->getMessage()]
            );
        }

        return [];
    }

    /**
     * Detect whether the current request is a catalog search result page.
     *
     * We match on the request path (not the full action name) because the
     * path is already normalized by the front controller and does not
     * require layout handles to have been loaded yet. Both
     * `/catalogsearch/result` and `/catalogsearch/result/` variants are
     * covered, as well as advanced-search result pages under the same
     * front name if a merchant enables them.
     */
    private function isSearchResultRequest(): bool
    {
        $path = (string) $this->request->getPathInfo();
        if ($path === '') {
            return false;
        }
        return str_contains($path, '/catalogsearch/');
    }

    /**
     * Build a sensible default meta description for a search result page.
     *
     * The raw search term comes from `?q=` and is user-supplied, so it is
     * escaped with Magento's Escaper (HTML context) before being injected
     * into the description string.
     */
    private function buildDefaultDescription(string $searchQuery): string
    {
        $trimmed = trim($searchQuery);
        if ($trimmed === '') {
            return 'Browse our full selection of products in our store.';
        }

        // Clamp overly long queries so the meta tag stays within a
        // reasonable length for SERP snippets.
        if (mb_strlen($trimmed) > 80) {
            $trimmed = mb_substr($trimmed, 0, 80);
        }

        $safeQuery = $this->escaper->escapeHtml($trimmed);

        return sprintf(self::DEFAULT_DESCRIPTION, $safeQuery);
    }

    /**
     * Load the best-matching active template for entity_type = 'search'.
     *
     * Looks for a store-specific template first, then falls back to the
     * global (store_id = 0) template. Within each scope the highest-priority
     * (lowest numeric value) row wins.
     *
     * @return array<string,mixed>|null
     */
    private function loadTemplate(int $storeId): ?array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_template');

        $select = $conn->select()
            ->from($table)
            ->where('entity_type = ?', self::ENTITY_TYPE)
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order(['store_id DESC', 'priority ASC'])
            ->limit(1);

        $row = $conn->fetchRow($select);

        return is_array($row) && !empty($row) ? $row : null;
    }
}
