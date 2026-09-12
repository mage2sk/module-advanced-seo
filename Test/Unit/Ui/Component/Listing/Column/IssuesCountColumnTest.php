<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\IssuesCountColumn;
use PHPUnit\Framework\TestCase;

class IssuesCountColumnTest extends TestCase
{
    private function column(): IssuesCountColumn
    {
        $processor = $this->createStub(Processor::class);
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($processor);

        $column = new IssuesCountColumn(
            $context,
            $this->createStub(UiComponentFactory::class),
            [],
            ['name' => 'issues_json']
        );
        $column->setData('name', 'issues_json');

        return $column;
    }

    private function render(string $json): string
    {
        $out = $this->column()->prepareDataSource(['data' => ['items' => [['issues_json' => $json]]]]);

        return $out['data']['items'][0]['issues_json'];
    }

    public function testTheIssueTextIsShownNotJustACount(): void
    {
        $html = $this->render('["Missing description","No canonical"]');

        $this->assertStringContainsString('Missing description', $html);
        $this->assertStringContainsString('No canonical', $html);
    }

    public function testALongIssueListIsTruncatedWithACounter(): void
    {
        $html = $this->render('["One","Two","Three","Four","Five"]');

        $badges = substr($html, (int) strpos($html, '<span style='));

        $this->assertStringContainsString('One', $badges);
        $this->assertStringContainsString('+2 more', $badges);
        $this->assertStringNotContainsString('Five', $badges, 'only the first few are badged; the rest stay in the tooltip');
        $this->assertStringContainsString('Five', $html, 'the full list is still available on hover');
    }

    public function testNoIssuesRendersZero(): void
    {
        $this->assertStringContainsString('>0<', $this->render('[]'));
    }

    public function testIssueTextIsEscaped(): void
    {
        $html = $this->render('["Duplicate title with URL https://x/?a=1&b=2 <script>"]');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testInvalidJsonDoesNotBreakTheColumn(): void
    {
        $this->assertIsString($this->render('{not json'));
    }
}
