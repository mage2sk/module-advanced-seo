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
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
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

            $renderStoreId = $storeId > 0 ? $storeId : 1;
            $context      = ['store_id' => $renderStoreId];
            $titlePattern   = (string) ($template['meta_title'] ?? '');
            $descPattern    = (string) ($template['meta_description'] ?? '');
            $kwPattern      = (string) ($template['meta_keywords'] ?? '');
            $seoNamePattern = (string) ($template['seo_name'] ?? '');
            $robots         = $template['robots'] ?? null;
            $ogTitlePat     = (string) ($template['og_title'] ?? '');
            $ogDescPat      = (string) ($template['og_description'] ?? '');
            $ogImage        = (string) ($template['og_image'] ?? '');

            $totalApplied = 0;
            $pendingRows  = [];

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
                $context,
                &$totalApplied,
                &$pendingRows
            ): void {
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

                    $this->saveToEav(
                        $entityType, $entityId, $storeId,
                        $metaTitle, $metaDesc, $metaKw, $seoName,
                        $ogTitle, $ogDesc, $renderedOgImage,
                        (string) $robots
                    );

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

                    $pendingRows[] = [
                        'store_id'         => $storeId,
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

            $this->messageManager->addSuccessMessage(
                (string) __('Template #%1 applied to %2 %3 entities.', $templateId, $totalApplied, $entityType)
            );

            $this->logger->info(sprintf(
                'Panth SEO Template Apply: template %d applied to %d %s entities (store %d)',
                $templateId,
                $totalApplied,
                $entityType,
                $storeId
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

        $eavStoreId = 0;

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
