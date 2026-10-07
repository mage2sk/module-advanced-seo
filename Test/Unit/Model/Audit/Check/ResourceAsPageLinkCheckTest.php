<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\ResourceAsPageLinkCheck;

class ResourceAsPageLinkCheckTest extends AbstractCheckTestCase
{
    public function testReadmeImageLinkAndPdfLinkAreReported(): void
    {
        $issues = (new ResourceAsPageLinkCheck($this->catalog()))->check(
            $this->page('resource_as_page_link.positive.html', 'https://example.com/extensions/example.html'),
            $this->ctx()
        );

        $this->assertSame(['docs/screenshot.png', '/media/manual.pdf'], $this->elements($issues));
        $this->assertStringContainsString('https://example.com/extensions/docs/screenshot.png', $issues[0]->detail);
    }

    public function testPageLinksAndExternalFilesPass(): void
    {
        $this->assertSame([], (new ResourceAsPageLinkCheck($this->catalog()))->check(
            $this->page('resource_as_page_link.negative.html'),
            $this->ctx()
        ));
    }
}
