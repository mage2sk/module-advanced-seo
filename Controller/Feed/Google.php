<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\Feed;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Feed\GoogleMerchantFeedBuilder;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;

/**
 * Frontend controller: GET /panth_seo/feed/google[?id=<feed_id>]
 *
 * Serves a product feed as application/xml or text/csv.
 * If `id` param is provided, serves the feed from a specific profile.
 * Otherwise, serves the legacy cached Google Merchant feed.
 */
class Google implements HttpGetActionInterface
{
    private const CACHE_PREFIX = 'panth_seo_google_feed_';
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_TAG = 'PANTH_SEO_GOOGLE_FEED';

    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly GoogleMerchantFeedBuilder $feedBuilder,
        private readonly ProfileBasedFeedBuilder $profileFeedBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Config $config,
        private readonly DirectoryList $directoryList,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $result = $this->rawFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();

        // Gate: check if the merchant feed feature is enabled
        if (!$this->config->isEnabled($storeId) || !$this->config->isMerchantFeedEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents('Feed not available.');
            return $result;
        }

        $feedId = (int) $this->request->getParam('id', 0);

        // Profile-based feed: serve from pre-generated file
        if ($feedId > 0) {
            return $this->serveProfileFeed($result, $feedId, $storeId);
        }

        // Legacy: serve from cache or generate on-the-fly
        return $this->serveLegacyFeed($result, $storeId);
    }

    /**
     * Serve a feed from a profile's pre-generated file.
     */
    private function serveProfileFeed(
        \Magento\Framework\Controller\Result\Raw $result,
        int $feedId,
        int $storeId
    ): ResponseInterface|ResultInterface {
        $profile = $this->profileFeedBuilder->loadProfile($feedId);

        if ($profile === null || !(int) ($profile['is_active'] ?? 0)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents('Feed not found or inactive.');
            return $result;
        }

        // Verify the profile belongs to the current store
        if ((int) ($profile['store_id'] ?? 0) !== $storeId) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents('Feed not available for this store.');
            return $result;
        }

        $filename = $profile['filename'] ?? '';
        $format = $profile['format'] ?? 'xml';
        $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);
        // Sanitize filename to prevent path traversal
        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $result->setHttpResponseCode(400);
            $result->setContents('Invalid filename.');
            return $result;
        }
        $filePath = $mediaDir . '/panth_seo/feeds/' . $filename;

        // Verify the resolved path is within the expected directory
        $realBase = realpath($mediaDir . '/panth_seo/feeds');
        if ($realBase !== false && file_exists($filePath)) {
            $realFile = realpath($filePath);
            if ($realFile === false || strpos($realFile, $realBase) !== 0) {
                $result->setHttpResponseCode(403);
                $result->setContents('Access denied.');
                return $result;
            }
        }

        if (!file_exists($filePath)) {
            // Generate on demand if file doesn't exist yet
            try {
                $this->profileFeedBuilder->generate($profile);
            } catch (\Throwable) {
                $result->setHttpResponseCode(500);
                $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
                $result->setContents('Feed generation failed.');
                return $result;
            }
        }

        if (!file_exists($filePath)) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $result->setContents('Feed file not found.');
            return $result;
        }

        $contentType = ($format === 'csv')
            ? 'text/csv; charset=utf-8'
            : 'application/xml; charset=utf-8';

        $result->setHeader('Content-Type', $contentType, true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setHeader('Content-Length', (string) filesize($filePath), true);
        $result->setContents(file_get_contents($filePath));

        return $result;
    }

    /**
     * Serve the legacy cached Google Merchant feed.
     */
    private function serveLegacyFeed(
        \Magento\Framework\Controller\Result\Raw $result,
        int $storeId
    ): ResponseInterface|ResultInterface {
        $cacheKey = self::CACHE_PREFIX . $storeId;
        $cached = $this->cache->load($cacheKey);

        if ($cached !== false && $cached !== '') {
            $xmlContent = $cached;
        } else {
            $xmlContent = $this->feedBuilder->build($storeId);
            $this->cache->save(
                $xmlContent,
                $cacheKey,
                [self::CACHE_TAG],
                self::CACHE_TTL
            );
        }

        $result->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setContents($xmlContent);

        return $result;
    }
}
