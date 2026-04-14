<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Feed;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\Io\Ftp;
use Magento\Framework\Filesystem\Io\Sftp;
use Psr\Log\LoggerInterface;

/**
 * Handles uploading generated feed files to FTP/SFTP servers.
 */
class FtpDelivery
{
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Deliver a feed file to remote FTP/SFTP server.
     *
     * @param array  $profile       Feed profile data
     * @param string $localFilePath Absolute path to the generated feed file
     * @throws \RuntimeException on connection or upload failure
     */
    public function deliver(array $profile, string $localFilePath): void
    {
        $type = ($profile['delivery_type'] ?? 'ftp');
        $host = trim((string) ($profile['delivery_host'] ?? ''));
        $user = trim((string) ($profile['delivery_user'] ?? ''));
        $password = (string) ($profile['delivery_password'] ?? '');
        $remotePath = trim((string) ($profile['delivery_path'] ?? '/'));
        $passiveMode = !empty($profile['delivery_passive_mode']);

        if ($host === '' || $user === '') {
            throw new \RuntimeException('FTP/SFTP delivery host and user are required.');
        }

        // Validate host is not an internal/loopback address (SSRF prevention)
        $this->validateHostNotInternal($host);

        // Decrypt password
        $decryptedPassword = $this->encryptor->decrypt($password);
        if ($decryptedPassword === '') {
            $decryptedPassword = $password; // fallback if not encrypted
        }

        // Parse host:port
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portStr] = explode(':', $host, 2);
            $port = (int) $portStr;
        }

        $remoteFilename = basename($localFilePath);
        $remoteFullPath = rtrim($remotePath, '/') . '/' . $remoteFilename;

        if ($type === 'sftp') {
            $this->deliverViaSftp($host, $port ?? 22, $user, $decryptedPassword, $localFilePath, $remoteFullPath);
        } else {
            $this->deliverViaFtp($host, $port ?? 21, $user, $decryptedPassword, $localFilePath, $remoteFullPath, $passiveMode);
        }
    }

    /**
     * Test FTP/SFTP connection without uploading.
     *
     * @return string Success message
     * @throws \RuntimeException on failure
     */
    public function testConnection(string $type, string $host, string $user, string $password, string $path): string
    {
        // Validate host before connecting (SSRF prevention)
        $cleanHost = $host;
        if (str_contains($cleanHost, ':')) {
            [$cleanHost] = explode(':', $cleanHost, 2);
        }
        $this->validateHostNotInternal($cleanHost);

        // Parse host:port
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portStr] = explode(':', $host, 2);
            $port = (int) $portStr;
        }

        if ($type === 'sftp') {
            $sftp = new Sftp();
            $sftp->open([
                'host'     => $host,
                'port'     => $port ?? 22,
                'username' => $user,
                'password' => $password,
            ]);
            // Try to change directory
            $sftp->cd($path ?: '/');
            $sftp->close();
        } else {
            $ftp = new Ftp();
            $ftp->open([
                'host'     => $host,
                'port'     => $port ?? 21,
                'user'     => $user,
                'password' => $password,
                'passive'  => true,
            ]);
            $ftp->cd($path ?: '/');
            $ftp->close();
        }

        return 'Connection successful.';
    }

    /**
     * Prevent SSRF by blocking connections to internal/loopback/private addresses.
     *
     * @throws \RuntimeException if the host resolves to an internal address
     */
    private function validateHostNotInternal(string $host): void
    {
        // Block obvious internal hostnames
        $blockedPatterns = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google', '169.254.169.254'];
        foreach ($blockedPatterns as $blocked) {
            if (strcasecmp($host, $blocked) === 0) {
                throw new \RuntimeException('FTP/SFTP host must not point to an internal address.');
            }
        }

        // Resolve hostname and check if IP is in private/reserved ranges
        $ips = gethostbynamel($host);
        if ($ips === false) {
            return; // DNS resolution failed; let the actual connection fail naturally
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException(
                    'FTP/SFTP host resolves to a private or reserved IP address. External hosts only.'
                );
            }
        }
    }

    private function deliverViaFtp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $localFilePath,
        string $remoteFullPath,
        bool $passiveMode
    ): void {
        $ftp = new Ftp();
        try {
            $ftp->open([
                'host'     => $host,
                'port'     => $port,
                'user'     => $user,
                'password' => $password,
                'passive'  => $passiveMode,
            ]);

            $ftp->write($remoteFullPath, $localFilePath);
            $ftp->close();

            $this->logger->info(sprintf(
                'Panth SEO Feed: file "%s" delivered via FTP to %s:%d%s',
                basename($localFilePath),
                $host,
                $port,
                $remoteFullPath
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Panth SEO Feed: FTP delivery failed for "%s" to %s: %s',
                basename($localFilePath),
                $host,
                $e->getMessage()
            ));
            throw new \RuntimeException('FTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function deliverViaSftp(
        string $host,
        int $port,
        string $user,
        string $password,
        string $localFilePath,
        string $remoteFullPath
    ): void {
        $sftp = new Sftp();
        try {
            $sftp->open([
                'host'     => $host,
                'port'     => $port,
                'username' => $user,
                'password' => $password,
            ]);

            $sftp->write($remoteFullPath, $localFilePath);
            $sftp->close();

            $this->logger->info(sprintf(
                'Panth SEO Feed: file "%s" delivered via SFTP to %s:%d%s',
                basename($localFilePath),
                $host,
                $port,
                $remoteFullPath
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Panth SEO Feed: SFTP delivery failed for "%s" to %s: %s',
                basename($localFilePath),
                $host,
                $e->getMessage()
            ));
            throw new \RuntimeException('SFTP delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
