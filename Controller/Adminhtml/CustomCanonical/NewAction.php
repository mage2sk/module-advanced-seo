<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;

class NewAction extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::custom_canonical';

    public function __construct(
        Context $context,
        private readonly ForwardFactory $forwardFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
