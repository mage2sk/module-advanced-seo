<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\ViewModel;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Api\StoreRepositoryInterface;

class StoreScopeSwitcher implements ArgumentInterface
{
    public const PARAM = 'store';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly BackendUrl $backendUrl
    ) {
    }

    public function getCurrentStoreId(): int
    {
        $raw = $this->request->getParam(self::PARAM);
        if ($raw === null || $raw === '') {
            return 0;
        }

        $storeId = (int) $raw;

        return $storeId > 0 && $this->storeExists($storeId) ? $storeId : 0;
    }

    public function getStoreOptions(): array
    {
        $options = [['value' => 0, 'label' => (string) __('All Store Views (default scope)')]];

        try {
            foreach ($this->storeRepository->getList() as $store) {
                $id = (int) $store->getId();
                if ($id === 0) {
                    continue;
                }
                $options[] = ['value' => $id, 'label' => (string) $store->getName()];
            }
        } catch (\Throwable) {
        }

        return $options;
    }

    public function getSwitchUrl(int $storeId): string
    {
        $params = $this->request->getParams();
        unset($params[self::PARAM], $params['key']);
        if ($storeId > 0) {
            $params[self::PARAM] = $storeId;
        }

        return $this->backendUrl->getUrl('*/*/*', $params);
    }

    public function hasMultipleStores(): bool
    {
        return count($this->getStoreOptions()) > 2;
    }

    private function storeExists(int $storeId): bool
    {
        try {
            $this->storeRepository->getById($storeId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
