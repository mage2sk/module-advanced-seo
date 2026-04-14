<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\SearchConsole;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\SearchConsole\IndexingClient;
use Psr\Log\LoggerInterface;

/**
 * On product/category/CMS save_after, submits the changed entity URL to the
 * Google Indexing API if enabled.
 *
 * Uses the same shutdown-function batching pattern as the IndexNow observer:
 * URLs are accumulated in a static array and flushed once at shutdown to
 * avoid blocking the admin save action.
 */
class EntityChangeObserver implements ObserverInterface
{
    /**
     * Accumulated URLs. Flushed once via register_shutdown_function.
     *
     * @var string[]
     */
    private static array $pendingUrls = [];

    private static bool $shutdownRegistered = false;

    private static ?IndexingClient $clientRef = null;
    private static ?LoggerInterface $loggerRef = null;

    public function __construct(
        private readonly IndexingClient $indexingClient,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
        self::$clientRef = $this->indexingClient;
        self::$loggerRef = $this->logger;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->seoConfig->isEnabled()) {
            return;
        }

        if (!$this->indexingClient->isEnabled()) {
            return;
        }

        $event = $observer->getEvent();
        $url = null;

        if ($product = $event->getData('product')) {
            /** @var Product $product */
            if (!$product->getId()) {
                return;
            }
            $url = $this->getProductUrl($product);
        } elseif ($category = $event->getData('category')) {
            /** @var Category $category */
            if (!$category->getId()) {
                return;
            }
            $url = (string) $category->getUrl();
        } elseif (($page = $event->getData('object')) && $page instanceof Page) {
            if (!$page->getId()) {
                return;
            }
            $url = $this->getCmsPageUrl($page);
        }

        if ($url === null || $url === '') {
            return;
        }

        self::$pendingUrls[] = $url;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushPendingUrls']);
        }
    }

    /**
     * Flush all collected URLs to the Google Indexing API.
     * Called automatically via register_shutdown_function.
     */
    public static function flushPendingUrls(): void
    {
        if (self::$clientRef === null) {
            return;
        }

        $urls = array_values(array_unique(self::$pendingUrls));
        foreach ($urls as $url) {
            try {
                self::$clientRef->submitUrl($url, 'URL_UPDATED');
            } catch (\Throwable $e) {
                self::$loggerRef?->error('Panth SEO Indexing API flush failed.', [
                    'error' => $e->getMessage(),
                    'url'   => $url,
                ]);
            }
        }

        self::$pendingUrls = [];
    }

    private function getProductUrl(Product $product): string
    {
        try {
            return (string) $product->getProductUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getCmsPageUrl(Page $page): string
    {
        try {
            $storeIds = $page->getStoreId();
            $storeId = is_array($storeIds) ? (int) ($storeIds[0] ?? 0) : (int) $storeIds;
            if ($storeId === 0) {
                $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId();
            }
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
            $identifier = $page->getIdentifier();
            return $baseUrl . '/' . ltrim((string) $identifier, '/');
        } catch (\Throwable) {
            return '';
        }
    }
}
