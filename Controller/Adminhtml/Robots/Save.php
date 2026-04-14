<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Robots;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Config\Model\Config\Factory as ConfigFactory;
use Magento\Backend\App\Action\Context;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::robots';

    public function __construct(Context $context, private readonly ConfigFactory $configFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $content = (string)$this->getRequest()->getParam('robots_txt', '');
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);

        try {
            $config = $this->configFactory->create();
            $config->setDataByPath('design/search_engine_robots/custom_instructions', $content);
            if ($storeId > 0) {
                $config->setScope('stores');
                $config->setStore($storeId);
            } else {
                $config->setScope('default');
            }
            $config->save();
            $this->messageManager->addSuccessMessage(__('robots.txt saved.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
