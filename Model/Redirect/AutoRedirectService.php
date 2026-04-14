<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared service for programmatically creating redirect records
 * in the panth_seo_redirect table.
 */
class AutoRedirectService
{
    private const TABLE = 'panth_seo_redirect';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly ?StoreManagerInterface $storeManager = null
    ) {
    }

    /**
     * Create a redirect record. Duplicate source+store combinations are
     * silently ignored so callers never need to worry about uniqueness.
     */
    public function createRedirect(
        string $sourcePath,
        string $targetPath,
        int $storeId,
        int $statusCode = 301
    ): void {
        $sourcePath = $this->normalizePath($sourcePath);
        $targetPath = $this->normalizePath($targetPath);

        if ($sourcePath === '' || $targetPath === '' || $sourcePath === $targetPath) {
            return;
        }

        // Security: reject absolute URLs pointing to hosts outside our stores,
        // dangerous URI schemes, and path-traversal segments. Prevents the
        // admin-configured "custom URL" strategy from being abused as an
        // open-redirect / phishing vector.
        if (!$this->isSafeTarget($targetPath)) {
            $this->logger->warning(
                '[PanthSEO] Auto-redirect rejected: unsafe target',
                ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId]
            );
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE);

            // Check for existing redirect with same pattern and store_id to avoid duplicates.
            $select = $connection->select()
                ->from($table, [RedirectRuleInterface::REDIRECT_ID])
                ->where(RedirectRuleInterface::PATTERN . ' = ?', $sourcePath)
                ->where(RedirectRuleInterface::STORE_ID . ' = ?', $storeId)
                ->limit(1);

            if ($connection->fetchOne($select) !== false) {
                $this->logger->debug(
                    '[PanthSEO] Auto-redirect skipped (duplicate)',
                    ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId]
                );
                return;
            }

            $connection->insert($table, [
                RedirectRuleInterface::PATTERN           => $sourcePath,
                RedirectRuleInterface::TARGET            => $targetPath,
                RedirectRuleInterface::STATUS_CODE       => $statusCode,
                RedirectRuleInterface::MATCH_TYPE        => RedirectRuleInterface::MATCH_LITERAL,
                RedirectRuleInterface::IS_ACTIVE         => 1,
                RedirectRuleInterface::STORE_ID          => $storeId,
                RedirectRuleInterface::IS_AUTO_GENERATED => 1,
            ]);

            $this->logger->info(
                '[PanthSEO] Auto-redirect created',
                ['source' => $sourcePath, 'target' => $targetPath, 'store_id' => $storeId, 'status' => $statusCode]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthSEO] Failed to create auto-redirect',
                ['source' => $sourcePath, 'target' => $targetPath, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Validate a redirect target at write time so malicious / malformed
     * targets never reach the panth_seo_redirect table. The runtime
     * Predispatch observer also checks this, but catching it at the
     * source keeps the table clean and stops an attacker-controlled admin
     * setting from persisting an open-redirect row.
     */
    private function isSafeTarget(string $target): bool
    {
        if ($target === '' || $target === '/') {
            return true;
        }

        // Reject dangerous schemes outright.
        if (preg_match('#^(javascript|data|vbscript|file):#i', $target)) {
            return false;
        }

        // Absolute URL — only allow if host matches one of our stores.
        if (preg_match('#^https?://#i', $target)) {
            if ($this->storeManager === null) {
                return false;
            }
            $host = parse_url($target, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return false;
            }
            foreach ($this->storeManager->getStores(true) as $store) {
                $storeHost = parse_url($store->getBaseUrl(), PHP_URL_HOST);
                if (is_string($storeHost) && strcasecmp($host, $storeHost) === 0) {
                    return true;
                }
            }
            return false;
        }

        // Protocol-relative (//evil.com/...) is always external.
        if (str_starts_with($target, '//')) {
            return false;
        }

        // Path traversal segments.
        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        // Control characters / newlines (CRLF injection).
        if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return false;
        }

        return true;
    }

    /**
     * Strip leading/trailing slashes and whitespace to normalise paths.
     * A bare "/" (homepage) is preserved as "/" so homepage-target redirects work.
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B");

        // Preserve homepage target: a bare "/" must not become an empty string.
        if ($path === '/') {
            return '/';
        }

        return trim($path, '/');
    }
}
