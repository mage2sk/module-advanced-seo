<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Hreflang;

use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Audit\Fix\HreflangGroupValidator;

class GroupGuardPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly HreflangGroupValidator $validator,
        private readonly RequestInterface $request,
        private readonly ManagerInterface $messageManager,
        private readonly RedirectFactory $redirectFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function aroundExecute($subject, callable $proceed)
    {
        if (!$this->config->isAuditFixEnabled(Config::AUDIT_FIX_HREFLANG_GROUP_GUARD)) {
            return $proceed();
        }
        $members = $this->request->getParam('hreflang_members');
        if (!is_array($members)) {
            return $proceed();
        }
        $duplicates = $this->validator->duplicateLocales(
            $members,
            fn (int $storeId): string => str_replace(
                '_',
                '-',
                (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId)
            )
        );
        if ($duplicates === []) {
            return $proceed();
        }
        foreach ($duplicates as $locale => $owners) {
            $this->messageManager->addErrorMessage(__(
                'Hreflang group not saved: %1 members use the locale "%2" (store:entity %3). A group links '
                . 'translations of one page, so each locale may appear once. Use internal links for topic clusters.',
                count($owners),
                $locale,
                implode(', ', $owners)
            ));
        }
        $groupId  = (int) $this->request->getParam('group_id');
        $redirect = $this->redirectFactory->create();

        return $groupId > 0
            ? $redirect->setPath('*/*/edit', ['id' => $groupId])
            : $redirect->setPath('*/*/');
    }
}
