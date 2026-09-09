<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

class Apply extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    private const BATCH_SIZE = 500;

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly TemplateRenderer $templateRenderer,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductAction $productAction,
        private readonly CategoryResource $categoryResource,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly SeoConfig $seoConfig,
        private readonly SeoCacheInvalidator $cacheInvalidator,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $templateId = (int) $this->getRequest()->getParam('template_id');

        if ($templateId <= 0) {
            $this->messageManager->addErrorMessage((string) __('Missing template_id parameter.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $template = $this->loadTemplate($templateId);
            if ($template === null) {
                $this->messageManager->addErrorMessage((string) __('Template not found.'));
                return $resultRedirect->setPath('*/*/');
            }

            $entityType   = (string) ($template['entity_type'] ?? 'product');
            $storeId      = (int) ($template['store_id'] ?? 0);
            $conditions   = $this->decodeConditions($template);

            $titlePattern   = (string) ($template['meta_title'] ?? '');
            $descPattern    = (string) ($template['meta_description'] ?? '');
            $kwPattern      = (string) ($template['meta_keywords'] ?? '');
            $seoNamePattern = (string) ($template['seo_name'] ?? '');
            $robots         = (string) ($template['robots'] ?? '');
            $ogTitlePat     = (string) ($template['og_title'] ?? '');
            $ogDescPat      = (string) ($template['og_description'] ?? '');
            $ogImage        = (string) ($template['og_image'] ?? '');

            $patterns = [
                $titlePattern, $descPattern, $kwPattern, $seoNamePattern,
                $ogTitlePat, $ogDescPat, $ogImage,
            ];

            $renderStoreIds = $this->resolveRenderStoreIds($storeId);
            if ($renderStoreIds === []) {
                $this->messageManager->addErrorMessage((string) __('No store view is available to apply this template to.'));
                return $resultRedirect->setPath('*/*/');
            }

            $eavPerStore = $storeId > 0 || $this->patternsUseStoreTokens($patterns);
            $totalApplied = 0;
            $eavWritten   = [];

            foreach ($renderStoreIds as $renderStoreId) {
                $eavStoreId = $eavPerStore ? $renderStoreId : 0;
                $context    = ['store_id' => $renderStoreId];
                $pendingRows = [];

                $processEntities = function (iterable $entities) use (
                    $titlePattern,
                    $descPattern,
                    $kwPattern,
                    $seoNamePattern,
                    $robots,
                    $ogTitlePat,
                    $ogDescPat,
                    $ogImage,
                    $conditions,
                    $entityType,
                    $storeId,
                    $renderStoreId,
                    $eavStoreId,
                    $eavPerStore,
                    $context,
                    &$totalApplied,
                    &$pendingRows,
                    &$eavWritten
                ): void {
                    $force = $this->seoConfig->isForceTemplateOverExisting($renderStoreId);

                    foreach ($entities as $entity) {
                        if (!$this->conditionEvaluator->evaluate($conditions, $entity, $storeId)) {
                            continue;
                        }

                        $entityId = $this->extractEntityId($entity);
                        if ($entityId === 0) {
                            continue;
                        }

                        $metaTitle = $titlePattern !== ''
                            ? $this->templateRenderer->render($titlePattern, $entity, $context)
                            : '';
                        $metaDesc  = $descPattern !== ''
                            ? $this->templateRenderer->render($descPattern, $entity, $context)
                            : '';
                        $metaKw    = $kwPattern !== ''
                            ? $this->templateRenderer->render($kwPattern, $entity, $context)
                            : '';
                        $seoName   = $seoNamePattern !== ''
                            ? $this->templateRenderer->render($seoNamePattern, $entity, $context)
                            : '';

                        $ogTitle = $ogTitlePat !== ''
                            ? $this->templateRenderer->render($ogTitlePat, $entity, $context)
                            : '';
                        $ogDesc = $ogDescPat !== ''
                            ? $this->templateRenderer->render($ogDescPat, $entity, $context)
                            : '';
                        $renderedOgImage = $ogImage !== ''
                            ? $this->templateRenderer->render($ogImage, $entity, $context)
                            : '';

                        if (!$force) {
                            if ($this->hasExistingValue($entity, 'meta_title')) {
                                $metaTitle = '';
                            }
                            if ($this->hasExistingValue($entity, 'meta_description')) {
                                $metaDesc = '';
                            }
                        }

                        $eavKey = $entityType . ':' . $entityId . ':' . $eavStoreId;
                        if ($eavPerStore || !isset($eavWritten[$eavKey])) {
                            $eavWritten[$eavKey] = true;
                            $this->saveToEav(
                                $entityType, $entityId, $eavStoreId,
                                $metaTitle, $metaDesc, $metaKw, $seoName,
                                $ogTitle, $ogDesc, $renderedOgImage,
                                $robots
                            );
                        }

                        $ogPayload = [];
                        if ($ogTitle !== '') {
                            $ogPayload['og:title'] = $ogTitle;
                        }
                        if ($ogDesc !== '') {
                            $ogPayload['og:description'] = $ogDesc;
                        }
                        if ($renderedOgImage !== '') {
                            $ogPayload['og:image'] = $renderedOgImage;
                        }

                        if ($metaTitle === '' && $metaDesc === '' && $metaKw === '' && $robots === '' && $ogPayload === []) {
                            continue;
                        }

                        $pendingRows[] = [
                            'store_id'         => $renderStoreId,
                            'entity_type'      => $entityType,
                            'entity_id'        => $entityId,
                            'meta_title'       => $metaTitle !== '' ? $metaTitle : null,
                            'meta_description' => $metaDesc !== '' ? $metaDesc : null,
                            'meta_keywords'    => $metaKw !== '' ? $metaKw : null,
                            'robots'           => $robots !== '' ? $robots : null,
                            'og_payload'       => $ogPayload !== []
                                ? $this->serializer->serialize($ogPayload)
                                : null,
                            'source'           => 'bulk_template',
                        ];

                        $totalApplied++;

                        if (count($pendingRows) >= self::BATCH_SIZE) {
                            $this->flushResolvedRows($pendingRows);
                            $pendingRows = [];
                        }
                    }
                };

                match ($entityType) {
                    'product'  => $this->iterateProducts($renderStoreId, $processEntities),
                    'category' => $this->iterateCategories($renderStoreId, $processEntities),
                    'cms', 'cms_page' => $this->iterateCmsPages($renderStoreId, $processEntities),
                    default    => null,
                };

                if ($pendingRows !== []) {
                    $this->flushResolvedRows($pendingRows);
                }
            }

            $this->markApplied($templateId);
            $this->cacheInvalidator->invalidateResolvedMeta();

            $this->messageManager->addSuccessMessage(
                (string) __('Template #%1 applied to %2 %3 entities.', $templateId, $totalApplied, $entityType)
            );

            $this->logger->info(sprintf(
                'Panth SEO Template Apply: template %d applied to %d %s entities (template store %d, rendered for stores %s)',
                $templateId,
                $totalApplied,
                $entityType,
                $storeId,
                implode(',', $renderStoreIds)
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO Template Apply failed', [
                'template_id' => $templateId,
                'exception'   => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Template apply failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('*/*/');
    }

    private function saveToEav(
        string $entityType,
        int $entityId,
        int $storeId,
        string $metaTitle,
        string $metaDesc,
        string $metaKw,
        string $seoName = '',
        string $ogTitle = '',
        string $ogDesc = '',
        string $ogImage = '',
        string $robots = ''
    ): void {
        $attributes = [];
        if ($metaTitle !== '') {
            $attributes['meta_title'] = $metaTitle;
        }
        if ($metaDesc !== '') {
            $attributes['meta_description'] = $metaDesc;
        }
        if ($seoName !== '') {
            $attributes['seo_name'] = $seoName;
        }
        if ($ogTitle !== '') {
            $attributes['og_title'] = $ogTitle;
        }
        if ($ogDesc !== '') {
            $attributes['og_description'] = $ogDesc;
        }
        if ($ogImage !== '') {
            $attributes['og_image'] = $ogImage;
        }
        if ($robots !== '') {
            $attributes['meta_robots'] = $robots;
        }

        if ($attributes === []) {
            return;
        }

        $eavStoreId = $storeId;

        if ($entityType === 'product') {
            if ($metaKw !== '') {
                $attributes['meta_keyword'] = $metaKw;
            }
            $this->productAction->updateAttributes(
                [$entityId],
                $attributes,
                $eavStoreId
            );
        } elseif ($entityType === 'category') {
            if ($metaKw !== '') {
                $attributes['meta_keywords'] = $metaKw;
            }
            $connection = $this->resource->getConnection();
            foreach ($attributes as $attrCode => $value) {
                try {
                    $attribute = $this->categoryResource->getAttribute($attrCode);
                    if ($attribute === false || !$attribute->getAttributeId()) {
                        continue;
                    }
                    $table = $attribute->getBackendTable();
                    $data = [
                        'attribute_id' => $attribute->getAttributeId(),
                        'store_id'     => $eavStoreId,
                        'entity_id'    => $entityId,
                        'value'        => $value,
                    ];
                    $connection->insertOnDuplicate($table, $data, ['value']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to save category EAV attribute', [
                        'attribute' => $attrCode,
                        'entity_id' => $entityId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($entityType === 'cms' || $entityType === 'cms_page') {
            $connection = $this->resource->getConnection();
            $cmsTable = $this->resource->getTableName('cms_page');
            $updateData = [];
            if ($metaTitle !== '') {
                $updateData['meta_title'] = $metaTitle;
            }
            if ($metaDesc !== '') {
                $updateData['meta_description'] = $metaDesc;
            }
            if ($metaKw !== '') {
                $updateData['meta_keywords'] = $metaKw;
            }
            if (!empty($updateData)) {
                $connection->update($cmsTable, $updateData, ['page_id = ?' => $entityId]);
            }
        }
    }

    private function flushResolvedRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('panth_seo_resolved');

        $connection->insertOnDuplicate($table, $rows, [
            'meta_title',
            'meta_description',
            'meta_keywords',
            'robots',
            'og_payload',
            'source',
        ]);
    }

    private function resolveRenderStoreIds(int $templateStoreId): array
    {
        if ($templateStoreId > 0) {
            return [$templateStoreId];
        }

        $ids = [];
        try {
            foreach ($this->storeRepository->getList() as $store) {
                $id = (int) $store->getId();
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO Template Apply: store list unavailable', [
                'error' => $e->getMessage(),
            ]);
        }

        return $ids;
    }

    private function patternsUseStoreTokens(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && preg_match('/\{\{\s*store\./', (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasExistingValue(mixed $entity, string $field): bool
    {
        if (!is_object($entity) || !method_exists($entity, 'getData')) {
            return false;
        }

        return trim((string) ($entity->getData($field) ?? '')) !== '';
    }

    private function markApplied(int $templateId): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName('panth_seo_template');
            $connection->update(
                $table,
                [
                    'last_applied_at' => $this->dateTime->gmtDate(),
                    'apply_count'     => new \Zend_Db_Expr('apply_count + 1'),
                ],
                ['template_id = ?' => $templateId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO Template Apply: could not update apply tracking', [
                'template_id' => $templateId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function loadTemplate(int $templateId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('panth_seo_template'))
                ->where('template_id = ?', $templateId)
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    private function decodeConditions(array $template): array
    {
        $raw = $template['conditions_serialized'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize((string) $raw);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
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

    private function iterateCategories(int $storeId, callable $callback): void
    {
        $page = 1;
        do {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([
                'name', 'url_key', 'seo_name', 'meta_title', 'meta_description',
                'og_title', 'og_description', 'og_image',
            ]);

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
}
