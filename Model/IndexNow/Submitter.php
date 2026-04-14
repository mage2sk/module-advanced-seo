<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\IndexNow;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Submits URLs to the IndexNow API for instant indexing by Bing, Yandex, and other
 * participating search engines.
 *
 * @see https://www.indexnow.org/documentation
 */
class Submitter
{
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';
    private const MAX_BATCH_SIZE = 10000;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly SeoConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Submit a list of URLs to IndexNow for the given store.
     *
     * URLs are automatically batched in chunks of up to 10,000 (the protocol maximum).
     *
     * @param string[] $urls Fully-qualified URLs to submit.
     * @param int      $storeId
     *
     * @return bool True when every batch succeeds (HTTP 200/202), false otherwise.
     */
    public function submit(array $urls, int $storeId): bool
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return true;
        }

        $apiKey = $this->config->getIndexNowApiKey($storeId);
        if ($apiKey === '') {
            $this->logger->warning('Panth SEO IndexNow: API key is not configured; skipping submission.');
            return false;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO IndexNow: cannot resolve store.', ['error' => $e->getMessage()]);
            return false;
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $keyLocation = $baseUrl . '/seo/indexnow/key';

        $success = true;
        foreach (array_chunk($urls, self::MAX_BATCH_SIZE) as $batch) {
            if (!$this->sendBatch($batch, $host, $apiKey, $keyLocation)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * POST a single batch to the IndexNow endpoint.
     *
     * @param string[] $urls
     */
    private function sendBatch(array $urls, string $host, string $apiKey, string $keyLocation): bool
    {
        $payload = [
            'host'        => $host,
            'key'         => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList'     => $urls,
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json; charset=utf-8');
            $curl->setOption(CURLOPT_TIMEOUT, 15);
            $curl->post(self::ENDPOINT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $status = $curl->getStatus();
            if ($status >= 200 && $status < 300) {
                $this->logger->info('Panth SEO IndexNow: submitted ' . count($urls) . ' URL(s).', [
                    'host'   => $host,
                    'status' => $status,
                ]);
                return true;
            }

            $this->logger->warning('Panth SEO IndexNow: unexpected HTTP status.', [
                'status' => $status,
                'body'   => $curl->getBody(),
                'count'  => count($urls),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO IndexNow: request failed.', [
                'error' => $e->getMessage(),
                'count' => count($urls),
            ]);
            return false;
        }
    }
}
