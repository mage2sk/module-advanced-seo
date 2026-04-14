<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Rule;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Backend\App\Action\Context;

class NewAction extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::rules';

    public function __construct(Context $context, private readonly ForwardFactory $forwardFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
