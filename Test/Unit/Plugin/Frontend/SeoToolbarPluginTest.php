<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Frontend;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\View\Result\Page;
use Panth\AdvancedSEO\Plugin\Frontend\SeoToolbarPlugin;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;
use PHPUnit\Framework\TestCase;

class SeoToolbarPluginTest extends TestCase
{
    private string $dir = '';

    private ?string $body = null;

    private bool $noCache = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panth_seo_toolbar_' . uniqid('', true);
        mkdir($this->dir . '/view/frontend/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->dir . '/view/frontend/templates/seo_toolbar.phtml';
        if (is_file($file)) {
            unlink($file);
        }
        foreach (['/view/frontend/templates', '/view/frontend', '/view', ''] as $sub) {
            if (is_dir($this->dir . $sub)) {
                rmdir($this->dir . $sub);
            }
        }
    }

    private function template(string $php): void
    {
        file_put_contents($this->dir . '/view/frontend/templates/seo_toolbar.phtml', $php);
    }

    private function plugin(string $area = 'frontend', bool $allowed = true, bool $dataFails = false, bool $dirFails = false): SeoToolbarPlugin
    {
        $toolbar = $this->createStub(SeoToolbar::class);
        $toolbar->method('isAllowed')->willReturn($allowed);
        if ($dataFails) {
            $toolbar->method('getData')->willThrowException(new \RuntimeException('no data'));
        } else {
            $toolbar->method('getData')->willReturn(['title' => 'Home & <Garden>']);
        }
        $state = $this->createStub(AppState::class);
        $state->method('getAreaCode')->willReturn($area);
        $moduleDir = $this->createStub(ModuleDir::class);
        if ($dirFails) {
            $moduleDir->method('getDir')->willThrowException(new \InvalidArgumentException('no module'));
        } else {
            $moduleDir->method('getDir')->willReturn($this->dir . '/view');
        }

        return new SeoToolbarPlugin($toolbar, $state, $moduleDir);
    }

    private function response(mixed $body): HttpResponse
    {
        $this->body = null;
        $this->noCache = false;
        $response = $this->createStub(HttpResponse::class);
        $response->method('getBody')->willReturn($body);
        $response->method('setBody')->willReturnCallback(function ($value) use ($response) {
            $this->body = $value;
            return $response;
        });
        $response->method('setNoCacheHeaders')->willReturnCallback(function () use ($response) {
            $this->noCache = true;
            return $response;
        });
        return $response;
    }

    private function render(SeoToolbarPlugin $plugin, ResponseInterface $response): Page
    {
        $page = $this->createStub(Page::class);
        $this->assertSame($page, $plugin->afterRenderResult($page, $page, $response));
        return $page;
    }

    public function testToolbarIsInjectedBeforeTheLastBodyTagWithNoCacheHeaders(): void
    {
        $this->template('<div id="tb"><?= $helper->esc($data[\'title\']) ?></div>');

        $this->render($this->plugin(), $this->response('<html><body><p>x</p></body></html>'));

        $this->assertSame('<html><body><p>x</p><div id="tb">Home &amp; &lt;Garden&gt;</div></body></html>', $this->body);
        $this->assertTrue($this->noCache);
    }

    public function testNothingHappensWhenTheToolbarCannotRender(): void
    {
        $this->render($this->plugin(), $this->response('<body></body>'));
        $this->assertNull($this->body);

        $this->template('');
        $this->render($this->plugin(), $this->response('<body></body>'));
        $this->assertNull($this->body);

        $this->template('<?php throw new \RuntimeException("tpl"); ?>');
        $this->render($this->plugin(), $this->response('<body></body>'));
        $this->assertNull($this->body);

        $this->template('<div>tb</div>');
        $this->render($this->plugin('frontend', true, true), $this->response('<body></body>'));
        $this->assertNull($this->body);

        $this->render($this->plugin('frontend', true, false, true), $this->response('<body></body>'));
        $this->assertNull($this->body);
    }

    public function testInapplicableRequestsAreSkipped(): void
    {
        $this->template('<div>tb</div>');

        $this->render($this->plugin('adminhtml'), $this->response('<body></body>'));
        $this->render($this->plugin('frontend', false), $this->response('<body></body>'));
        $this->render($this->plugin(), $this->response(''));
        $this->render($this->plugin(), $this->response('{"json":true}'));

        $this->assertNull($this->body);
    }

    public function testEscapingHelpers(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('&quot;a&quot; &amp; &#039;b&#039;', $plugin->esc('"a" & \'b\''));
        $this->assertSame('{&quot;x&quot;:&quot;&lt;\/script&gt;&quot;}', $plugin->escJson('{"x":"</script>"}'));
    }

    public function testStatusClassRatesLengths(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('r', $plugin->statusClass(0, 30, 60));
        $this->assertSame('g', $plugin->statusClass(30, 30, 60));
        $this->assertSame('g', $plugin->statusClass(60, 30, 60));
        $this->assertSame('y', $plugin->statusClass(61, 30, 60));
        $this->assertSame('y', $plugin->statusClass(10, 30, 60));
    }
}
