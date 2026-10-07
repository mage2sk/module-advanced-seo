<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\ProbeResult;
use PHPUnit\Framework\TestCase;

class ProbeResultTest extends TestCase
{
    public function testBrokenClassification(): void
    {
        $this->assertTrue((new ProbeResult('u', 0, 'timeout'))->isBroken());
        $this->assertTrue((new ProbeResult('u', 404))->isBroken());
        $this->assertTrue((new ProbeResult('u', 503))->isBroken());
        $this->assertFalse((new ProbeResult('u', 200))->isBroken());
        $this->assertFalse((new ProbeResult('u', 301))->isBroken());
        $this->assertFalse((new ProbeResult('u', 403, unverifiable: true))->isBroken());
    }

    public function testRedirectAndLocation(): void
    {
        $redirect = new ProbeResult('u', 302, headers: ['location' => '/next', 'content-type' => 'text/html']);

        $this->assertTrue($redirect->isRedirect());
        $this->assertSame('/next', $redirect->location());
        $this->assertSame('text/html', $redirect->header('Content-Type'));
        $this->assertFalse((new ProbeResult('u', 200))->isRedirect());
        $this->assertFalse((new ProbeResult('u', 400))->isRedirect());
        $this->assertSame('', (new ProbeResult('u', 200))->location());
    }
}
