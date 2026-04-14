<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Export a CSV file showing current vs. new meta values for ALL matching entities.
 *
 * Columns: entity_id, identifier (sku for products / name for categories),
 *          current_meta_title, new_meta_title, current_meta_description, new_meta_description
 *
 * Entities are loaded in paginated batches to bound memory usage.
 */
class ExportCsv extends AbstractAction implements HttpGetActionInterface
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
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function execute()
    {
        $templateId = (int) $this->getRequest()->getParam('template_id');

        if ($templateId <= 0) {
            $this->messageManager->addErrorMessage((string) __('Missing template_id parameter.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        try {
            $template = $this->loadTemplate($templateId);
            if ($template === null) {
                $this->messageManager->addErrorMessage((string) __('Template not found.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }

            $entityType   = (string) ($template['entity_type'] ?? 'product');
            $storeId      = (int) ($template['store_id'] ?? 0);
            $conditions   = $this->decodeConditions($template);
            $context      = ['store_id' => $storeId];
            $titlePattern = (string) ($template['meta_title'] ?? '');
            $descPattern  = (string) ($template['meta_description'] ?? '');

            $tmpDir   = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
            $fileName = 'seo_template_preview_' . $templateId . '_' . date('Ymd_His') . '.csv';
            $stream   = $tmpDir->openFile($fileName, 'w');

            $identifierLabel = $entityType === 'product' ? 'sku' : 'name';
            $stream->writeCsv([
                'entity_id',
                $identifierLabel,
                'current_meta_title',
                'new_meta_title',
                'current_meta_description',
                'new_meta_description',
            ]);

            $writeRow = function (iterable $entities) use (
                $titlePattern,
                $descPattern,
                $conditions,
                $storeId,
                $context,
                $entityType,
                $stream
            ): void {
                foreach ($entities as $entity) {
                    if (!$this->conditionEvaluator->evaluate($conditions, $entity, $storeId)) {
                        continue;
                    }

                    $entityId     = (int) $entity->getId();
                    $identifier   = $entityType === 'product'
                        ? (string) $entity->getData('sku')
                        : (string) $entity->getData('name');
                    $currentTitle = (string) ($entity->getData('meta_title') ?? '');
                    $currentDesc  = (string) ($entity->getData('meta_description') ?? '');

                    $newTitle = $titlePattern !== ''
                        ? $this->templateRenderer->render($titlePattern, $entity, $context)
                        : '';
                    $newDesc  = $descPattern !== ''
                        ? $this->templateRenderer->render($descPattern, $entity, $context)
                        : '';

                    $stream->writeCsv([
                        $entityId,
                        $identifier,
                        $currentTitle,
                        $newTitle,
                        $currentDesc,
                        $newDesc,
                    ]);
                }
            };

            match ($entityType) {
                'product'  => $this->iterateProducts($storeId, $writeRow),
                'category' => $this->iterateCategories($storeId, $writeRow),
                default    => null,
            };

            $stream->close();

            return $this->fileFactory->create(
                $fileName,
                [
                    'type'  => 'filename',
                    'value' => $fileName,
                    'rm'    => true,
                ],
                DirectoryList::TMP
            );
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO Template ExportCsv failed', ['exception' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('CSV export failed: %1', $e->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
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

    /**
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
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

    /**
     * Iterate all products for a store in paginated batches.
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
                'name', 'url_key', 'sku', 'meta_title', 'meta_description',
                'visibility', 'type_id', 'attribute_set_id',
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
     * Iterate all categories for a store in paginated batches.
     *
     * @param callable(iterable): void $callback
     */
    private function iterateCategories(int $storeId, callable $callback): void
    {
        $page = 1;
        do {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([
                'name', 'url_key', 'meta_title', 'meta_description',
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
}
