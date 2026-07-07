<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Action;

use Magento\Store\Model\StoreManagerInterface;

class Canonical
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function apply(array $params, array $output): array
    {
        $url = trim((string)($params['canonical'] ?? ''));
        if ($url === '') {
            return $output;
        }

        if (!preg_match('#^https?://#i', $url)) {
            try {
                $base = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
                $url = $base . '/' . ltrim($url, '/');
            } catch (\Throwable $e) {
            }
        }

        $output['canonical'] = $url;
        return $output;
    }
}
