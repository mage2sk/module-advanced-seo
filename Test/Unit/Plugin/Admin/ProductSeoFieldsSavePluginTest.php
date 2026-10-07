<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Catalog\Controller\Adminhtml\Product\Save as ProductSaveController;
use Magento\Framework\App\Request\Http;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Admin\ProductSeoFieldsSavePlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductSeoFieldsSavePluginTest extends TestCase
{
    use ConnectionRecorder;

    private array $warnings = [];

    private function invoke(array $post, array $params = [], array $fetchOne = [], bool $enabled = true, ?\Throwable $fails = null): mixed
    {
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

        $plugin = new ProductSeoFieldsSavePlugin($this->recordingResource($fetchOne, [], $fails), $request, $logger, $config);

        return $plugin->afterExecute($this->createStub(ProductSaveController::class), 'result');
    }

    public function testANewCanonicalIsInserted(): void
    {
        $this->assertSame('result', $this->invoke(['custom_canonical_url' => ' https://x.test/ ', 'entity_id' => 6], ['store' => 1]));

        $this->assertSame([['panth_seo_custom_canonical', [
            'source_entity_type' => 'product',
            'source_entity_id'   => 6,
            'target_url'         => 'https://x.test/',
            'store_id'           => 1,
            'is_active'          => 1,
        ]]], $this->db['insert']);
    }

    public function testAnExistingCanonicalIsUpdated(): void
    {
        $this->invoke(['custom_canonical_url' => 'https://y.test/'], ['id' => 6], ['21']);

        $this->assertSame([], $this->db['insert']);
        $this->assertSame('panth_seo_custom_canonical', $this->db['update'][0][0]);
        $this->assertSame(['canonical_id = ?' => 21], $this->db['update'][0][2]);
    }

    public function testClearingTheFieldDeletesAnExistingRowOnly(): void
    {
        $this->invoke(['custom_canonical_url' => '  '], ['id' => 6], ['21']);
        $this->assertSame([['panth_seo_custom_canonical', ['canonical_id = ?' => 21, 'target_url <> ?' => '']]], $this->db['delete']);

        $this->invoke(['custom_canonical_url' => ''], ['id' => 6]);
        $this->assertSame([], $this->db['delete']);
    }

    public function testRequestsWithoutTheFieldOrIdAreIgnored(): void
    {
        $this->invoke(['name' => 'x'], ['id' => 6]);
        $this->assertSame([], $this->db['where']);

        $this->invoke(['custom_canonical_url' => 'u']);
        $this->assertSame([], $this->db['where']);

        $this->invoke(['custom_canonical_url' => 'u'], ['id' => 6], [], false);
        $this->assertSame([], $this->db['where']);
    }

    public function testFailuresAreLogged(): void
    {
        $this->invoke(['custom_canonical_url' => 'u'], ['id' => 6], [], true, new \RuntimeException('gone'));

        $this->assertSame(['[Panth_AdvancedSEO] Product canonical save failed', ['error' => 'gone']], $this->warnings[0]);
    }
}
