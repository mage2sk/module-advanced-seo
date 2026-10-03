<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\SubdomainNoHstsCheck;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;

class SubdomainNoHstsCheckTest extends AbstractCheckTestCase
{
    public function testRootAndSubdomainWithoutHstsAreReported(): void
    {
        $page   = $this->page('subdomain_no_hsts.positive.html', self::BASE);
        $issues = (new SubdomainNoHstsCheck($this->catalog()))->checkSite([$page], $this->ctx(['hostChecks' => [
            'example.com'      => new ProbeResult('https://example.com/', 200),
            'blog.example.com' => new ProbeResult('https://blog.example.com/', 301),
            'down.example.com' => new ProbeResult('https://down.example.com/', 0, 'timeout'),
        ]]));

        $this->assertSame(['https://example.com/', 'https://blog.example.com/'], array_map(static fn ($i) => $i->url, $issues));
    }

    public function testHostsSendingHstsPass(): void
    {
        $page    = $this->page('subdomain_no_hsts.negative.html', self::BASE);
        $headers = ['strict-transport-security' => 'max-age=31536000; includeSubDomains'];

        $this->assertSame([], (new SubdomainNoHstsCheck($this->catalog()))->checkSite([$page], $this->ctx(['hostChecks' => [
            'example.com'      => new ProbeResult('https://example.com/', 200, headers: $headers),
            'blog.example.com' => new ProbeResult('https://blog.example.com/', 200, headers: $headers),
        ]])));
    }
}
