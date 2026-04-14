<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Adminhtml\Rule;

use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Model\ResourceModel\Rule as RuleResource;
use Panth\AdvancedSEO\Model\Rule as RuleModel;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Magento\Backend\App\Action\Context;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_AdvancedSEO::rules';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Registry $registry,
        private readonly RuleResource $ruleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $model = new \Magento\Framework\DataObject();
        if ($id > 0) {
            $data = [];
            $tmp = $this->ruleResource->getConnection()->fetchRow(
                $this->ruleResource->getConnection()->select()
                    ->from($this->ruleResource->getMainTable())
                    ->where('rule_id = ?', $id)
            );
            if ($tmp) {
                $data = $tmp;
            }
            $model->setData($data);
        }
        $this->registry->register('panth_seo_rule', $model, true);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_AdvancedSEO::rule');
        $page->getConfig()->getTitle()->prepend($id ? __('Edit Rule') : __('New Rule'));
        return $page;
    }
}
