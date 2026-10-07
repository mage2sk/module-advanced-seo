<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor\InlineEdit;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\Save as CanonicalSave;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\AddDefaultFields;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\TestConnection;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Save as TemplateSave;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Panth\AdvancedSEO\Model\Feed\FtpDelivery;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider\CollectionRecorder;
use PHPUnit\Framework\TestCase;

class SaveControllersTest extends TestCase
{
    use ControllerHarness;
    use ConnectionRecorder;
    use CollectionRecorder;

    private int $invalidations = 0;

    private function templateSave(array $post, array $params = [], ?\Throwable $fails = null): TemplateSave
    {
        $this->invalidations = 0;
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 00:00:00');
        $invalidator = $this->createStub(SeoCacheInvalidator::class);
        $invalidator->method('invalidateResolvedMeta')->willReturnCallback(function (): void {
            $this->invalidations++;
        });
        $resource = $this->recordingResource();
        if ($fails !== null) {
            $resource = $this->createStub(ResourceConnection::class);
            $resource->method('getConnection')->willThrowException($fails);
        }

        return new TemplateSave($this->controllerContext($params, $post), $resource, $dateTime, $invalidator);
    }

    public function testTemplateInsertBuildsConditionsFromTheSimpleFields(): void
    {
        $this->dbLastInsertId = '42';
        $this->templateSave([
            'name' => str_repeat('n', 300), 'entity_type' => 'category', 'store_id' => '2', 'meta_title' => 'T',
            'condition_attribute' => ' color ', 'condition_value' => 'red',
        ], ['back' => '1'])->execute();

        $row = $this->db['insert'][0][1];
        $this->assertSame('panth_seo_template', $this->db['insert'][0][0]);
        $this->assertSame(255, mb_strlen($row['name']));
        $this->assertSame('category', $row['entity_type']);
        $this->assertSame(2, $row['store_id']);
        $this->assertSame(100, $row['priority']);
        $this->assertSame(1, $row['is_active']);
        $this->assertSame('2026-10-03 00:00:00', $row['created_at']);
        $this->assertSame(
            ['type' => 'all', 'conditions' => [['attribute' => 'color', 'operator' => 'in', 'value' => 'red']]],
            json_decode($row['conditions_serialized'], true)
        );
        $this->assertSame(1, $this->invalidations);
        $this->assertSame([['success', 'Template saved.']], $this->messages);
        $this->assertSame(['*/*/edit', ['id' => 42]], $this->redirect);
    }

    public function testTemplateUpdateKeepsRawConditions(): void
    {
        $this->templateSave(['template_id' => '7', 'entity_type' => 'cms_page', 'conditions_serialized' => '{"x":1}'])->execute();

        $this->assertSame('panth_seo_template', $this->db['update'][0][0]);
        $this->assertSame('{"x":1}', $this->db['update'][0][1]['conditions_serialized']);
        $this->assertArrayNotHasKey('created_at', $this->db['update'][0][1]);
        $this->assertSame(['template_id = ?' => 7], $this->db['update'][0][2]);
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->templateSave(['template_id' => '7'])->execute();
        $this->assertSame('{}', $this->db['update'][0][1]['conditions_serialized']);
        $this->assertSame('product', $this->db['update'][0][1]['entity_type']);
    }

    public function testTemplateSaveRejectsBadInput(): void
    {
        $this->templateSave([])->execute();
        $this->assertSame(['*/*/', []], $this->redirect);
        $this->assertSame([], $this->messages);

        $this->templateSave(['template_id' => 3, 'entity_type' => 'brand'])->execute();
        $this->assertSame([['error', 'Invalid entity type.']], $this->messages);
        $this->assertSame(['*/*/edit', ['id' => 3]], $this->redirect);

        $this->templateSave(['template_id' => 3], [], new \RuntimeException('duplicate'))->execute();
        $this->assertSame([['error', 'duplicate']], $this->messages);
        $this->assertSame(0, $this->invalidations);
    }

    private function canonicalSave(
        array $post,
        array $params = [],
        bool $fails = false,
        ?\Magento\Framework\App\Request\DataPersistorInterface $persistor = null,
        ?\Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator $invalidator = null
    ): CanonicalSave {
        $repository = $this->createStub(CustomCanonicalRepository::class);
        $repository->method('save')->willReturnCallback(function (array $row) use ($fails): int {
            if ($fails) {
                throw new \RuntimeException('constraint');
            }
            $this->db['insert'][] = $row;
            return 77;
        });
        $this->db = ['insert' => []];

        return new CanonicalSave($this->controllerContext($params, $post), $repository, $persistor, $invalidator);
    }

    public function testCanonicalSaveNormalisesTheRow(): void
    {
        $this->canonicalSave([
            'canonical_id' => '5', 'source_entity_type' => 'product', 'source_entity_id' => '9',
            'target_url' => 'https://x.test/' . str_repeat('a', 3000), 'store_id' => '1',
        ], ['back' => 1])->execute();

        $row = $this->db['insert'][0];
        $this->assertSame(5, $row['canonical_id']);
        $this->assertSame(9, $row['source_entity_id']);
        $this->assertSame(2048, mb_strlen($row['target_url']));
        $this->assertSame('', $row['target_entity_type']);
        $this->assertSame(1, $row['is_active']);
        $this->assertSame(['*/*/edit', ['id' => 77]], $this->redirect);

        $this->canonicalSave(['source_entity_type' => 'category', 'source_entity_id' => '4', 'target_url' => 'https://y.test/'])->execute();
        $this->assertArrayNotHasKey('canonical_id', $this->db['insert'][0]);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testCanonicalSaveRejectsUnsafeInput(): void
    {
        $this->canonicalSave([])->execute();
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->canonicalSave(['canonical_id' => 2, 'source_entity_type' => 'brand'])->execute();
        $this->assertSame([['error', 'Invalid entity type.']], $this->messages);

        $this->canonicalSave(['canonical_id' => 2, 'target_entity_type' => 'x'])->execute();
        $this->assertSame([['error', 'Invalid entity type.']], $this->messages);

        $valid = ['canonical_id' => 2, 'source_entity_type' => 'product', 'source_entity_id' => '9'];
        foreach (['JavaScript:alert(1)', ' javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'ftp://x.test/', 'https://x.test/"onmouseover=1'] as $bad) {
            $this->canonicalSave($valid + ['target_url' => $bad])->execute();
            $this->assertStringContainsString('full http:// or https:// URL', (string) $this->messages[0][1], $bad);
            $this->assertSame(['*/*/edit', ['id' => 2]], $this->redirect);
            $this->assertSame([], $this->db['insert']);
        }

        $this->canonicalSave(['canonical_id' => 2, 'source_entity_type' => 'product'])->execute();
        $this->assertSame([['error', 'Enter the ID of the source entity.']], $this->messages);

        $this->canonicalSave($valid)->execute();
        $this->assertSame([['error', 'Enter a canonical URL or choose a target entity type and ID.']], $this->messages);

        $this->canonicalSave($valid + ['target_url' => '/x'], [], true)->execute();
        $this->assertSame([['error', 'constraint']], $this->messages);
    }

    public function testCanonicalTargetUrlRules(): void
    {
        $this->assertTrue(CanonicalSave::isAllowedTargetUrl(''));
        $this->assertTrue(CanonicalSave::isAllowedTargetUrl('/page.html'));
        $this->assertTrue(CanonicalSave::isAllowedTargetUrl('https://shop.test/page.html?a=1'));
        $this->assertTrue(CanonicalSave::isAllowedTargetUrl('HTTP://shop.test'));
        $this->assertFalse(CanonicalSave::isAllowedTargetUrl('vbscript:x'));
        $this->assertFalse(CanonicalSave::isAllowedTargetUrl('https://'));
        $this->assertFalse(CanonicalSave::isAllowedTargetUrl('page.html'));
        $this->assertFalse(CanonicalSave::isAllowedTargetUrl('https://shop.test/<script>'));
    }

    public function testRejectedCanonicalKeepsTheInputAndASavedOneCleansThePageCache(): void
    {
        $persisted = [];
        $persistor = $this->createStub(\Magento\Framework\App\Request\DataPersistorInterface::class);
        $persistor->method('set')->willReturnCallback(function ($key, $value) use (&$persisted): void {
            $persisted[$key] = $value;
        });
        $persistor->method('clear')->willReturnCallback(function ($key) use (&$persisted): void {
            $persisted[$key] = 'cleared';
        });
        $cleaned = [];
        $invalidator = $this->createStub(\Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator::class);
        $invalidator->method('cleanEntityPages')->willReturnCallback(function (string $type, array $ids) use (&$cleaned): void {
            $cleaned[] = [$type, $ids];
        });

        $post = ['source_entity_type' => 'product', 'source_entity_id' => '9', 'target_url' => 'javascript:x'];
        $this->canonicalSave($post, [], false, $persistor, $invalidator)->execute();
        $this->assertSame($post, $persisted['panth_seo_custom_canonical']);
        $this->assertSame(['*/*/edit', []], $this->redirect);
        $this->assertSame([], $cleaned);

        $post['target_url'] = 'https://shop.test/a.html';
        $this->canonicalSave($post, [], false, $persistor, $invalidator)->execute();
        $this->assertSame('cleared', $persisted['panth_seo_custom_canonical']);
        $this->assertSame([['product', [9]]], $cleaned);
    }

    private function inlineEdit(array $params, array $session, array &$saved): InlineEdit
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturnCallback(static function (int $id) {
            if ($id === 99) {
                throw new \RuntimeException('missing product');
            }
            $product = new class extends Product {
                public function __construct()
                {
                }
            };
            $product->setData('entity_id', $id);
            return $product;
        });
        $products->method('save')->willReturnCallback(static function ($product) use (&$saved) {
            $saved[] = ['product', $product->getData()];
            return $product;
        });

        $categoryResource = $this->createStub(CategoryResource::class);
        $categoryResource->method('getAttribute')->willReturnCallback(static fn($code) => match ($code) {
            'meta_title' => new DataObject(['attribute_id' => 45, 'backend_table' => 'catalog_category_entity_varchar']),
            default => new DataObject([]),
        });

        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturnCallback(static function ($id) {
            $page = new class extends Page {
                public function __construct()
                {
                }
            };
            $page->setData('page_id', $id);
            return $page;
        });
        $pages->method('save')->willReturnCallback(static function ($page) use (&$saved) {
            $saved[] = ['cms', $page->getData()];
            return $page;
        });

        return new InlineEdit(
            $this->controllerContext($params),
            $products,
            $categoryResource,
            $pages,
            $this->recordingResource(),
            $this->recordingSession($session),
            $this->jsonFactory()
        );
    }

    public function testInlineEditSavesProducts(): void
    {
        $saved = [];
        $this->inlineEdit(['items' => [4 => ['meta_title' => 'T', 'meta_description' => 'D'], 99 => ['meta_title' => 'x']], 'store' => '2'], [], $saved)->execute();

        $this->assertSame([['product', ['entity_id' => 4, 'store_id' => 2, 'meta_title' => 'T', 'meta_description' => 'D']]], $saved);
        $this->assertTrue($this->json['error']);
        $this->assertSame('[ID: 99] missing product', (string) $this->json['messages'][0]);
    }

    public function testInlineEditUpdatesOnlyTheEditedProductAttributesInTheChosenScope(): void
    {
        $saved = [];
        $calls = [];
        $found = $this->createStub(\Magento\Catalog\Model\Product::class);
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturnCallback(static function (int $id) use ($found) {
            if ($id === 99) {
                throw new \RuntimeException('missing product');
            }
            return $found;
        });
        $action = $this->createStub(\Magento\Catalog\Model\Product\Action::class);
        $action->method('updateAttributes')->willReturnCallback(function (array $ids, array $attrs, $storeId) use (&$calls, $action) {
            $calls[] = ['update', $ids, $attrs, $storeId];
            return $action;
        });
        $invalidator = $this->createStub(\Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator::class);
        $invalidator->method('cleanEntityPages')->willReturnCallback(function (string $type, array $ids) use (&$calls): void {
            $calls[] = ['fpc', $type, $ids];
        });
        $resolved = $this->createStub(\Panth\AdvancedSEO\Model\Meta\ResolvedRepository::class);
        $resolved->method('deleteEntity')->willReturnCallback(function (string $type, int $id) use (&$calls): void {
            $calls[] = ['resolved', $type, $id];
        });
        $categoryResource = $this->createStub(\Magento\Catalog\Model\ResourceModel\Category::class);

        $controller = new InlineEdit(
            $this->controllerContext(['items' => [4 => ['meta_title' => 'T'], 99 => ['meta_title' => 'x']], 'store' => '2']),
            $products,
            $categoryResource,
            $this->createStub(\Magento\Cms\Api\PageRepositoryInterface::class),
            $this->recordingResource(),
            $this->recordingSession([]),
            $this->jsonFactory(),
            $action,
            $invalidator,
            $this->createStub(\Panth\AdvancedSEO\Model\Meta\Cache::class),
            $resolved
        );
        $controller->execute();

        $this->assertSame([
            ['update', [4], ['meta_title' => 'T'], 2],
            ['resolved', 'product', 4],
            ['fpc', 'product', [4]],
        ], $calls);
        $this->assertTrue($this->json['error']);
        $this->assertSame([], $saved);
    }

    public function testInlineEditWritesCategoryAttributesDirectly(): void
    {
        $saved = [];
        $this->inlineEdit(
            ['items' => [6 => ['meta_title' => 'Cat title', 'meta_description' => 'ignored']]],
            ['panth_seo_bulkeditor_type' => 'category', 'panth_seo_bulkeditor_store' => '3'],
            $saved
        )->execute();

        $this->assertSame([[
            'catalog_category_entity_varchar',
            ['attribute_id' => 45, 'store_id' => 3, 'entity_id' => 6, 'value' => 'Cat title'],
            ['value'],
        ]], $this->db['insertOnDuplicate']);
        $this->assertSame(['messages' => [], 'error' => false], $this->json);
    }

    public function testInlineEditSavesCmsPages(): void
    {
        $saved = [];
        $this->inlineEdit(['items' => [2 => ['meta_description' => 'About us']]], ['panth_seo_bulkeditor_type' => 'cms'], $saved)->execute();

        $this->assertSame([['cms', ['page_id' => 2, 'meta_description' => 'About us']]], $saved);
    }

    public function testInlineEditNeedsItems(): void
    {
        $saved = [];
        $this->inlineEdit([], [], $saved)->execute();

        $this->assertTrue($this->json['error']);
        $this->assertSame('Please correct the data sent.', (string) $this->json['messages'][0]);
    }

    public function testAddDefaultFieldsSkipsExistingMappings(): void
    {
        $resource = $this->recordingResource();
        $this->dbFetchCol = ['g:id', 'g:title'];
        (new AddDefaultFields($this->controllerContext(['feed_id' => 5]), $resource))->execute();

        $this->assertCount(18, $this->db['insert']);
        $this->assertSame(
            ['feed_id' => 5, 'feed_field' => 'g:description', 'source_type' => 'attribute', 'source_value' => 'description', 'default_value' => null, 'sort_order' => 30, 'is_required' => 1],
            $this->db['insert'][0][1]
        );
        $this->assertSame([['success', 'Added 18 Google Shopping default field mappings.'], ['notice', 'Skipped 2 fields that already exist.']], $this->messages);
        $this->assertSame(['*/feed/fields', ['feed_id' => 5]], $this->redirect);
        $this->dbFetchCol = [];
    }

    public function testAddDefaultFieldsValidatesAndReportsErrors(): void
    {
        (new AddDefaultFields($this->controllerContext(), $this->recordingResource()))->execute();
        $this->assertSame([['error', 'Invalid feed profile ID.']], $this->messages);
        $this->assertSame(['*/*/index', []], $this->redirect);

        $failing = $this->createStub(ResourceConnection::class);
        $failing->method('getConnection')->willThrowException(new \RuntimeException('no table'));
        (new AddDefaultFields($this->controllerContext(['feed_id' => 5]), $failing))->execute();
        $this->assertSame([['error', 'no table']], $this->messages);
    }

    private function testConnection(array $params, array $fetchOne = [], string $decrypted = 'plain'): ?array
    {
        $calls = [];
        $ftp = $this->createStub(FtpDelivery::class);
        $ftp->method('testConnection')->willReturnCallback(static function (...$args) use (&$calls): string {
            $calls[] = $args;
            if ($args[1] === 'bad.host') {
                throw new \RuntimeException('refused');
            }
            return 'Connected to ' . $args[1];
        });
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn($decrypted);

        (new TestConnection($this->controllerContext($params), $this->jsonFactory(), $ftp, $encryptor, $this->recordingResource($fetchOne)))->execute();

        return ['json' => $this->json, 'calls' => $calls];
    }

    public function testConnectionUsesTheSubmittedOrSavedPassword(): void
    {
        $result = $this->testConnection(['delivery_type' => 'sftp', 'delivery_host' => ' h.test ', 'delivery_user' => 'u', 'delivery_password' => 'pw', 'delivery_path' => ' /in ']);
        $this->assertSame(['success' => true, 'message' => 'Connected to h.test'], $result['json']);
        $this->assertSame([['sftp', 'h.test', 'u', 'pw', '/in']], $result['calls']);

        $result = $this->testConnection(['delivery_host' => 'h', 'delivery_user' => 'u', 'feed_id' => 3], ['cipher']);
        $this->assertSame('plain', $result['calls'][0][3]);
        $this->assertSame('ftp', $result['calls'][0][0]);

        $result = $this->testConnection(['delivery_host' => 'h', 'delivery_user' => 'u', 'feed_id' => 3], ['legacy-plain'], '');
        $this->assertSame('legacy-plain', $result['calls'][0][3]);

        $result = $this->testConnection(['delivery_host' => 'h', 'delivery_user' => 'u', 'feed_id' => 3], ['']);
        $this->assertSame('', $result['calls'][0][3]);
    }

    public function testConnectionValidationAndFailures(): void
    {
        $result = $this->testConnection(['delivery_host' => 'h']);
        $this->assertSame(['success' => false, 'message' => 'Host and User are required.'], $result['json']);
        $this->assertSame([], $result['calls']);

        $result = $this->testConnection(['delivery_host' => 'bad.host', 'delivery_user' => 'u', 'delivery_password' => 'p']);
        $this->assertSame(['success' => false, 'message' => 'Connection failed: refused'], $result['json']);
    }
}
