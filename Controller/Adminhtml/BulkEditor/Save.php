<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Backend\App\Action\Context;

/**
 * Accepts an array `rows` with {entity_id, meta_title, meta_description}
 * and persists them against catalog products.
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    public function __construct(Context $context, private readonly ProductRepositoryInterface $productRepository)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $rows = (array)$this->getRequest()->getParam('rows', []);
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        $saved = 0;
        foreach ($rows as $row) {
            $id = (int)($row['entity_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $product = $this->productRepository->getById($id, true, $storeId);
                $product->setStoreId($storeId);
                if (isset($row['meta_title'])) {
                    $product->setMetaTitle((string)$row['meta_title']);
                }
                if (isset($row['meta_description'])) {
                    $product->setMetaDescription((string)$row['meta_description']);
                }
                if (isset($row['meta_keyword'])) {
                    $product->setMetaKeyword((string)$row['meta_keyword']);
                }
                $this->productRepository->save($product);
                $saved++;
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage(__('Entity %1: %2', $id, $e->getMessage()));
            }
        }
        $this->messageManager->addSuccessMessage(__('%1 row(s) saved.', $saved));
        return $resultRedirect->setPath('*/*/');
    }
}
