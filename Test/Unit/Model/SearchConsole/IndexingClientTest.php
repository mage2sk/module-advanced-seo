<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\SearchConsole;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Panth\AdvancedSEO\Model\SearchConsole\IndexingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexingClientTest extends TestCase
{
    private static string $privateKey = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = '';
        openssl_pkey_export($key, $pem);
        self::$privateKey = $pem;
    }

    private function serviceAccount(array $override = []): string
    {
        return (string) json_encode(array_merge([
            'private_key' => self::$privateKey,
            'client_email' => 'svc@example.iam.gserviceaccount.com',
        ], $override));
    }

    private function curl(int $status, string $body = ''): Curl
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        return $curl;
    }

    private function client(
        array $curls,
        ?string $decryptedJson,
        LoggerInterface $logger,
        ?FlagManager $flagManager = null,
        string $configValue = 'encrypted'
    ): IndexingClient {
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...$curls);

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($configValue);
        $config->method('isSetFlag')->willReturn(true);

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn($decryptedJson ?? '');

        if ($flagManager === null) {
            $flagManager = $this->createStub(FlagManager::class);
            $flagManager->method('getFlagData')->willReturn(null);
        }

        return new IndexingClient($factory, $config, $encryptor, $logger, $flagManager);
    }

    public function testIsEnabledReadsConfigFlag(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->expects($this->once())->method('isSetFlag')
            ->with(IndexingClient::XML_INDEXING_ENABLED, 'store', 3)->willReturn(true);

        $client = new IndexingClient(
            $this->createStub(CurlFactory::class),
            $config,
            $this->createStub(EncryptorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FlagManager::class)
        );

        $this->assertTrue($client->isEnabled(3));
    }

    public function testDailyLimitBlocksSubmission(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturn(['date' => gmdate('Y-m-d'), 'count' => 200]);

        $factory = $this->createMock(CurlFactory::class);
        $factory->expects($this->never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO Indexing API: daily rate limit reached.', ['limit' => 200, 'url' => 'https://x.test/']);

        $client = new IndexingClient(
            $factory,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(EncryptorInterface::class),
            $logger,
            $flags
        );

        $this->assertFalse($client->submitUrl('https://x.test/'));
    }

    public function testMissingServiceAccountConfig(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: service account JSON is not configured or invalid.');

        $this->assertFalse($this->client([], null, $logger, null, '')->submitUrl('https://x.test/'));
    }

    public function testUndecryptableOrInvalidJsonServiceAccount(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('error')
            ->with('Panth SEO Indexing API: service account JSON is not configured or invalid.');

        $this->assertFalse($this->client([], null, $logger)->submitUrl('https://x.test/'));
        $this->assertFalse($this->client([], '{broken', $logger)->submitUrl('https://x.test/'));
        $this->assertFalse($this->client([], '"just a string"', $logger)->submitUrl('https://x.test/'));
    }

    public function testServiceAccountMissingFields(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: service account missing private_key or client_email.');

        $json = $this->serviceAccount(['client_email' => '']);
        $this->assertFalse($this->client([], $json, $logger)->submitUrl('https://x.test/'));
    }

    public function testUnparsablePrivateKey(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: cannot parse private key from service account.');

        $json = $this->serviceAccount(['private_key' => 'not a key']);
        $this->assertFalse($this->client([], $json, $logger)->submitUrl('https://x.test/'));
    }

    public function testTokenExchangeHttpFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: token exchange failed.', ['status' => 401, 'body' => 'denied']);

        $client = $this->client([$this->curl(401, 'denied')], $this->serviceAccount(), $logger);
        $this->assertFalse($client->submitUrl('https://x.test/'));
    }

    public function testEmptyAccessToken(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: empty access_token in response.');

        $client = $this->client([$this->curl(200, '{"access_token":""}')], $this->serviceAccount(), $logger);
        $this->assertFalse($client->submitUrl('https://x.test/'));
    }

    public function testMalformedTokenResponse(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: token request failed.', $this->arrayHasKey('error'));

        $client = $this->client([$this->curl(200, 'not json')], $this->serviceAccount(), $logger);
        $this->assertFalse($client->submitUrl('https://x.test/'));
    }

    public function testSuccessfulSubmissionSignsJwtAndReusesToken(): void
    {
        $tokenCurl = $this->createMock(Curl::class);
        $tokenCurl->method('getStatus')->willReturn(200);
        $tokenCurl->method('getBody')->willReturn('{"access_token":"tok123"}');
        $assertion = '';
        $tokenCurl->expects($this->once())->method('post')
            ->with('https://oauth2.googleapis.com/token', $this->callback(
                static function (string $body) use (&$assertion): bool {
                    parse_str($body, $parts);
                    $assertion = (string) ($parts['assertion'] ?? '');
                    return ($parts['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
                }
            ));

        $headers = [];
        $submitCurl = $this->createMock(Curl::class);
        $submitCurl->method('getStatus')->willReturn(200);
        $submitCurl->method('addHeader')->willReturnCallback(
            static function (string $name, string $value) use (&$headers): void {
                $headers[$name] = $value;
            }
        );
        $submitCurl->expects($this->once())->method('post')->with(
            'https://indexing.googleapis.com/v3/urlNotifications:publish',
            '{"url":"https://x.test/a","type":"URL_DELETED"}'
        );

        $secondSubmit = $this->curl(204);

        $saved = [];
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(static function () use (&$saved) {
            return $saved === [] ? ['date' => '2000-01-01', 'count' => 150] : end($saved);
        });
        $flags->expects($this->exactly(2))->method('saveFlag')
            ->willReturnCallback(static function (string $code, array $data) use (&$saved): bool {
                $saved[] = $data;
                return true;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('info');
        $logger->expects($this->never())->method('error');

        $client = $this->client([$tokenCurl, $submitCurl, $secondSubmit], $this->serviceAccount(), $logger, $flags);

        $this->assertTrue($client->submitUrl('https://x.test/a', 'URL_DELETED'));
        $this->assertTrue($client->submitUrl('https://x.test/b'));

        $this->assertSame('Bearer tok123', $headers['Authorization']);
        $this->assertSame(['date' => gmdate('Y-m-d'), 'count' => 1], $saved[0]);
        $this->assertSame(2, $saved[1]['count']);

        $segments = explode('.', $assertion);
        $this->assertCount(3, $segments);
        $claims = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);
        $this->assertSame('svc@example.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/indexing', $claims['scope']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);

        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['key'];
        $signature = base64_decode(strtr($segments[2], '-_', '+/'));
        $this->assertSame(1, openssl_verify($segments[0] . '.' . $segments[1], $signature, $public, OPENSSL_ALGO_SHA256));
    }

    public function testUnexpectedStatusLogsWarningAndCountFailureIsTolerated(): void
    {
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willThrowException(new \RuntimeException('flag read'));
        $flags->method('saveFlag')->willThrowException(new \RuntimeException('flag write'));

        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')
            ->willReturnCallback(static function (string $msg, array $ctx) use (&$warnings): void {
                $warnings[$msg] = $ctx;
            });

        $client = $this->client(
            [$this->curl(200, '{"access_token":"t"}'), $this->curl(429, str_repeat('x', 600))],
            $this->serviceAccount(),
            $logger,
            $flags
        );

        $this->assertFalse($client->submitUrl('https://x.test/'));
        $this->assertSame(
            ['error' => 'flag write'],
            $warnings['Panth SEO Indexing API: could not store the daily submission count.']
        );
        $ctx = $warnings['Panth SEO Indexing API: unexpected HTTP status.'];
        $this->assertSame(429, $ctx['status']);
        $this->assertSame(500, strlen($ctx['body']));
    }

    public function testSubmitRequestExceptionIsLogged(): void
    {
        $failing = $this->createStub(Curl::class);
        $failing->method('post')->willThrowException(new \RuntimeException('timeout'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO Indexing API: request failed.', ['url' => 'https://x.test/', 'error' => 'timeout']);

        $client = $this->client([$this->curl(200, '{"access_token":"t"}'), $failing], $this->serviceAccount(), $logger);
        $this->assertFalse($client->submitUrl('https://x.test/'));
    }
}
