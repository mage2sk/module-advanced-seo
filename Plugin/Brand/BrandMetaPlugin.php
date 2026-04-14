<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Brand;

use Magento\Catalog\Controller\Category\View as CategoryViewController;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * After-plugin on Magento\Catalog\Controller\Category\View::execute().
 *
 * When the brand filter is active, searches for a template row with
 * `entity_type = 'brand'` in `panth_seo_template` and renders it.
 * This allows merchants to define templates like:
 *
 *   "{{brand}} Products | {{category}} | {{store}}"
 *
 * The plugin runs *after* the controller returns so that the layout is already
 * generated and the category metadata plugin has already applied its defaults.
 * Brand-specific meta takes the highest priority when a matching brand template
 * exists.
 */
class BrandMetaPlugin
{
    public function __construct(
        private readonly BrandDetector $brandDetector,
        private readonly SeoConfig $seoConfig,
        private readonly TemplateRenderer $templateRenderer,
        private readonly PageConfig $pageConfig,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(CategoryViewController $subject, ResultInterface|null $result): ResultInterface|null
    {
        try {
            $this->applyBrandMeta($subject->getRequest());
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO BrandMetaPlugin failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function applyBrandMeta(RequestInterface $request): void
    {
        if (!$this->seoConfig->isEnabled()) {
            return;
        }

        if (!$this->brandDetector->isBrandPage($request)) {
            return;
        }

        $brandName = $this->brandDetector->getCurrentBrand($request);
        if ($brandName === null || $brandName === '') {
            return;
        }

        $category = $this->registry->registry('current_category');
        if ($category === null || !$category->getId()) {
            return;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $template = $this->loadBrandTemplate($storeId);
        if ($template === null) {
            return;
        }

        $context = [
            'store_id'   => $storeId,
            'brand_name' => $brandName,
        ];

        $this->renderAndApply($template, $category, $context);
    }

    /**
     * Load the best-matching active brand template for the given store.
     *
     * Precedence: store-specific first, then default (store_id = 0).
     * Within a store scope, the highest-priority (lowest number) wins.
     *
     * @return array<string, mixed>|null
     */
    private function loadBrandTemplate(int $storeId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('panth_seo_template');

        $select = $connection->select()
            ->from($tableName)
            ->where('entity_type = ?', 'brand')
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order(['store_id DESC', 'priority ASC'])
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Render templates from the row and apply non-empty results to page config.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $context
     */
    private function renderAndApply(array $template, mixed $entity, array $context): void
    {
        $metaTitle = $this->renderField($template, 'meta_title', $entity, $context);
        if ($metaTitle !== '') {
            $this->pageConfig->getTitle()->set($metaTitle);
        }

        $metaDescription = $this->renderField($template, 'meta_description', $entity, $context);
        if ($metaDescription !== '') {
            $this->pageConfig->setDescription($metaDescription);
        }

        $metaKeywords = $this->renderField($template, 'meta_keywords', $entity, $context);
        if ($metaKeywords !== '') {
            $this->pageConfig->setKeywords($metaKeywords);
        }

        $robots = trim((string) ($template['robots'] ?? ''));
        if ($robots !== '') {
            $this->pageConfig->setRobots($robots);
        }
    }

    /**
     * Render a single template field through the token engine.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $context
     */
    private function renderField(array $template, string $field, mixed $entity, array $context): string
    {
        $pattern = trim((string) ($template[$field] ?? ''));
        if ($pattern === '') {
            return '';
        }

        return $this->templateRenderer->render($pattern, $entity, $context);
    }
}
