<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\DuplicateH1TitleCheck;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;

class DuplicateH1TitleCheckTest extends AbstractCheckTestCase
{
    public function testH1EqualToTitleIsFlagged(): void
    {
        $issues = (new DuplicateH1TitleCheck($this->catalog()))->check(
            $this->page('duplicate_h1_title.positive.html', 'https://example.com/pay'),
            $this->ctx()
        );

        $this->assertSame([DuplicateH1TitleCheck::CODE], $this->codes($issues));
    }

    public function testDistinctTitlePasses(): void
    {
        $this->assertSame([], (new DuplicateH1TitleCheck($this->catalog()))->check(
            $this->page('duplicate_h1_title.negative.html', 'https://example.com/pay'),
            $this->ctx()
        ));
    }

    public function testMissingAndMultipleH1(): void
    {
        $check = new DuplicateH1TitleCheck($this->catalog());

        $this->assertSame(
            [DuplicateH1TitleCheck::CODE_MISSING],
            $this->codes($check->check(new ParsedPage('https://example.com/a', 200, title: 'A'), $this->ctx()))
        );
        $this->assertSame(
            [DuplicateH1TitleCheck::CODE_MULTIPLE],
            $this->codes($check->check(new ParsedPage('https://example.com/b', 200, title: 'B', h1: ['One', 'Two']), $this->ctx()))
        );
    }
}
