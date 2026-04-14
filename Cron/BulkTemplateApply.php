<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Cron;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\Data\MetaTemplateInterface;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Cron job: applies all active SEO templates in bulk.
 *
 * For each store, loads templates ordered by priority DESC, evaluates their
 * conditions against every matching entity, renders the template output and
 * upserts the result into `panth_seo_resolved`.
 *
 * Processes entities in batches of 500 to keep memory bounded on large catalogs.
 */
class BulkTemplateApply
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly TemplateCollectionFactory $templateCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly TemplateRenderer $templateRenderer,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly LoggerInterface $logger,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
        private readonly ResolvedMetaFactory $resolvedMetaFactory,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function execute(): void
    {
        $stores = $this->storeRepository->getList();

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                continue; // skip admin store
            }

            try {
                $this->processStore($storeId);
            } catch (\Throwable $e) {
                $this->logger->error('Panth SEO BulkTemplateApply failed for store ' . $storeId, [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processStore(int $storeId): void
    {
        $templates = $this->loadActiveTemplates($storeId);
        if ($templates === []) {
            return;
        }

        $this->logger->info('Panth SEO BulkTemplateApply: processing store ' . $storeId . ' with ' . count($templates) . ' templates');

        foreach ($templates as $template) {
            try {
                $this->processTemplate($template, $storeId);
            } catch (\Throwable $e) {
                $this->logger->warning('Panth SEO BulkTemplateApply: template ' . $template->getTemplateId() . ' failed', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return MetaTemplateInterface[]
     */
    private function loadActiveTemplates(int $storeId): array
    {
        $collection = $this->templateCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('is_cron_enabled', 1);
        $collection->addFieldToFilter('store_id', ['in' => [0, $storeId]]);
        $collection->setOrder('priority', 'DESC');

        return $collection->getItems();
    }

    private function processTemplate(MetaTemplateInterface $template, int $storeId): void
    {
        $entityType = $template->getEntityType();
        $conditions = $this->decodeConditions($template);
        $renderStoreId = $storeId > 0 ? $storeId : 1;
        $context    = ['store_id' => $renderStoreId];
        $processed  = 0;

        $batchCallback = function (iterable $entities) use ($template, $entityType, $storeId, $conditions, $context, &$processed): void {
            foreach ($entities as $entity) {
                if (!$this->conditionEvaluator->evaluate($conditions, $entity, $storeId)) {
                    continue;
                }

                $this->renderAndSave($template, $entity, $entityType, $storeId, $context);
                $processed++;
            }
        };

        $renderStoreId = $storeId > 0 ? $storeId : 1;
        match ($entityType) {
            'product'  => $this->iterateProducts($renderStoreId, $batchCallback),
            'category' => $this->iterateCategories($renderStoreId, $batchCallback),
            'cms', 'cms_page' => $this->iterateCmsPages($renderStoreId, $batchCallback),
            default    => null,
        };

        if ($processed > 0) {
            $this->stampTemplateApplied($template);
            $this->logger->info(sprintf(
                'Panth SEO BulkTemplateApply: template %d applied to %d %s entities (store %d)',
                $template->getTemplateId(),
                $processed,
                $entityType,
                $storeId
            ));
        }
    }

    /**
     * Iterate all products for a store in batches.
     *
     * @param callable(iterable): void $callback
     */
    private function iterateProducts(int $storeId, callable $callback): void
    {
        $page = 1;
        do {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([
                'name', 'url_key', 'sku', 'seo_name', 'meta_title', 'meta_description',
                'short_description', 'description', 'manufacturer', 'visibility',
                'type_id', 'attribute_set_id', 'price', 'special_price', 'image',
            ]);
            $collection->setPageSize(self::BATCH_SIZE);
            $collection->setCurPage($page);

            $items = $collection->getItems();
            if ($items === []) {
                break;
            }

            $callback($items);

            $lastPage = (int) $collection->getLastPageNumber();
            $page++;
        } while ($page <= $lastPage);
    }

    /**
     * Iterate all categories for a store in batches.
     *
     * @param callable(iterable): void $callback
     */
    private function iterateCategories(int $storeId, callable $callback): void
    {
        $page = 1;
        do {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect(['name', 'url_key', 'seo_name', 'meta_title', 'meta_description']);
            $collection->addFieldToFilter('level', ['gteq' => 2]);
            $collection->setPageSize(self::BATCH_SIZE);
            $collection->setCurPage($page);

            $items = $collection->getItems();
            if ($items === []) {
                break;
            }

            $callback($items);

            $lastPage = (int) $collection->getLastPageNumber();
            $page++;
        } while ($page <= $lastPage);
    }

    /**
     * Iterate all CMS pages for a store in batches.
     *
     * @param callable(iterable): void $callback
     */
    private function iterateCmsPages(int $storeId, callable $callback): void
    {
        $page = 1;
        do {
            $collection = $this->cmsPageCollectionFactory->create();
            $collection->addFieldToFilter('is_active', 1);
            if ($storeId > 0) {
                $collection->addStoreFilter($storeId);
            }
            $collection->setPageSize(self::BATCH_SIZE);
            $collection->setCurPage($page);

            $items = $collection->getItems();
            if ($items === []) {
                break;
            }

            $callback($items);

            $lastPage = (int) $collection->getLastPageNumber();
            $page++;
        } while ($page <= $lastPage);
    }

    /**
     * Render template fields and upsert into panth_seo_resolved.
     */
    private function renderAndSave(
        MetaTemplateInterface $template,
        mixed $entity,
        string $entityType,
        int $storeId,
        array $context
    ): void {
        $entityId = $this->extractEntityId($entity);
        if ($entityId === 0) {
            return;
        }

        $metaTitle       = $this->renderField($template->getMetaTitle(), $entity, $context);
        $metaDescription = $this->renderField($template->getMetaDescription(), $entity, $context);
        $metaKeywords    = $this->renderField($template->getMetaKeywords(), $entity, $context);
        $robots          = $template->getRobots();

        $ogPayload = [];
        $ogTitle = $this->renderField($template->getOgTitle(), $entity, $context);
        if ($ogTitle !== '') {
            $ogPayload['og:title'] = $ogTitle;
        }
        $ogDescription = $this->renderField($template->getOgDescription(), $entity, $context);
        if ($ogDescription !== '') {
            $ogPayload['og:description'] = $ogDescription;
        }
        $ogImage = $this->renderField($template->getOgImage(), $entity, $context);
        if ($ogImage !== '') {
            $ogPayload['og:image'] = $ogImage;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_resolved');

        $data = [
            'store_id'         => $storeId,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'meta_title'       => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'meta_keywords'    => $metaKeywords !== '' ? $metaKeywords : null,
            'robots'           => $robots,
            'og_payload'       => $ogPayload !== [] ? $this->serializer->serialize($ogPayload) : null,
            'source'           => 'bulk_template',
        ];

        $connection->insertOnDuplicate($table, $data, [
            'meta_title',
            'meta_description',
            'meta_keywords',
            'robots',
            'og_payload',
            'source',
        ]);
    }

    private function renderField(?string $templateString, mixed $entity, array $context): string
    {
        if ($templateString === null || $templateString === '') {
            return '';
        }

        return $this->templateRenderer->render($templateString, $entity, $context);
    }

    private function extractEntityId(mixed $entity): int
    {
        if (is_object($entity) && method_exists($entity, 'getId')) {
            return (int) $entity->getId();
        }

        if (is_array($entity) && isset($entity['entity_id'])) {
            return (int) $entity['entity_id'];
        }

        return 0;
    }

    /**
     * Update last_applied_at and increment apply_count on the template row.
     */
    private function stampTemplateApplied(MetaTemplateInterface $template): void
    {
        $templateId = $template->getTemplateId();
        if ($templateId === null) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_template');

        $connection->update(
            $table,
            [
                'last_applied_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'apply_count'     => new \Zend_Db_Expr('apply_count + 1'),
            ],
            ['template_id = ?' => $templateId]
        );
    }

    /**
     * Decode the conditions_serialized column into an array.
     *
     * @return array<string,mixed>
     */
    private function decodeConditions(MetaTemplateInterface $template): array
    {
        if (!method_exists($template, 'getData')) {
            return [];
        }

        $raw = $template->getData('conditions_serialized');
        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
