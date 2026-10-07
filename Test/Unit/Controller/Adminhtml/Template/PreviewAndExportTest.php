<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\Template;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\ExportCsv;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Preview;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\ControllerHarness;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PreviewAndExportTest extends TestCase
{
    use ControllerHarness;
    use ConnectionRecorder;

    private array $errors = [];

    private array $csv = [];

    private ?array $file = null;

    private static function entity(int $id, array $data = []): DataObject
    {
        return new class (['id' => $id] + $data) extends DataObject {
            public function getId()
            {
                return $this->getData('id');
            }
        };
    }

    private function collectionFactory(string $factoryClass, string $collectionClass, array $pages): object
    {
        $factory = $this->createStub($factoryClass);
        $factory->method('create')->willReturnCallback(function () use ($collectionClass, $pages) {
            $collection = $this->createStub($collectionClass);
            $current = 1;
            $collection->method('setCurPage')->willReturnCallback(static function ($page) use (&$current, $collection) {
                $current = $page;
                return $collection;
            });
            $collection->method('getItems')->willReturnCallback(static function () use (&$current, $pages): array {
                return $pages[$current - 1] ?? [];
            });
            $collection->method('getLastPageNumber')->willReturn(count($pages));
            return $collection;
        });
        return $factory;
    }

    private function deps(array $products = [], array $categories = []): array
    {
        $this->errors = [];
        $evaluator = $this->createStub(ConditionEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(
            static fn(array $conditions, $entity) => ($conditions['skip'] ?? null) !== $entity->getId()
        );
        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(
            static fn(string $pattern, $entity, array $context) => $pattern . ':' . $entity->getId() . '@' . $context['store_id']
        );
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });

        return [
            $evaluator,
            $renderer,
            $this->collectionFactory(ProductCollectionFactory::class, ProductCollection::class, $products),
            $this->collectionFactory(CategoryCollectionFactory::class, CategoryCollection::class, $categories),
            $logger,
        ];
    }

    private function preview(array $params, array|false $template, array $products = [], array $categories = [], ?\Throwable $fails = null): ?array
    {
        [$evaluator, $renderer, $productFactory, $categoryFactory, $logger] = $this->deps($products, $categories);
        $controller = new Preview(
            $this->controllerContext($params),
            $this->recordingResource([], [], $fails, [$template]),
            new Json(),
            $evaluator,
            $renderer,
            $productFactory,
            $categoryFactory,
            $this->jsonFactory(),
            $logger
        );
        $controller->execute();

        return $this->json;
    }

    public function testPreviewRendersMatchingProducts(): void
    {
        $json = $this->preview(['template_id' => 3], [
            'entity_type' => 'product', 'store_id' => '2', 'meta_title' => 'T', 'meta_description' => '', 'conditions_serialized' => '{"skip":2}',
        ], [[self::entity(1, ['name' => 'A', 'meta_title' => 'Old']), self::entity(2)], [self::entity(3)]]);

        $this->assertTrue($json['success']);
        $this->assertSame([
            ['entity_id' => 1, 'name' => 'A', 'current_title' => 'Old', 'preview_title' => 'T:1@2', 'current_desc' => '', 'preview_desc' => ''],
            ['entity_id' => 3, 'name' => '', 'current_title' => '', 'preview_title' => 'T:3@2', 'current_desc' => '', 'preview_desc' => ''],
        ], $json['items']);
    }

    public function testPreviewStopsAtTwentyItems(): void
    {
        $page = [];
        for ($i = 1; $i <= 25; $i++) {
            $page[] = self::entity($i);
        }
        $json = $this->preview(['template_id' => 3], ['entity_type' => 'category', 'meta_description' => 'D', 'conditions_serialized' => 'broken'], [], [$page, [self::entity(99)]]);

        $this->assertCount(20, $json['items']);
        $this->assertSame('D:1@0', $json['items'][0]['preview_desc']);
    }

    public function testPreviewErrors(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Missing template_id parameter.'], $this->preview([], false));
        $this->assertSame(['success' => false, 'message' => 'Template not found.'], $this->preview(['template_id' => 3], false));
        $this->assertSame(['success' => true, 'items' => []], $this->preview(['template_id' => 3], ['entity_type' => 'cms_page']));

        $json = $this->preview(['template_id' => 3], false, [], [], new \RuntimeException('db'));
        $this->assertFalse($json['success']);
        $this->assertSame(['Panth SEO Template Preview failed', ['exception' => 'db']], $this->errors[0]);
    }

    private function export(array $params, array|false $template, array $products = [], array $categories = [], bool $writeFails = false): mixed
    {
        $this->csv = [];
        $this->file = null;
        [$evaluator, $renderer, $productFactory, $categoryFactory, $logger] = $this->deps($products, $categories);

        $stream = $this->createStub(FileWriteInterface::class);
        $stream->method('writeCsv')->willReturnCallback(function (array $row) {
            $this->csv[] = $row;
            return 1;
        });
        $directory = $this->createStub(WriteInterface::class);
        if ($writeFails) {
            $directory->method('openFile')->willThrowException(new \RuntimeException('read-only'));
        } else {
            $directory->method('openFile')->willReturn($stream);
        }
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);
        $response = $this->createStub(ResponseInterface::class);
        $fileFactory = $this->createStub(FileFactory::class);
        $fileFactory->method('create')->willReturnCallback(function ($name, $content, $dir) use ($response) {
            $this->file = [$name, $content, $dir];
            return $response;
        });

        $controller = new ExportCsv(
            $this->controllerContext($params),
            $this->recordingResource([], [], null, [$template]),
            new Json(),
            $evaluator,
            $renderer,
            $productFactory,
            $categoryFactory,
            $fileFactory,
            $filesystem,
            $logger
        );

        return $controller->execute();
    }

    public function testExportWritesProductRowsToATemporaryFile(): void
    {
        $this->export(['template_id' => 4], [
            'entity_type' => 'product', 'store_id' => 1, 'meta_title' => 'T', 'meta_description' => 'D',
        ], [[self::entity(5, ['sku' => 'S5', 'meta_description' => 'old'])]]);

        $this->assertSame(['entity_id', 'sku', 'current_meta_title', 'new_meta_title', 'current_meta_description', 'new_meta_description'], $this->csv[0]);
        $this->assertSame([5, 'S5', '', 'T:5@1', 'old', 'D:5@1'], $this->csv[1]);
        $this->assertMatchesRegularExpression('/^seo_template_preview_4_\d{8}_\d{6}\.csv$/', $this->file[0]);
        $this->assertSame(['type' => 'filename', 'value' => $this->file[0], 'rm' => true], $this->file[1]);
        $this->assertSame('tmp', $this->file[2]);
    }

    public function testExportUsesTheNameForCategories(): void
    {
        $this->export(['template_id' => 4], ['entity_type' => 'category', 'conditions_serialized' => '{"skip":6}'], [], [[self::entity(6), self::entity(7, ['name' => 'Gear'])]]);

        $this->assertSame('name', $this->csv[0][1]);
        $this->assertSame([[7, 'Gear', '', '', '', '']], array_slice($this->csv, 1));
    }

    public function testExportErrorsRedirectBack(): void
    {
        $this->export([], false);
        $this->assertSame([['error', 'Missing template_id parameter.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->export(['template_id' => 4], false);
        $this->assertSame([['error', 'Template not found.']], $this->messages);

        $this->export(['template_id' => 4], ['entity_type' => 'product'], [], [], true);
        $this->assertSame([['error', 'CSV export failed: read-only']], $this->messages);
        $this->assertSame('Panth SEO Template ExportCsv failed', $this->errors[0][0]);
        $this->assertNull($this->file);
    }
}
