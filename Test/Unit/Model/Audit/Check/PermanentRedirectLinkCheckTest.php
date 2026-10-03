<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\PermanentRedirectLinkCheck;

class PermanentRedirectLinkCheckTest extends AbstractCheckTestCase
{
    private function ctxWithRedirects(): \Panth\AdvancedSEO\Model\Audit\AuditContext
    {
        return $this->ctx([
            'statuses' => [
                'https://example.com/service-a/' => 301,
                'https://example.com/service-a'  => 200,
                'https://example.com/old-page'   => 308,
                'https://example.com/new-page'   => 200,
                'https://example.com/temporary'  => 302,
            ],
            'redirectMap' => [
                'https://example.com/service-a/' => ['https://example.com/service-a'],
                'https://example.com/old-page'   => ['https://example.com/new-page'],
                'https://example.com/temporary'  => ['https://example.com/new-page'],
            ],
        ]);
    }

    public function testGetUrlTrailingSlashRedirectIsReportedWithDirectFixHint(): void
    {
        $issues = (new PermanentRedirectLinkCheck($this->catalog()))->check(
            $this->page('permanent_redirect_link.positive.html', 'https://example.com/'),
            $this->ctxWithRedirects()
        );

        $this->assertSame(
            ['https://example.com/service-a/', 'https://example.com/old-page'],
            $this->elements($issues, PermanentRedirectLinkCheck::CODE)
        );
        $trailing = array_values(array_filter($issues, static fn ($i) => $i->code === PermanentRedirectLinkCheck::CODE_TRAILING_SLASH));
        $this->assertCount(1, $trailing);
        $this->assertStringContainsString("['_direct' => 'service-a']", $trailing[0]->detail);
        $this->assertStringContainsString("getUrl('', ['_direct' => 'slug'])", $trailing[0]->fixHint);
    }

    public function testDirectLinksAndTemporaryRedirectsPass(): void
    {
        $this->assertSame([], (new PermanentRedirectLinkCheck($this->catalog()))->check(
            $this->page('permanent_redirect_link.negative.html', 'https://example.com/'),
            $this->ctxWithRedirects()
        ));
    }
}
