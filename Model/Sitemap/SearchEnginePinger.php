<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Pings search engines after a sitemap rebuild so they discover updated content
 * as quickly as possible. Each engine can be individually toggled via admin config.
 */
class SearchEnginePinger
{
    private const ENGINES = [
        'google' => [
            'url'    => 'https://www.google.com/ping?sitemap=',
            'config' => 'panth_seo/sitemap/ping_google',
        ],
        'bing' => [
            'url'    => 'https://www.bing.com/ping?sitemap=',
            'config' => 'panth_seo/sitemap/ping_bing',
        ],
    ];

    /** @var int */
    private const TIMEOUT = 15;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ping all enabled search engines with the given sitemap URL.
     *
     * @return array<string, array{success: bool, status: int}>
     */
    public function ping(string $sitemapUrl): array
    {
        $results = [];

        foreach (self::ENGINES as $engine => $meta) {
            if (!$this->scopeConfig->isSetFlag($meta['config'])) {
                $this->logger->debug(
                    sprintf('[PanthSEO] sitemap ping to %s skipped (disabled in config)', $engine)
                );
                continue;
            }

            $pingUrl = $meta['url'] . urlencode($sitemapUrl);

            try {
                /** @var Curl $curl */
                $curl = $this->curlFactory->create();
                $curl->setTimeout(self::TIMEOUT);
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->setOption(CURLOPT_MAXREDIRS, 3);
                $curl->get($pingUrl);

                $status  = $curl->getStatus();
                $success = $status >= 200 && $status < 300;

                $results[$engine] = [
                    'success' => $success,
                    'status'  => $status,
                ];

                $this->logger->info(
                    sprintf(
                        '[PanthSEO] sitemap ping to %s: HTTP %d (%s) — %s',
                        $engine,
                        $status,
                        $success ? 'OK' : 'FAIL',
                        $pingUrl
                    )
                );
            } catch (\Throwable $e) {
                $results[$engine] = [
                    'success' => false,
                    'status'  => 0,
                ];

                $this->logger->error(
                    sprintf(
                        '[PanthSEO] sitemap ping to %s failed: %s — %s',
                        $engine,
                        $e->getMessage(),
                        $pingUrl
                    )
                );
            }
        }

        return $results;
    }
}
