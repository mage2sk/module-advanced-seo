<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\DuplicateTitleCheck;

class DuplicateTitleCheckTest extends AbstractCheckTestCase
{
    public function testTitlesAreComparedCaseAndWhitespaceInsensitively(): void
    {
        $a = $this->page('duplicate_title.positive.html', 'https://example.com/service-a');
        $b = $this->page('duplicate_title.positive.html', 'https://example.com/service-b');
        $c = $this->page('duplicate_title.negative.html', 'https://example.com/service-c');

        $issues = (new DuplicateTitleCheck($this->catalog()))->checkSite(
            [$a->url => $a, $b->url => $b, $c->url => $c],
            $this->ctx()
        );

        $duplicates = array_values(array_filter($issues, static fn ($i) => $i->code === DuplicateTitleCheck::CODE));
        $this->assertCount(2, $duplicates);
        $this->assertStringContainsString('shared by 2 pages', strtolower($duplicates[0]->detail));
        $this->assertContains(DuplicateTitleCheck::CODE_CANNIBALISATION, $this->codes($issues));
    }

    public function testDistinctTitlesPass(): void
    {
        $a = $this->page('duplicate_title.positive.html', 'https://example.com/service-a');
        $c = $this->page('duplicate_title.negative.html', 'https://example.com/service-c');

        $this->assertSame([], (new DuplicateTitleCheck($this->catalog()))->checkSite([$a, $c], $this->ctx()));
    }

    public function testJaccard(): void
    {
        $this->assertEqualsWithDelta(1.0, DuplicateTitleCheck::jaccard('Custom Development Services', 'custom development services'), 0.001);
        $this->assertLessThan(0.8, DuplicateTitleCheck::jaccard('Custom Development Services', 'Performance audit'));
    }
}
