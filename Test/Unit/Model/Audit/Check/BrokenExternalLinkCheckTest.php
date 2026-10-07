<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\BrokenExternalLinkCheck;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;
use Panth\AdvancedSEO\Model\Audit\ResourceProbe;

class BrokenExternalLinkCheckTest extends AbstractCheckTestCase
{
    private function probes(): array
    {
        $linkedIn = 'https://www.linkedin.com/company/example';
        $whatsApp = 'https://wa.me/10000000000';

        return [
            'https://gone.example.net/page' => new ProbeResult('https://gone.example.net/page', 404),
            $linkedIn => new ProbeResult($linkedIn, 999, unverifiable: true),
            $whatsApp => new ProbeResult($whatsApp, 429, unverifiable: true),
            'https://ok.example.net/' => new ProbeResult('https://ok.example.net/', 200),
        ];
    }

    public function testBrokenLinksAreGroupedAndSocialRateLimitsAreUnverifiable(): void
    {
        $page   = $this->page('broken_external_link.positive.html');
        $issues = (new BrokenExternalLinkCheck($this->catalog()))->checkSite([$page], $this->ctx(['probes' => $this->probes()]));

        $this->assertSame(['https://gone.example.net/page'], $this->elements($issues, BrokenExternalLinkCheck::CODE));
        $broken = array_values(array_filter($issues, static fn ($i) => $i->code === BrokenExternalLinkCheck::CODE));
        $this->assertStringContainsString('HTTP 404', $broken[0]->detail);
        $this->assertStringContainsString('Linked from 1 page(s)', $broken[0]->detail);
        $anchors = array_values(array_filter($issues, static fn ($i) => $i->code === BrokenExternalLinkCheck::CODE_ANCHOR));
        $this->assertCount(1, $anchors);
        $this->assertStringContainsString('https://github.com/example-org/example-repo#installation', $anchors[0]->detail);
    }

    public function testHealthyLinksPass(): void
    {
        $page = $this->page('broken_external_link.negative.html');

        $this->assertSame([], (new BrokenExternalLinkCheck($this->catalog()))->checkSite([$page], $this->ctx(['probes' => $this->probes()])));
    }

    public function testUnverifiableHosts(): void
    {
        $this->assertTrue(ResourceProbe::isUnverifiableHost('www.linkedin.com'));
        $this->assertTrue(ResourceProbe::isUnverifiableHost('x.com'));
        $this->assertTrue(ResourceProbe::isUnverifiableHost('medium.com'));
        $this->assertFalse(ResourceProbe::isUnverifiableHost('example.net'));
        $this->assertFalse(ResourceProbe::isUnverifiableHost('notx.com'));
    }
}
