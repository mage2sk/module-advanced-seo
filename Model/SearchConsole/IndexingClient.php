<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\SearchConsole;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class IndexingClient
{
    private const ENDPOINT      = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const SCOPE         = 'https://www.googleapis.com/auth/indexing';
    private const MAX_DAILY     = 200;
    private const JWT_EXPIRY    = 3600;

    public const XML_INDEXING_ENABLED     = 'panth_seo/search_console/indexing_api_enabled';
    public const XML_SERVICE_ACCOUNT_JSON = 'panth_seo/search_console/service_account_json';

    private static int $dailyCount = 0;

    private ?string $accessToken = null;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function submitUrl(string $url, string $type = 'URL_UPDATED'): bool
    {
        if (self::$dailyCount >= self::MAX_DAILY) {
            $this->logger->warning('Panth SEO Indexing API: daily rate limit reached.', [
                'limit' => self::MAX_DAILY,
                'url'   => $url,
            ]);
            return false;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return false;
        }

        $payload = json_encode([
            'url'  => $url,
            'type' => $type,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $token);
            $curl->setOption(CURLOPT_TIMEOUT, 15);
            $curl->post(self::ENDPOINT, $payload);

            $status = $curl->getStatus();
            self::$dailyCount++;

            if ($status >= 200 && $status < 300) {
                $this->logger->info('Panth SEO Indexing API: submitted URL.', [
                    'url'    => $url,
                    'type'   => $type,
                    'status' => $status,
                ]);
                return true;
            }

            $this->logger->warning('Panth SEO Indexing API: unexpected HTTP status.', [
                'url'    => $url,
                'type'   => $type,
                'status' => $status,
                'body'   => mb_substr($curl->getBody(), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO Indexing API: request failed.', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getAccessToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $serviceAccountJson = $this->getServiceAccountJson();
        if ($serviceAccountJson === null) {
            $this->logger->error('Panth SEO Indexing API: service account JSON is not configured or invalid.');
            return null;
        }

        $jwt = $this->createJwt($serviceAccountJson);
        if ($jwt === null) {
            return null;
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $curl->setOption(CURLOPT_TIMEOUT, 10);
            $curl->post(self::TOKEN_URL, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]));

            $status = $curl->getStatus();
            if ($status !== 200) {
                $this->logger->error('Panth SEO Indexing API: token exchange failed.', [
                    'status' => $status,
                    'body'   => mb_substr($curl->getBody(), 0, 500),
                ]);
                return null;
            }

            $response = json_decode($curl->getBody(), true, 16, JSON_THROW_ON_ERROR);
            $this->accessToken = (string) ($response['access_token'] ?? '');

            if ($this->accessToken === '') {
                $this->logger->error('Panth SEO Indexing API: empty access_token in response.');
                $this->accessToken = null;
                return null;
            }

            return $this->accessToken;
        } catch (\Throwable $e) {
            $this->logger->error('Panth SEO Indexing API: token request failed.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function createJwt(array $sa): ?string
    {
        $privateKey = (string) ($sa['private_key'] ?? '');
        $clientEmail = (string) ($sa['client_email'] ?? '');

        if ($privateKey === '' || $clientEmail === '') {
            $this->logger->error('Panth SEO Indexing API: service account missing private_key or client_email.');
            return null;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $claim = $this->base64UrlEncode(json_encode([
            'iss'   => $clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + self::JWT_EXPIRY,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header . '.' . $claim;

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            $this->logger->error('Panth SEO Indexing API: cannot parse private key from service account.');
            return null;
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->logger->error('Panth SEO Indexing API: JWT signing failed.');
            return null;
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function getServiceAccountJson(): ?array
    {
        $encrypted = (string) ($this->scopeConfig->getValue(
            self::XML_SERVICE_ACCOUNT_JSON,
            ScopeInterface::SCOPE_STORE
        ) ?? '');

        if ($encrypted === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($encrypted);
        if ($decrypted === '' || $decrypted === false) {
            return null;
        }

        try {
            $parsed = json_decode($decrypted, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
