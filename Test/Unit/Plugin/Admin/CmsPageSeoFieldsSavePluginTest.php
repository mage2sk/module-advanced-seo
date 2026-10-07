<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Cms\Controller\Adminhtml\Page\Save as CmsPageSaveController;
use Magento\Framework\App\Request\Http;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Admin\CmsPageSeoFieldsSavePlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CmsPageSeoFieldsSavePluginTest extends TestCase
{
    use ConnectionRecorder;

    private array $warnings = [];

    private function save(
        array $post,
        array $params = [],
        array $fetchOne = [],
        array $fetchRow = [],
        bool $enabled = true,
        ?\Throwable $fails = null
    ): mixed {
        $this->warnings = [];
        $request = $this->createStub(Http::class);
        $request->method('getPostValue')->willReturn($post);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $plugin = new CmsPageSeoFieldsSavePlugin($this->recordingResource($fetchOne, [], $fails, $fetchRow), $request, $logger, $config);

        return $plugin->afterExecute($this->createStub(CmsPageSaveController::class), 'redirect');
    }

    public function testANewOverrideIsInsertedForTheFirstStore(): void
    {
        $this->assertSame('redirect', $this->save([
            'page_id' => 7, 'store_id' => ['2', '3'], 'meta_robots' => ' NOINDEX,FOLLOW ', 'hreflang_identifier' => '',
        ]));

        $this->assertSame([['panth_seo_override', [
            'robots'              => 'NOINDEX,FOLLOW',
            'hreflang_identifier' => null,
            'entity_type'         => 'cms_page',
            'entity_id'           => 7,
            'store_id'            => 2,
        ]]], $this->db['insert']);
    }

    public function testAnExistingOverrideIsUpdated(): void
    {
        $this->save(['hreflang_identifier' => 'about'], ['page_id' => 7, 'store' => 1], ['30']);

        $this->assertSame([['panth_seo_override', ['robots' => null, 'hreflang_identifier' => 'about'], ['override_id = ?' => 30]]], $this->db['update']);
        $this->assertContains(['store_id = ?', 1], $this->db['where']);
    }

    public function testClearingBothFieldsDeletesAnOtherwiseEmptyRow(): void
    {
        $this->save(['page_id' => 7], [], ['30'], [[
            'override_id' => 30, 'entity_type' => 'cms_page', 'robots' => 'NOINDEX', 'meta_title' => '', 'og_title' => null, 'flag' => '0',
        ]]);

        $this->assertSame([['panth_seo_override', ['override_id = ?' => 30]]], $this->db['delete']);
        $this->assertSame([], $this->db['update']);
    }

    public function testClearingBothFieldsKeepsARowWithOtherOverrides(): void
    {
        $this->save(['page_id' => 7], [], ['30'], [['override_id' => 30, 'meta_title' => 'Custom title']]);

        $this->assertSame([], $this->db['delete']);
        $this->assertSame([['panth_seo_override', ['robots' => null, 'hreflang_identifier' => null], ['override_id = ?' => 30]]], $this->db['update']);
    }

    public function testClearingWithAMissingRowUpdatesAndWithoutAnExistingIdDoesNothing(): void
    {
        $this->save(['page_id' => 7], [], ['30'], [false]);
        $this->assertCount(1, $this->db['update']);

        $this->save(['page_id' => 7]);
        $this->assertSame([], $this->db['update']);
        $this->assertSame([], $this->db['insert']);
        $this->assertSame([], $this->db['delete']);
    }

    public function testMissingPageIdOrDisabledModuleIsIgnored(): void
    {
        $this->save(['meta_robots' => 'NOINDEX']);
        $this->assertSame([], $this->db['where']);

        $this->save(['page_id' => 7, 'meta_robots' => 'NOINDEX'], [], [], [], false);
        $this->assertSame([], $this->db['where']);
    }

    public function testFailuresAreLogged(): void
    {
        $this->save(['page_id' => 7, 'meta_robots' => 'X'], [], [], [], true, new \RuntimeException('deadlock'));

        $this->assertSame(['[Panth_AdvancedSEO] CMS page SEO fields save failed', ['error' => 'deadlock']], $this->warnings[0]);
    }
}
