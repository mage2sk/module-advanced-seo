<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Preview what a template will produce for the first 20 matching entities.
 *
 * Returns JSON:
 *   { success: true, items: [ {entity_id, name, current_title, preview_title, current_desc, preview_desc}, ... ] }
 */
class Preview extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    private const PREVIEW_LIMIT = 20;

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly TemplateRenderer $templateRenderer,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();
        $templateId = (int) $this->getRequest()->getParam('template_id');

        if ($templateId <= 0) {
            return $result->setData(['success' => false, 'message' => 'Missing template_id parameter.']);
        }

        try {
            $template = $this->loadTemplate($templateId);
            if ($template === null) {
                return $result->setData(['success' => false, 'message' => 'Template not found.']);
            }

            $entityType   = (string) ($template['entity_type'] ?? 'product');
            $storeId      = (int) ($template['store_id'] ?? 0);
            $conditions   = $this->decodeConditions($template);
            $context      = ['store_id' => $storeId];
            $titlePattern = (string) ($template['meta_title'] ?? '');
            $descPattern  = (string) ($template['meta_description'] ?? '');

            /** @var list<array{entity_id: int, name: string, current_title: string, preview_title: string, current_desc: string, preview_desc: string}> $items */
            $items = [];

            $collectMatches = function (iterable $entities) use (
                $titlePattern,
                $descPattern,
                $conditions,
                $storeId,
                $context,
                &$items
            ): void {
                foreach ($entities as $entity) {
                    if (count($items) >= self::PREVIEW_LIMIT) {
                        return;
                    }

                    if (!$this->conditionEvaluator->evaluate($conditions, $entity, $storeId)) {
                        continue;
                    }

                    $entityId     = (int) $entity->getId();
                    $name         = (string) $entity->getData('name');
                    $currentTitle = (string) ($entity->getData('meta_title') ?? '');
                    $currentDesc  = (string) ($entity->getData('meta_description') ?? '');

                    $previewTitle = $titlePattern !== ''
                        ? $this->templateRenderer->render($titlePattern, $entity, $context)
                        : '';
                    $previewDesc  = $descPattern !== ''
                        ? $this->templateRenderer->render($descPattern, $entity, $context)
                        : '';

                    $items[] = [
                        'entity_id'     => $entityId,
                        'name'          => $name,
                        'current_title' => $currentTitle,
                        'preview_title' => $previewTitle,
                        'current_desc'  => $currentDesc,
                        'preview_desc'  => $previewDesc,
                    ];
                }
            };

            match ($entityType) {
                'product'  => $this->iterateProducts($storeId, $collectMatches, $items),
                'category' => $this->iterateCategories($storeId, $collectMatches, $items),
                default    => null,
            };

            return $result->setData(['success' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO Template Preview failed', ['exception' => $e->getMessage()]);
            return $result->setData(['success' => false, 'message' => 'An internal error occurred while generating the preview.']);
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
     * Iterate products in pages until we have enough preview items.
     *
     * @param callable(iterable): void $callback
     * @param list<array<string,mixed>> $items Collected items (checked for early termination)
     */
    private function iterateProducts(int $storeId, callable $callback, array &$items): void
    {
        $page = 1;
        do {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([
                'name', 'url_key', 'sku', 'meta_title', 'meta_description',
                'visibility', 'type_id', 'attribute_set_id',
            ]);
            $collection->setPageSize(self::PREVIEW_LIMIT * 2);
            $collection->setCurPage($page);

            $loaded = $collection->getItems();
            if ($loaded === []) {
                break;
            }

            $callback($loaded);

            $lastPage = (int) $collection->getLastPageNumber();
            $page++;
        } while ($page <= $lastPage && count($items) < self::PREVIEW_LIMIT);
    }

    /**
     * Iterate categories in pages until we have enough preview items.
     *
     * @param callable(iterable): void $callback
     * @param list<array<string,mixed>> $items Collected items (checked for early termination)
     */
    private function iterateCategories(int $storeId, callable $callback, array &$items): void
    {
        $page = 1;
        do {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([
                'name', 'url_key', 'meta_title', 'meta_description',
            ]);
            $collection->setPageSize(self::PREVIEW_LIMIT * 2);
            $collection->setCurPage($page);

            $loaded = $collection->getItems();
            if ($loaded === []) {
                break;
            }

            $callback($loaded);

            $lastPage = (int) $collection->getLastPageNumber();
            $page++;
        } while ($page <= $lastPage && count($items) < self::PREVIEW_LIMIT);
    }
}
