<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Magento\Framework\Encryption\EncryptorInterface;
use Panth\AdvancedSEO\Model\Feed\FtpDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FtpDeliveryTest extends TestCase
{
    private function delivery(): FtpDelivery
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        return new FtpDelivery($encryptor, $logger);
    }

    public static function missingCredentialsProvider(): array
    {
        return [
            'no host' => [['delivery_user' => 'u']],
            'blank host' => [['delivery_host' => '   ', 'delivery_user' => 'u']],
            'no user' => [['delivery_host' => 'ftp.example.com']],
        ];
    }

    #[DataProvider('missingCredentialsProvider')]
    public function testMissingHostOrUserThrows(array $profile): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('host and user are required');

        $this->delivery()->deliver($profile, '/tmp/feed.xml');
    }

    public static function internalHostProvider(): array
    {
        return [
            ['localhost'],
            ['LOCALHOST'],
            ['127.0.0.1'],
            ['0.0.0.0'],
            ['169.254.169.254'],
            ['localhost:2121'],
        ];
    }

    #[DataProvider('internalHostProvider')]
    public function testInternalHostsAreBlockedForDelivery(string $host): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not point to an internal address');

        $this->delivery()->deliver(
            ['delivery_host' => $host, 'delivery_user' => 'u', 'delivery_type' => 'sftp'],
            '/tmp/feed.xml'
        );
    }

    public static function privateIpProvider(): array
    {
        return [['10.0.0.5'], ['192.168.1.20:21'], ['172.16.4.4'], ['127.0.0.2']];
    }

    #[DataProvider('privateIpProvider')]
    public function testPrivateIpLiteralsAreRejected(string $host): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or reserved IP address');

        $this->delivery()->deliver(['delivery_host' => $host, 'delivery_user' => 'u'], '/tmp/feed.xml');
    }

    public function testConnectionTestAlsoBlocksInternalHosts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not point to an internal address');

        $this->delivery()->testConnection('ftp', '127.0.0.1:21', 'u', 'p', '/');
    }

    public function testConnectionTestRejectsPrivateIp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private or reserved IP address');

        $this->delivery()->testConnection('sftp', '192.168.0.10', 'u', 'p', '');
    }
}
