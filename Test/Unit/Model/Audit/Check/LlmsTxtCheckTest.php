<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\LlmsTxtCheck;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\ProbeResult;

class LlmsTxtCheckTest extends AbstractCheckTestCase
{
    private function probe(string $fixture, string $type = 'text/plain; charset=utf-8', int $status = 200): ProbeResult
    {
        return new ProbeResult(self::BASE . 'llms.txt', $status, headers: ['content-type' => $type], body: self::fixture($fixture));
    }

    public function testOffTopicPriorityUrlsAndWrongTypeAreWarnings(): void
    {
        $issues = (new LlmsTxtCheck($this->catalog()))->checkSite([], $this->ctx([
            'llmsTxt' => $this->probe('llms_txt.positive.txt', 'text/html'),
        ]));

        $this->assertSame(['Content-Type', 'Priority URLs'], $this->elements($issues));
        $this->assertSame(Issue::SEVERITY_WARNING, $issues[0]->severity);
        $this->assertSame(Issue::CATEGORY_AI_SEARCH, $issues[0]->category);
    }

    public function testMissingFileIsANotice(): void
    {
        $issues = (new LlmsTxtCheck($this->catalog()))->checkSite([], $this->ctx([
            'llmsTxt' => new ProbeResult(self::BASE . 'llms.txt', 404),
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame(Issue::SEVERITY_NOTICE, $issues[0]->severity);
    }

    public function testValidFilePasses(): void
    {
        $this->assertSame([], (new LlmsTxtCheck($this->catalog()))->checkSite([], $this->ctx([
            'llmsTxt' => $this->probe('llms_txt.negative.txt'),
        ])));
    }
}
