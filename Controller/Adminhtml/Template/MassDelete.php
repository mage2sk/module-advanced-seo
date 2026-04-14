<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Template;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Backend\App\Action\Context;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::templates';

    public function __construct(Context $context, private readonly ResourceConnection $resource)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $ids = (array)$this->getRequest()->getParam('selected', []);
        $ids = array_filter(array_map('intval', $ids));
        $resultRedirect = $this->resultRedirectFactory->create();
        if ($ids) {
            try {
                $this->resource->getConnection()->delete(
                    $this->resource->getTableName('panth_seo_template'),
                    ['template_id IN (?)' => $ids]
                );
                $this->messageManager->addSuccessMessage(__('%1 template(s) deleted.', count($ids)));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $resultRedirect->setPath('*/*/');
    }
}
