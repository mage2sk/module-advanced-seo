<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use PHPUnit\Framework\TestCase;

class IssueCatalogTest extends TestCase
{
    public function testEveryBuiltInDefinitionCreatesAValidIssue(): void
    {
        $catalog = new IssueCatalog();
        $codes   = $catalog->codes();

        $this->assertContains('missing_title', $codes);
        $this->assertContains('link_to_disabled_entity', $codes);
        foreach ($codes as $code) {
            $issue = $catalog->create($code, 'https://example.com/');
            $this->assertSame($code, $issue->code);
            $this->assertNotSame('', $catalog->title($code), $code);
            $this->assertNotSame('', $catalog->fixHint($code), $code);
        }
    }

    public function testKnownDefinition(): void
    {
        $catalog = new IssueCatalog();

        $this->assertTrue($catalog->has('slow_page'));
        $this->assertSame(
            [
                'severity' => Issue::SEVERITY_ERROR,
                'category' => Issue::CATEGORY_PERFORMANCE,
                'title'    => 'Slow page load',
                'fix_hint' => $catalog->fixHint('slow_page'),
            ],
            $catalog->definition('slow_page')
        );
    }

    public function testUnknownCodeGetsANoticeFallback(): void
    {
        $catalog = new IssueCatalog();

        $this->assertFalse($catalog->has('brand_new_check'));
        $this->assertSame(
            ['severity' => 'notice', 'category' => 'content', 'title' => 'Brand new check', 'fix_hint' => ''],
            $catalog->definition('brand_new_check')
        );
    }

    public function testInjectedDefinitionsOverrideAndExtend(): void
    {
        $catalog = new IssueCatalog([
            'slow_page'    => [Issue::SEVERITY_NOTICE, Issue::CATEGORY_PERFORMANCE, 'Slowish'],
            'custom_check' => ['severity' => 'warning', 'category' => 'links', 'title' => 'Custom', 'fix_hint' => 'Fix'],
            'sparse_check' => ['title' => 'Sparse'],
        ]);

        $this->assertSame('notice', $catalog->definition('slow_page')['severity']);
        $this->assertSame('', $catalog->fixHint('slow_page'));
        $this->assertTrue($catalog->has('custom_check'));
        $this->assertSame('Fix', $catalog->fixHint('custom_check'));
        $this->assertSame(
            ['severity' => 'notice', 'category' => 'content', 'title' => 'Sparse', 'fix_hint' => ''],
            $catalog->definition('sparse_check')
        );
        $codes = $catalog->codes();
        $this->assertSame(count($codes), count(array_unique($codes)));
        $this->assertContains('custom_check', $codes);
    }

    public function testCreateAllowsSeverityOverride(): void
    {
        $issue = (new IssueCatalog())->create(
            'duplicate_title',
            'https://example.com/a',
            '<title>',
            'Shared with /b',
            Issue::SEVERITY_NOTICE
        );

        $this->assertSame('notice', $issue->severity);
        $this->assertSame('meta', $issue->category);
        $this->assertSame('<title>', $issue->element);
        $this->assertSame('Shared with /b', $issue->detail);
        $this->assertNotSame('', $issue->fixHint);
    }
}
