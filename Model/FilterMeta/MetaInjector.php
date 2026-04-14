<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\FilterMeta;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Applies per-filter meta overrides to the page config when layered navigation filters are active.
 *
 * Resolution order per filter:
 *   1. Exact DB record in panth_seo_category_filter_meta (per category / attribute / option / store).
 *   2. Template with entity_type = 'category_filter' in panth_seo_template, rendered with filter tokens.
 *   3. Auto-append filter labels to the page title (when enabled).
 */
class MetaInjector
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LayerResolver $layerResolver,
        private readonly TemplateRenderer $templateRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Detect active filters and inject matching meta title/description/keywords into PageConfig.
     */
    public function inject(PageConfig $pageConfig, int $categoryId, int $storeId): void
    {
        $activeFilters = $this->getActiveFilters();
        if (empty($activeFilters)) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_category_filter_meta');

        $appliedTitle = null;
        $appliedDescription = null;
        $appliedKeywords = null;
        $filterLabels = [];

        foreach ($activeFilters as $attributeCode => $optionId) {
            $select = $connection->select()
                ->from($table)
                ->where('category_id = ?', $categoryId)
                ->where('attribute_code = ?', $attributeCode)
                ->where('option_id = ?', (int) $optionId)
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id DESC')
                ->limit(1);

            $row = $connection->fetchRow($select);
            if ($row === false) {
                // Collect filter label for auto-append even if no override row exists
                $filterLabels[$attributeCode] = $this->resolveFilterLabel($attributeCode);
                continue;
            }

            $metaTitle = !empty($row['meta_title']) ? (string) $row['meta_title'] : null;
            $metaDesc = !empty($row['meta_description']) ? (string) $row['meta_description'] : null;
            $metaKw = !empty($row['meta_keywords']) ? (string) $row['meta_keywords'] : null;

            if ($metaTitle !== null) {
                $appliedTitle = $metaTitle;
            }
            if ($metaDesc !== null) {
                $appliedDescription = $metaDesc;
            }
            if ($metaKw !== null) {
                $appliedKeywords = $metaKw;
            }
        }

        // Template fallback: when no DB-level filter meta was found, try to resolve
        // from a category_filter template in panth_seo_template.
        if ($appliedTitle === null && $appliedDescription === null && $appliedKeywords === null) {
            $templateResult = $this->applyFilterTemplate($categoryId, $storeId, $activeFilters, $pageConfig);
            if ($templateResult) {
                $appliedTitle = $templateResult['title'];
                $appliedDescription = $templateResult['description'];
                $appliedKeywords = $templateResult['keywords'];
            }
        }

        if ($appliedTitle !== null) {
            $pageConfig->getTitle()->set($appliedTitle);
        }
        if ($appliedDescription !== null) {
            $pageConfig->setDescription($appliedDescription);
        }
        if ($appliedKeywords !== null) {
            $pageConfig->setKeywords($appliedKeywords);
        }

        // If inject_filter_in_title is enabled and no specific title override was found,
        // append filter values to the existing page title.
        if ($appliedTitle === null && $this->isInjectFilterInTitleEnabled($storeId)) {
            $this->appendFiltersToTitle($pageConfig, $activeFilters);
        }
    }

    /**
     * Get active layered navigation filters as [attribute_code => option_id].
     *
     * @return array<string, string>
     */
    private function getActiveFilters(): array
    {
        $filters = [];

        try {
            $layer = $this->layerResolver->get();
            $state = $layer->getState();
            foreach ($state->getFilters() as $filterItem) {
                $filter = $filterItem->getFilter();
                $attributeCode = $filter->getRequestVar();
                $value = $filterItem->getValueString();
                if ($attributeCode !== '' && $value !== '') {
                    $filters[$attributeCode] = $value;
                }
            }
        } catch (\Throwable) {
            // Layer may not be initialised; fall back to request params
            $knownFilterParams = ['color', 'size', 'price', 'material', 'style', 'brand', 'manufacturer'];
            foreach ($knownFilterParams as $param) {
                $val = $this->request->getParam($param);
                if ($val !== null && $val !== '') {
                    $filters[$param] = (string) $val;
                }
            }
        }

        return $filters;
    }

    /**
     * Resolve a human-readable label for the current filter value.
     */
    private function resolveFilterLabel(string $attributeCode): string
    {
        try {
            $layer = $this->layerResolver->get();
            foreach ($layer->getState()->getFilters() as $filterItem) {
                if ($filterItem->getFilter()->getRequestVar() === $attributeCode) {
                    return (string) $filterItem->getLabel();
                }
            }
        } catch (\Throwable) {
            // Fallback to raw request value
        }

        $raw = $this->request->getParam($attributeCode);
        return $raw !== null ? (string) $raw : '';
    }

    /**
     * Append active filter labels to the existing page title.
     *
     * Result example: "Shoes | Color: Red, Size: XL"
     *
     * @param array<string, string> $activeFilters
     */
    private function appendFiltersToTitle(PageConfig $pageConfig, array $activeFilters): void
    {
        $parts = [];
        try {
            $layer = $this->layerResolver->get();
            foreach ($layer->getState()->getFilters() as $filterItem) {
                $code = $filterItem->getFilter()->getRequestVar();
                if (isset($activeFilters[$code])) {
                    $filterName = $filterItem->getFilter()->getName();
                    $label = $filterItem->getLabel();
                    $parts[] = $filterName . ': ' . $label;
                }
            }
        } catch (\Throwable) {
            // Fallback: use raw param names
            foreach ($activeFilters as $code => $value) {
                $parts[] = ucfirst($code) . ': ' . $value;
            }
        }

        if (empty($parts)) {
            return;
        }

        $currentTitle = $pageConfig->getTitle()->getShort();
        $suffix = implode(', ', $parts);
        $pageConfig->getTitle()->set($currentTitle . ' | ' . $suffix);
    }

    private function isInjectFilterInTitleEnabled(?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            'panth_seo/filter_meta/inject_filter_in_title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Attempt to load and render a category_filter template from panth_seo_template.
     *
     * Template tokens available for rendering:
     *   {{category}}          — current category name
     *   {{filter:<code>}}     — resolved label for the given filter attribute code
     *   {{filters}}           — comma-separated list of all active filter labels
     *   {{store}}             — current store name
     *
     * @param array<string, string> $activeFilters
     * @return array{title: string|null, description: string|null, keywords: string|null}|null
     */
    private function applyFilterTemplate(
        int $categoryId,
        int $storeId,
        array $activeFilters,
        PageConfig $pageConfig
    ): ?array {
        $template = $this->loadFilterTemplate($storeId);
        if ($template === null) {
            return null;
        }

        $context = $this->buildFilterTemplateContext($categoryId, $storeId, $activeFilters, $pageConfig);

        $appliedTitle = null;
        $appliedDescription = null;
        $appliedKeywords = null;

        try {
            if (!empty($template['meta_title'])) {
                $processed = $this->replaceFilterTokens((string) $template['meta_title'], $context);
                $rendered = $this->templateRenderer->render($processed, null, $context);
                if ($rendered !== '') {
                    $appliedTitle = $rendered;
                }
            }
            if (!empty($template['meta_description'])) {
                $processed = $this->replaceFilterTokens((string) $template['meta_description'], $context);
                $rendered = $this->templateRenderer->render($processed, null, $context);
                if ($rendered !== '') {
                    $appliedDescription = $rendered;
                }
            }
            if (!empty($template['meta_keywords'])) {
                $processed = $this->replaceFilterTokens((string) $template['meta_keywords'], $context);
                $rendered = $this->templateRenderer->render($processed, null, $context);
                if ($rendered !== '') {
                    $appliedKeywords = $rendered;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: category_filter template rendering failed', [
                'category_id' => $categoryId,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($appliedTitle === null && $appliedDescription === null && $appliedKeywords === null) {
            return null;
        }

        return [
            'title' => $appliedTitle,
            'description' => $appliedDescription,
            'keywords' => $appliedKeywords,
        ];
    }

    /**
     * Load the highest-priority active template with entity_type = 'category_filter'.
     *
     * @return array<string, mixed>|null
     */
    private function loadFilterTemplate(int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_template');

        $select = $connection->select()
            ->from($table)
            ->where('entity_type = ?', 'category_filter')
            ->where('store_id IN (?)', [0, $storeId])
            ->where('is_active = ?', 1)
            ->order(['store_id DESC', 'priority ASC'])
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row !== false ? $row : null;
    }

    /**
     * Build the context array for category_filter template rendering.
     *
     * Provides tokens like {{category}}, {{filter:color}}, {{filters}}, {{store}}.
     *
     * @param array<string, string> $activeFilters
     * @return array<string, mixed>
     */
    private function buildFilterTemplateContext(
        int $categoryId,
        int $storeId,
        array $activeFilters,
        PageConfig $pageConfig
    ): array {
        $context = [];

        // {{category}} — current category name (from page title as best-effort)
        $context['category'] = (string) $pageConfig->getTitle()->getShort();

        // {{store}} — current store name
        try {
            $store = $this->storeManager->getStore($storeId);
            $context['store'] = (string) $store->getName();
        } catch (\Throwable) {
            $context['store'] = '';
        }

        // Resolve all active filter labels
        $filterLabelMap = [];
        foreach ($activeFilters as $attributeCode => $optionId) {
            $label = $this->resolveFilterLabel($attributeCode);
            $filterLabelMap[$attributeCode] = $label;
            // {{filter:<code>}} — individual filter token, e.g. {{filter:color}}
            $context['filter.' . $attributeCode] = $label;
        }

        // {{filters}} — comma-separated list of all active filter labels
        $context['filters'] = implode(', ', array_filter($filterLabelMap, static fn(string $v) => $v !== ''));

        return $context;
    }

    /**
     * Replace {{filter:attribute_code}} tokens in a template string.
     *
     * The TemplateRenderer's colon syntax passes the part after the colon as an
     * argument to a token resolver, but there is no registered 'filter' token
     * resolver. This method performs a direct string replacement of
     * {{filter:code}} tokens using the resolved labels already in $context.
     *
     * @param array<string, mixed> $context
     */
    private function replaceFilterTokens(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*filter:([a-zA-Z0-9_]+)\s*\}\}/u',
            static function (array $matches) use ($context): string {
                $attributeCode = $matches[1];
                return (string) ($context['filter.' . $attributeCode] ?? '');
            },
            $template
        );
    }
}
