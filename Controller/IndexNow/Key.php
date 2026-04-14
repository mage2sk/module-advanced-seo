<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Controller\IndexNow;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;

/**
 * Frontend controller for IndexNow key verification.
 *
 * Route: GET /seo/indexnow/key
 *        GET /seo/indexnow/key?key={api_key}
 *        GET /seo/indexnow/key/key/{api_key}
 *
 * IndexNow protocol requires the declared keyLocation URL to serve the API
 * key as a plain-text response. The IndexNowSubmitter declares keyLocation
 * as "{baseUrl}/seo/indexnow/key" (see Panth\AdvancedSEO\Model\IndexNow\
 * Submitter::submit) so this controller MUST continue to answer that URL
 * and return the configured API key verbatim.
 *
 * When an explicit "key" parameter is supplied (via query string, URL
 * key/value segments, or a trailing ".txt" extension) it must match the
 * configured key exactly, otherwise the request is rejected with 404 so
 * that this endpoint cannot be used as an arbitrary text echo service.
 */
class Key implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly SeoConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $apiKey  = $this->config->getIndexNowApiKey($storeId);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setHeader('Cache-Control', 'no-store, max-age=0', true);

        // Hard-fail when IndexNow is disabled or the key is not configured.
        if ($apiKey === '' || !$this->config->isIndexNowEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        // If the caller supplies a key explicitly, require it to match the
        // configured key (case-insensitive, timing-safe).
        $requestedKey = $this->extractRequestedKey();
        if ($requestedKey !== null && !hash_equals(strtolower($apiKey), strtolower($requestedKey))) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $result->setContents($apiKey);
        return $result;
    }

    /**
     * Extracts an optionally-supplied key from the request. Accepts:
     *   - ?key=abc
     *   - /seo/indexnow/key/key/abc
     *   - /seo/indexnow/key/abc.txt   (trailing path segment, .txt optional)
     *
     * Returns null when no key was supplied by the caller.
     */
    private function extractRequestedKey(): ?string
    {
        $key = (string) $this->request->getParam('key', '');
        if ($key !== '') {
            return $this->stripTxt($key);
        }

        $path = (string) $this->request->getPathInfo();
        if (preg_match('#/seo/indexnow/key/([A-Za-z0-9._-]+)#', $path, $m) === 1) {
            return $this->stripTxt($m[1]);
        }

        return null;
    }

    private function stripTxt(string $value): string
    {
        return preg_replace('/\.txt$/i', '', $value) ?? $value;
    }
}
