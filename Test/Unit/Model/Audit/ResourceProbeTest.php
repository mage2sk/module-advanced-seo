<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\ResourceProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResourceProbeTest extends TestCase
{
    public static function hostProvider(): array
    {
        return [
            ['linkedin.com', true],
            ['WWW.LinkedIn.com', true],
            ['wa.me', true],
            ['notlinkedin.com', false],
            ['example.com', false],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testUnverifiableHosts(string $host, bool $expected): void
    {
        $this->assertSame($expected, ResourceProbe::isUnverifiableHost($host));
    }

    public static function ipProvider(): array
    {
        return [
            ['8.8.8.8', true],
            ['[2001:4860:4860::8888]', true],
            ['10.0.0.1', false],
            ['192.168.1.9', false],
            ['127.0.0.1', false],
            ['[::1]', false],
            ['169.254.1.1', false],
            ['not-an-ip', false],
        ];
    }

    #[DataProvider('ipProvider')]
    public function testPublicIpDetection(string $ip, bool $expected): void
    {
        $this->assertSame($expected, ResourceProbe::isPublicIp($ip));
    }

    public function testPrivateTargetsAreBlockedWithoutARequest(): void
    {
        $probe = new ResourceProbe();
        $probe->configure('example.com', true, 'Agent');

        $result = $probe->fetchTimed('http://10.0.0.5/admin');

        $this->assertSame(0, $result->status);
        $this->assertSame('Blocked: 10.0.0.5 resolves to a private or reserved address', $result->error);
        $this->assertTrue($result->isBroken());
        $this->assertSame(0, $probe->getRequestCount());
    }

    public function testHeadAndGetReportBlockedHostsAndCacheThem(): void
    {
        $probe = new ResourceProbe();
        $probe->configure('Example.com', false, 'Agent');

        $head = $probe->head(['http://192.168.1.1/a.png', 'http://[::1]:8080/b.css', 42]);

        $this->assertSame(['http://192.168.1.1/a.png', 'http://[::1]:8080/b.css'], array_keys($head));
        $this->assertSame(0, $head['http://[::1]:8080/b.css']->status);
        $this->assertStringStartsWith('Blocked: ::1', $head['http://[::1]:8080/b.css']->error);

        $get = $probe->get(['http://192.168.1.1/a.png']);
        $again = $probe->get(['http://192.168.1.1/a.png']);
        $this->assertSame($get['http://192.168.1.1/a.png'], $again['http://192.168.1.1/a.png']);
        $this->assertSame(0, $probe->getRequestCount());
    }

    public function testHeadWithoutFallbackAndEmptyInput(): void
    {
        $probe = new ResourceProbe();

        $this->assertSame([], $probe->head([]));
        $result = $probe->head(['https://127.0.0.1/x'], false);
        $this->assertSame(0, $result['https://127.0.0.1/x']->status);
    }

    public function testConfigureResetsTheRequestCounter(): void
    {
        $probe = new ResourceProbe();
        $probe->configure('example.com', true, 'Agent');

        $this->assertSame(0, $probe->getRequestCount());
    }
}
