<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\UnminifiedJsCssCheck;

class UnminifiedJsCssCheckTest extends AbstractCheckTestCase
{
    private const JS  = 'https://example.com/static/frontend/Example/theme/en_US/js/app.js';
    private const CSS = 'https://example.com/static/frontend/Example/theme/en_US/css/styles.css';

    private function readable(): string
    {
        return str_repeat("function example() {\n    var value = 1;\n    return value + 1;\n}\n\n", 40);
    }

    private function minified(): string
    {
        return str_repeat('function e(){var v=1;return v+1}', 60);
    }

    public function testUnminifiedJsIsFlaggedWhenMinifyIsOff(): void
    {
        $page   = $this->page('unminified_js_css.positive.html');
        $issues = (new UnminifiedJsCssCheck($this->catalog()))->checkSite([$page], $this->ctx([
            'minifyJs'       => false,
            'minifyCss'      => true,
            'resourceBodies' => [self::JS => $this->readable(), self::CSS => $this->readable()],
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame(self::JS, $issues[0]->url);
        $this->assertStringContainsString('dev/js/minify_files', $issues[0]->detail);
    }

    public function testMinifiedBodiesOrMinifyEnabledPass(): void
    {
        $page  = $this->page('unminified_js_css.negative.html');
        $check = new UnminifiedJsCssCheck($this->catalog());

        $this->assertSame([], $check->checkSite([$page], $this->ctx([
            'minifyJs'       => false,
            'minifyCss'      => false,
            'resourceBodies' => [self::JS => $this->minified(), self::CSS => $this->minified()],
        ])));
        $this->assertSame([], $check->checkSite([$page], $this->ctx([
            'resourceBodies' => [self::JS => $this->readable()],
        ])));
    }
}
