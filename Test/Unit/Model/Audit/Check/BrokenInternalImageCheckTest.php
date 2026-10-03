<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\BrokenInternalImageCheck;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;

class BrokenInternalImageCheckTest extends AbstractCheckTestCase
{
    private function probes(): array
    {
        $out = [];
        foreach ([
            'https://example.com/extensions/docs/screenshot.png' => 404,
            'https://example.com/media/wysiwyg/missing.jpg'      => 404,
            'https://example.com/media/wysiwyg/missing-2x.jpg'   => 410,
            'https://example.com/media/wysiwyg/ok.jpg'           => 200,
        ] as $url => $status) {
            $out[$url] = new ProbeResult($url, $status);
        }

        return $out;
    }

    public function testReadmeRelativeLinkIsReportedAsBrokenImageAndRelativeReadmeLink(): void
    {
        $page   = $this->page('broken_internal_image.positive.html', 'https://example.com/extensions/example.html');
        $issues = (new BrokenInternalImageCheck($this->catalog()))->check($page, $this->ctx(['probes' => $this->probes()]));

        $broken = $this->elements($issues, BrokenInternalImageCheck::CODE);
        $this->assertContains('https://example.com/extensions/docs/screenshot.png', $broken);
        $this->assertContains('https://example.com/media/wysiwyg/missing.jpg', $broken);
        $this->assertContains('https://example.com/media/wysiwyg/missing-2x.jpg', $broken);
        $this->assertNotContains('https://example.com/media/wysiwyg/ok.jpg', $broken);
        $this->assertSame(['docs/screenshot.png'], $this->elements($issues, BrokenInternalImageCheck::CODE_RELATIVE));
    }

    public function testAbsoluteLinksAndExternalImagesPass(): void
    {
        $page   = $this->page('broken_internal_image.negative.html', 'https://example.com/extensions/example.html');
        $issues = (new BrokenInternalImageCheck($this->catalog()))->check($page, $this->ctx(['probes' => $this->probes()]));

        $this->assertSame([], $issues);
    }
}
