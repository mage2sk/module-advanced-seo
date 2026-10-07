<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\Module\PackageInfo;
use Panth\AdvancedSEO\Model\Audit\AuditUserAgent;
use PHPUnit\Framework\TestCase;

class AuditUserAgentTest extends TestCase
{
    public function testWithoutPackageInfoTheVersionIsDev(): void
    {
        $agent = new AuditUserAgent();

        $this->assertSame('dev', $agent->getVersion());
        $this->assertSame('PanthSeoAudit/dev (+https://example.com/)', $agent->forStore('https://example.com///'));
    }

    public function testPackageVersionIsReadOnceAndCached(): void
    {
        $info = $this->createMock(PackageInfo::class);
        $info->expects($this->once())->method('getVersion')->with('Panth_AdvancedSEO')->willReturn('2.4.1');
        $agent = new AuditUserAgent($info);

        $this->assertSame('2.4.1', $agent->getVersion());
        $this->assertSame('PanthSeoAudit/2.4.1 (+https://shop.test/)', $agent->forStore('https://shop.test'));
    }

    public function testEmptyOrFailingLookupFallsBackToDev(): void
    {
        $empty = $this->createStub(PackageInfo::class);
        $empty->method('getVersion')->willReturn('');
        $failing = $this->createStub(PackageInfo::class);
        $failing->method('getVersion')->willThrowException(new \RuntimeException('no composer.json'));

        $this->assertSame('dev', (new AuditUserAgent($empty))->getVersion());
        $this->assertSame('dev', (new AuditUserAgent($failing))->getVersion());
    }
}
