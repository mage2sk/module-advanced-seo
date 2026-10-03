<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\Template;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection as CmsCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Apply;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml\ControllerHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplyTest extends TestCase
{
    use ControllerHarness;

    private array $eav = [];

    private array $upserts = [];

    private array $updates = [];

    private array $logs = [];

    private int $invalidations = 0;

    private static function entity(int $id, array $data = []): DataObject
    {
        return new class (['id' => $id] + $data) extends DataObject {
            public function getId()
            {
                return $this->getData('id');
            }
        };
    }

    private function factory(string $factoryClass, string $collectionClass, array $items): object
    {
        $factory = $this->createStub($factoryClass);
        $factory->method('create')->willReturnCallback(function () use ($collectionClass, $items) {
            $collection = $this->createStub($collectionClass);
            $current = 1;
            $collection->method('setCurPage')->willReturnCallback(static function ($page) use (&$current, $collection) {
                $current = $page;
                return $collection;
            });
            $collection->method('getItems')->willReturnCallback(static function () use (&$current, $items): array {
                return $current === 1 ? $items : [];
            });
            $collection->method('getLastPageNumber')->willReturn(1);
            return $collection;
        });
        return $factory;
    }

    private function apply(array|false|\Throwable $template, array $items = [], array $options = []): void
    {
        $this->eav = [];
        $this->upserts = [];
        $this->updates = [];
        $this->logs = [];
        $this->invalidations = 0;

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(static function () use ($template) {
            if ($template instanceof \Throwable) {
                throw $template;
            }
            return $template;
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(function ($table, array $data, array $fields = []) {
            $this->upserts[] = [$table, $data, $fields];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function ($table, array $data, $where) use ($options) {
            if ($table === 'panth_seo_template' && ($options['mark_fails'] ?? false)) {
                throw new \RuntimeException('tracking columns missing');
            }
            $this->updates[] = [$table, $data, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $evaluator = $this->createStub(ConditionEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(
            static fn(array $conditions, $entity) => ($conditions['skip'] ?? null) !== $entity->getId()
        );
        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(
            static fn(string $pattern, $entity, array $context) => $pattern . '#' . $entity->getId() . '@' . $context['store_id']
        );

        $productAction = $this->createStub(ProductAction::class);
        $productAction->method('updateAttributes')->willReturnCallback(function (array $ids, array $attrs, $storeId) use ($productAction) {
            $this->eav[] = ['product', $ids, $attrs, $storeId];
            return $productAction;
        });
        $categoryResource = $this->createStub(CategoryResource::class);
        $categoryResource->method('getAttribute')->willReturnCallback(static function ($code) {
            return match ($code) {
                'meta_title'       => new DataObject(['attribute_id' => 1, 'backend_table' => 'catalog_category_entity_varchar']),
                'meta_description' => new DataObject(['attribute_id' => 2, 'backend_table' => 'catalog_category_entity_text']),
                'meta_robots'      => throw new \RuntimeException('no robots attribute'),
                default            => false,
            };
        });

        $stores = [];
        foreach ($options['stores'] ?? [0, 1, 2] as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        if ($options['stores_fail'] ?? false) {
            $repository->method('getList')->willThrowException(new \RuntimeException('stores'));
        } else {
            $repository->method('getList')->willReturn($stores);
        }

        $config = $this->createStub(Config::class);
        $config->method('isForceTemplateOverExisting')->willReturn($options['force'] ?? false);
        $invalidator = $this->createStub(SeoCacheInvalidator::class);
        $invalidator->method('invalidateResolvedMeta')->willReturnCallback(function (): void {
            $this->invalidations++;
        });
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 00:00:00');
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
                $this->logs[] = [$level, $message, $context];
            });
        }

        $controller = new Apply(
            $this->controllerContext($options['params'] ?? ['template_id' => 6]),
            $resource,
            new Json(),
            $evaluator,
            $renderer,
            $this->factory(ProductCollectionFactory::class, ProductCollection::class, $items),
            $this->factory(CategoryCollectionFactory::class, CategoryCollection::class, $items),
            $productAction,
            $categoryResource,
            $this->factory(CmsCollectionFactory::class, CmsCollection::class, $items),
            $repository,
            $config,
            $invalidator,
            $dateTime,
            $logger
        );
        $controller->execute();
    }

    private function resolvedRows(): array
    {
        $rows = [];
        foreach ($this->upserts as [$table, $data]) {
            if ($table === 'panth_seo_resolved') {
                array_push($rows, ...$data);
            }
        }
        return $rows;
    }

    public function testStoreSpecificProductTemplateWritesEavAndResolvedRows(): void
    {
        $this->apply([
            'entity_type' => 'product', 'store_id' => 2, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K',
            'seo_name' => 'S', 'robots' => 'NOINDEX', 'og_title' => 'OT', 'og_description' => 'OD', 'og_image' => 'OI',
        ], [self::entity(10)]);

        $this->assertSame([['product', [10], [
            'meta_title' => 'T#10@2', 'meta_description' => 'D#10@2', 'seo_name' => 'S#10@2', 'og_title' => 'OT#10@2',
            'og_description' => 'OD#10@2', 'og_image' => 'OI#10@2', 'meta_robots' => 'NOINDEX', 'meta_keyword' => 'K#10@2',
        ], 2]], $this->eav);
        $this->assertSame([[
            'store_id' => 2, 'entity_type' => 'product', 'entity_id' => 10, 'meta_title' => 'T#10@2', 'meta_description' => 'D#10@2',
            'meta_keywords' => 'K#10@2', 'robots' => 'NOINDEX',
            'og_payload' => '{"og:title":"OT#10@2","og:description":"OD#10@2","og:image":"OI#10@2"}', 'source' => 'bulk_template',
        ]], $this->resolvedRows());
        $this->assertSame('panth_seo_template', $this->updates[0][0]);
        $this->assertSame(['template_id = ?' => 6], $this->updates[0][2]);
        $this->assertSame(1, $this->invalidations);
        $this->assertSame([['success', 'Template #6 applied to 1 product entities.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testAllStoreTemplateWritesDefaultEavOnceButResolvesPerStore(): void
    {
        $this->apply(['entity_type' => 'product', 'store_id' => 0, 'meta_title' => '{{name}}'], [self::entity(10)]);

        $this->assertSame([['product', [10], ['meta_title' => '{{name}}#10@1'], 0]], $this->eav);
        $this->assertSame([1, 2], array_column($this->resolvedRows(), 'store_id'));
        $this->assertStringContainsString('rendered for stores 1,2', $this->logs[0][1]);
    }

    public function testStoreTokensForcePerStoreEavWrites(): void
    {
        $this->apply(['entity_type' => 'product', 'store_id' => 0, 'meta_title' => '{{ store.name }} {{name}}'], [self::entity(10)]);

        $this->assertSame([1, 2], array_column($this->eav, 3));
    }

    public function testExistingMetaIsKeptUnlessForced(): void
    {
        $entity = self::entity(10, ['meta_title' => 'Mine', 'meta_description' => '']);
        $this->apply(['entity_type' => 'product', 'store_id' => 1, 'meta_title' => 'T', 'meta_description' => 'D'], [$entity]);
        $this->assertSame(['meta_description' => 'D#10@1'], $this->eav[0][2]);
        $this->assertNull($this->resolvedRows()[0]['meta_title']);

        $this->apply(['entity_type' => 'product', 'store_id' => 1, 'meta_title' => 'T'], [$entity], ['force' => true]);
        $this->assertSame(['meta_title' => 'T#10@1'], $this->eav[0][2]);
    }

    public function testEntitiesWithoutOutputConditionsOrIdsAreSkipped(): void
    {
        $this->apply(
            ['entity_type' => 'product', 'store_id' => 1, 'meta_title' => 'T', 'conditions_serialized' => '{"skip":11}'],
            [self::entity(11), self::entity(0), self::entity(12, ['meta_title' => 'kept'])]
        );

        $this->assertSame([], $this->eav);
        $this->assertSame([], $this->resolvedRows());
        $this->assertSame([['success', 'Template #6 applied to 0 product entities.']], $this->messages);
    }

    public function testCategoryAttributesAreWrittenPerAttributeAndFailuresLogged(): void
    {
        $this->apply(
            ['entity_type' => 'category', 'store_id' => 1, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'K', 'robots' => 'NOINDEX'],
            [self::entity(3)]
        );

        $eavUpserts = array_values(array_filter($this->upserts, static fn($u) => $u[0] !== 'panth_seo_resolved'));
        $this->assertSame([
            ['catalog_category_entity_varchar', ['attribute_id' => 1, 'store_id' => 1, 'entity_id' => 3, 'value' => 'T#3@1'], ['value']],
            ['catalog_category_entity_text', ['attribute_id' => 2, 'store_id' => 1, 'entity_id' => 3, 'value' => 'D#3@1'], ['value']],
        ], $eavUpserts);
        $warning = array_values(array_filter($this->logs, static fn($l) => $l[0] === 'warning'))[0];
        $this->assertSame(['attribute' => 'meta_robots', 'entity_id' => 3, 'error' => 'no robots attribute'], $warning[2]);
        $this->assertSame('category', $this->resolvedRows()[0]['entity_type']);
    }

    public function testCmsPagesAreUpdatedDirectly(): void
    {
        $this->apply(['entity_type' => 'cms_page', 'store_id' => 1, 'meta_title' => 'T', 'meta_keywords' => 'K'], [self::entity(8)]);

        $this->assertSame(['cms_page', ['meta_title' => 'T#8@1', 'meta_keywords' => 'K#8@1'], ['page_id = ?' => 8]], $this->updates[0]);
        $this->assertSame('cms', $this->resolvedRows()[0]['entity_type']);
    }

    public function testCmsWithOnlyRobotsSkipsTheTableUpdate(): void
    {
        $this->apply(['entity_type' => 'cms', 'store_id' => 1, 'robots' => 'NOINDEX'], [self::entity(8)]);

        $this->assertSame('panth_seo_template', $this->updates[0][0]);
        $this->assertSame('NOINDEX', $this->resolvedRows()[0]['robots']);
    }

    public function testUnknownEntityTypesApplyNothing(): void
    {
        $this->apply(['entity_type' => 'brand', 'store_id' => 1, 'meta_title' => 'T'], [self::entity(1)]);

        $this->assertSame([], $this->upserts);
        $this->assertSame([['success', 'Template #6 applied to 0 brand entities.']], $this->messages);
    }

    public function testTrackingFailureIsOnlyAWarning(): void
    {
        $this->apply(['entity_type' => 'product', 'store_id' => 1, 'meta_title' => 'T'], [self::entity(1)], ['mark_fails' => true]);

        $this->assertSame('success', $this->messages[0][0]);
        $warning = array_values(array_filter($this->logs, static fn($l) => $l[0] === 'warning'))[0];
        $this->assertSame(['template_id' => 6, 'error' => 'tracking columns missing'], $warning[2]);
    }

    public function testValidationAndFailurePaths(): void
    {
        $this->apply(false, [], ['params' => []]);
        $this->assertSame([['error', 'Missing template_id parameter.']], $this->messages);

        $this->apply(false);
        $this->assertSame([['error', 'Template not found.']], $this->messages);

        $this->apply(['entity_type' => 'product', 'store_id' => 0], [], ['stores' => [0]]);
        $this->assertSame([['error', 'No store view is available to apply this template to.']], $this->messages);

        $this->apply(['entity_type' => 'product', 'store_id' => 0], [], ['stores_fail' => true]);
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertSame('error', $this->messages[0][0]);

        $this->apply(new \RuntimeException('db gone'));
        $this->assertSame([['error', 'Template apply failed: db gone']], $this->messages);
        $this->assertSame(['template_id' => 6, 'exception' => 'db gone'], $this->logs[0][2]);
        $this->assertSame(0, $this->invalidations);
    }
}
