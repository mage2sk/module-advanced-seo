<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\AiPrompt;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Backend\App\Action\Context;

class NewAction extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::manage';

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
