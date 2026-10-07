<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Store\Model\StoreManagerInterface;

class StoreToken implements TokenInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        try {
            $storeId = (int) ($context['store_id'] ?? $this->storeManager->getStore()->getId());
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return '';
        }

        return match (strtolower($argument ?? 'name')) {
            'id'      => (string) $store->getId(),
            'code'    => (string) $store->getCode(),
            'website' => (string) $store->getWebsiteId(),
            'url'     => (string) $store->getBaseUrl(),
            default   => (string) $store->getName(),
        };
    }
}
