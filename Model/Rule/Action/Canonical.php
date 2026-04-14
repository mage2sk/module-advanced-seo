<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Rule\Action;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a canonical URL to the outgoing meta context.
 * Supports absolute URLs or paths relative to the current store base URL.
 */
class Canonical
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $output
     * @return array<string,mixed>
     */
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
                // leave as-is
            }
        }

        $output['canonical'] = $url;
        return $output;
    }
}
