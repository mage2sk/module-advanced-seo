<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Canonical;

use Magento\Catalog\Controller\Adminhtml\Product\Save as ProductSaveController;
use Magento\Framework\App\Request\Http;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Panth\AdvancedSEO\Plugin\Canonical\ProductFormSavePlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductFormSavePluginTest extends TestCase
{
    use ConnectionRecorder;

    private array $saved = [];

    private array $deleted = [];

    private array $warnings = [];

    private function plugin(array $post, array $params, array $fetchOne = [], bool $enabled = true, ?\Throwable $fails = null): ProductFormSavePlugin
    {
        $this->saved = [];
        $this->deleted = [];
        $this->warnings = [];

        $repository = $this->createStub(CustomCanonicalRepository::class);
        $repository->method('save')->willReturnCallback(function (array $data): int {
            $this->saved[] = $data;
            return 1;
        });
        $repository->method('deleteById')->willReturnCallback(function (int $id): void {
            $this->deleted[] = $id;
        });
        $request = $this->createStub(Http::class);
        $request->method('getPostValue')->willReturn($post);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new ProductFormSavePlugin($repository, $this->recordingResource($fetchOne, [], $fails), $request, $logger, $config);
    }

    private function execute(ProductFormSavePlugin $plugin): mixed
    {
        return $plugin->afterExecute($this->createStub(ProductSaveController::class), 'redirect');
    }

    public function testANewCanonicalIsSaved(): void
    {
        $result = $this->execute($this->plugin(['custom_canonical_url' => ' https://x.test/p '], ['id' => 4, 'store' => 2]));

        $this->assertSame('redirect', $result);
        $this->assertSame([[
            'source_entity_type' => 'product',
            'source_entity_id'   => 4,
            'target_url'         => 'https://x.test/p',
            'store_id'           => 2,
            'is_active'          => 1,
        ]], $this->saved);
        $this->assertContains(['store_id = ?', 2], $this->db['where']);
    }

    public function testAnExistingCanonicalIsUpdatedInPlace(): void
    {
        $this->execute($this->plugin(['custom_canonical_url' => 'https://x.test/', 'entity_id' => 8], [], ['15']));

        $this->assertSame(15, $this->saved[0]['canonical_id']);
        $this->assertSame(8, $this->saved[0]['source_entity_id']);
    }

    public function testClearingTheFieldDeletesTheExistingRow(): void
    {
        $this->execute($this->plugin(['custom_canonical_url' => '', 'entity_id' => 8], [], ['15']));
        $this->assertSame([15], $this->deleted);

        $this->execute($this->plugin(['entity_id' => 8], []));
        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->saved);
    }

    public function testNoProductIdOrDisabledModuleDoesNothing(): void
    {
        $this->execute($this->plugin(['custom_canonical_url' => 'https://x.test/'], []));
        $this->assertSame([], $this->saved);

        $this->execute($this->plugin(['custom_canonical_url' => 'https://x.test/'], ['id' => 3], [], false));
        $this->assertSame([], $this->saved);
    }

    public function testFailuresAreLogged(): void
    {
        $result = $this->execute($this->plugin(['custom_canonical_url' => 'u'], ['id' => 3], [], true, new \RuntimeException('db')));

        $this->assertSame('redirect', $result);
        $this->assertSame(['Panth SEO product canonical save failed', ['error' => 'db']], $this->warnings[0]);
    }
}
