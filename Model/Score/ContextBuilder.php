<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Builds the context array passed into SEO score checks.
 */
class ContextBuilder
{
    /**
     * Mapping of third-party entity types to their database table and columns.
     * Used for safe context building without hard module dependencies.
     */
    private const THIRD_PARTY_TABLE_MAP = [
        'faq' => [
            'table' => 'panth_faq_item',
            'id_column' => 'item_id',
            'name_column' => 'question',
            'content_column' => 'answer',
            'meta_title_column' => 'meta_title',
            'meta_description_column' => 'meta_description',
            'meta_keywords_column' => 'meta_keywords',
        ],
        'testimonial' => [
            'table' => 'panth_testimonial',
            'id_column' => 'testimonial_id',
            'name_column' => 'title',
            'content_column' => 'content',
            'extra_columns' => ['customer_name', 'customer_company', 'short_content'],
        ],
        'banner' => [
            'table' => 'panth_banner_slide',
            'id_column' => 'slide_id',
            'name_column' => 'title',
            'content_column' => 'content_html',
            'extra_columns' => ['alt_text', 'link_url'],
        ],
        'dynamic_form' => [
            'table' => 'panth_dynamic_form',
            'id_column' => 'form_id',
            'name_column' => 'title',
            'content_column' => 'description',
            'meta_title_column' => 'meta_title',
            'meta_description_column' => 'meta_description',
            'meta_keywords_column' => 'meta_keywords',
            'extra_columns' => ['content_above', 'content_below', 'success_message'],
        ],
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $entityType, int $entityId, int $storeId): array
    {
        $ctx = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'meta' => ['title' => '', 'description' => '', 'keywords' => ''],
            'content' => '',
            'attributes' => [],
        ];

        try {
            switch ($entityType) {
                case 'product':
                    $product = $this->productRepository->getById($entityId, false, $storeId);
                    $ctx['meta']['title'] = (string)$product->getMetaTitle();
                    $ctx['meta']['description'] = (string)$product->getMetaDescription();
                    $ctx['meta']['keywords'] = (string)$product->getMetaKeyword();
                    $ctx['content'] = (string)$product->getDescription();
                    $ctx['attributes'] = [
                        'name' => (string)$product->getName(),
                        'sku' => (string)$product->getSku(),
                        'brand' => (string)($product->getData('brand') ?? ''),
                        'image' => (string)$product->getData('image'),
                        'price' => (float)$product->getPrice(),
                    ];
                    $ctx['entity'] = $product;
                    break;
                case 'category':
                    $category = $this->categoryRepository->get($entityId, $storeId);
                    $ctx['meta']['title'] = (string)$category->getMetaTitle();
                    $ctx['meta']['description'] = (string)$category->getMetaDescription();
                    $ctx['meta']['keywords'] = (string)$category->getMetaKeywords();
                    $ctx['content'] = (string)$category->getDescription();
                    $ctx['attributes'] = [
                        'name' => (string)$category->getName(),
                        'image' => (string)$category->getImageUrl(),
                    ];
                    $ctx['entity'] = $category;
                    break;
                case 'cms_page':
                    $page = $this->pageRepository->getById($entityId);
                    $ctx['meta']['title'] = (string)$page->getMetaTitle();
                    $ctx['meta']['description'] = (string)$page->getMetaDescription();
                    $ctx['meta']['keywords'] = (string)$page->getMetaKeywords();
                    $ctx['content'] = (string)$page->getContent();
                    $ctx['attributes'] = ['name' => (string)$page->getTitle()];
                    $ctx['entity'] = $page;
                    break;
                default:
                    // Third-party Panth module entities: load via direct DB query
                    $this->buildThirdPartyContext($ctx, $entityType, $entityId);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO context build failed: ' . $e->getMessage());
        }

        return $ctx;
    }

    /**
     * Build context for third-party Panth module entities using direct DB queries.
     * This avoids hard dependencies on other Panth modules.
     */
    private function buildThirdPartyContext(array &$ctx, string $entityType, int $entityId): void
    {
        $mapping = self::THIRD_PARTY_TABLE_MAP[$entityType] ?? null;
        if ($mapping === null) {
            return;
        }

        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName($mapping['table']);

        if (!$connection->isTableExists($tableName)) {
            $this->logger->info(
                'Panth SEO: table ' . $mapping['table'] . ' not found, skipping context for ' . $entityType
            );
            return;
        }

        $row = $connection->fetchRow(
            $connection->select()
                ->from($tableName)
                ->where($mapping['id_column'] . ' = ?', $entityId)
                ->limit(1)
        );

        if (!$row) {
            return;
        }

        // Set name/content from mapped columns
        $name = (string)($row[$mapping['name_column']] ?? '');
        $content = (string)($row[$mapping['content_column']] ?? '');

        $ctx['content'] = $content;
        $ctx['attributes']['name'] = $name;

        // Set meta fields if columns exist
        if (isset($mapping['meta_title_column']) && !empty($row[$mapping['meta_title_column']])) {
            $ctx['meta']['title'] = (string)$row[$mapping['meta_title_column']];
        }
        if (isset($mapping['meta_description_column']) && !empty($row[$mapping['meta_description_column']])) {
            $ctx['meta']['description'] = (string)$row[$mapping['meta_description_column']];
        }
        if (isset($mapping['meta_keywords_column']) && !empty($row[$mapping['meta_keywords_column']])) {
            $ctx['meta']['keywords'] = (string)$row[$mapping['meta_keywords_column']];
        }

        // Add extra columns as attributes
        if (isset($mapping['extra_columns'])) {
            foreach ($mapping['extra_columns'] as $col) {
                if (isset($row[$col])) {
                    $ctx['attributes'][$col] = (string)$row[$col];
                }
            }
        }
    }
}
