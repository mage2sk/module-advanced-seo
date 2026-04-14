<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Redirect;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Redirect\AutoRedirectService;
use Psr\Log\LoggerInterface;

/**
 * Observer on `catalog_category_delete_before`.
 *
 * Creates a 301 redirect from the category URL to its parent category URL.
 * If the category is a root or top-level category, redirects to homepage.
 */
class CategoryDeleteBefore implements ObserverInterface
{
    public function __construct(
        private readonly AutoRedirectService $autoRedirectService,
        private readonly SeoConfig $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled() || !$this->config->isAutoRedirectEnabled()) {
                return;
            }

            /** @var Category $category */
            $event = $observer->getEvent();
            $category = $event->getCategory() ?? $event->getObject() ?? $event->getEntity();
            if (!$category instanceof Category || !$category->getId()) {
                return;
            }

            $categoryId = (int) $category->getId();
            $rewrites = $this->getCategoryUrlRewrites($categoryId);

            if ($rewrites === []) {
                return;
            }

            $parentId = (int) $category->getParentId();
            $targetPath = $this->resolveParentUrl($parentId);

            foreach ($rewrites as $rewrite) {
                $storeId = (int) ($rewrite['store_id'] ?? 0);
                $sourcePath = (string) ($rewrite['request_path'] ?? '');
                if ($sourcePath === '') {
                    continue;
                }
                $this->autoRedirectService->createRedirect($sourcePath, $targetPath, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PanthSEO] CategoryDeleteBefore observer failed',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Fetch all URL rewrites for the given category.
     *
     * @return array<int, array{request_path: string, store_id: int}>
     */
    private function getCategoryUrlRewrites(int $categoryId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('url_rewrite');

        $select = $connection->select()
            ->from($table, ['request_path', 'store_id'])
            ->where('entity_type = ?', 'category')
            ->where('entity_id = ?', $categoryId);

        return $connection->fetchAll($select);
    }

    /**
     * Resolve the parent category URL. Returns homepage path for root-level categories.
     *
     * Root category (level 1) and default category (level 2) have no storefront URLs,
     * so we redirect to homepage in those cases.
     */
    private function resolveParentUrl(int $parentId): string
    {
        if ($parentId <= 1) {
            return '/';
        }

        try {
            $connection = $this->resourceConnection->getConnection();

            // Check if parent is a root category (level <= 2 means no storefront URL)
            $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');
            $level = $connection->fetchOne(
                $connection->select()
                    ->from($categoryTable, ['level'])
                    ->where('entity_id = ?', $parentId)
            );

            if ($level === false || (int) $level <= 1) {
                return '/';
            }

            $urlTable = $this->resourceConnection->getTableName('url_rewrite');
            $parentPath = $connection->fetchOne(
                $connection->select()
                    ->from($urlTable, ['request_path'])
                    ->where('entity_type = ?', 'category')
                    ->where('entity_id = ?', $parentId)
                    ->where('metadata IS NULL')
                    ->limit(1)
            );

            return $parentPath !== false && $parentPath !== '' ? (string) $parentPath : '/';
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[PanthSEO] Could not resolve parent category URL',
                ['parent_id' => $parentId, 'error' => $e->getMessage()]
            );
            return '/';
        }
    }
}
