<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\FilterMeta;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class NewAction extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::filter_meta';

    public function __construct(Context $context, private readonly ForwardFactory $forwardFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $forward = $this->forwardFactory->create();
        return $forward->forward('edit');
    }
}
