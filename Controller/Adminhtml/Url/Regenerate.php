<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Url;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\Url\RewriteRegenerator;

/**
 * Admin POST controller to regenerate catalog URL rewrites.
 *
 * Route: panth_seo/url/regenerate
 */
class Regenerate extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

    public function __construct(
        Context $context,
        private readonly RewriteRegenerator $rewriteRegenerator
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $entityType = (string) $this->getRequest()->getParam('entity_type', 'all');
        $storeId    = (int) $this->getRequest()->getParam('store_id', 0);

        $validTypes = ['product', 'category', 'all'];
        if (!in_array($entityType, $validTypes, true)) {
            $this->messageManager->addErrorMessage(
                __('Invalid entity type "%1". Allowed: product, category, all.', $entityType)
            );
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $totalGenerated = 0;

            if ($entityType === 'product' || $entityType === 'all') {
                $totalGenerated += $this->rewriteRegenerator->regenerateProducts($storeId);
            }

            if ($entityType === 'category' || $entityType === 'all') {
                $totalGenerated += $this->rewriteRegenerator->regenerateCategories($storeId);
            }

            $this->messageManager->addSuccessMessage(
                __('URL rewrites regenerated successfully. Total: %1 rewrite(s).', $totalGenerated)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Error regenerating URL rewrites: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('*/*/');
    }
}
