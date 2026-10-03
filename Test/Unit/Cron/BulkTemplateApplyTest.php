<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection as CmsPageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Cron\BulkTemplateApply;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use Panth\AdvancedSEO\Model\Meta\Template;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection as TemplateCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BulkTemplateApplyTest extends TestCase
{
    private array $upserts = [];

    private array $updates = [];

    private array $logged = [];

    private array $templateFilters = [];

    private array $pages = [];

    private array $cmsStoreFilters = [];

    private static function entity(array $data): DataObject
    {
        return new class ($data) extends DataObject {
            public function getId()
            {
                return $this->getData('id');
            }
        };
    }

    private function template(string $entityType, array $fields = [], ?string $conditions = null, ?int $id = 3): Template
    {
        $template = $this->createStub(Template::class);
        $template->method('getEntityType')->willReturn($entityType);
        $template->method('getTemplateId')->willReturn($id);
        $template->method('getMetaTitle')->willReturn($fields['meta_title'] ?? null);
        $template->method('getMetaDescription')->willReturn($fields['meta_description'] ?? null);
        $template->method('getMetaKeywords')->willReturn($fields['meta_keywords'] ?? null);
        $template->method('getOgTitle')->willReturn($fields['og_title'] ?? null);
        $template->method('getOgDescription')->willReturn($fields['og_description'] ?? null);
        $template->method('getOgImage')->willReturn($fields['og_image'] ?? null);
        $template->method('getRobots')->willReturn($fields['robots'] ?? null);
        $template->method('getData')->willReturnCallback(
            static fn($key = '') => $key === 'conditions_serialized' ? $conditions : null
        );

        return $template;
    }

    private function collectionStub(string $class, array $pages): object
    {
        $collection = $this->createStub($class);
        $current = 1;
        $collection->method('setCurPage')->willReturnCallback(
            function (int $page) use ($collection, &$current, $class) {
                $current = $page;
                $this->pages[] = [$class, $page];
                return $collection;
            }
        );
        $collection->method('getItems')->willReturnCallback(static function () use (&$current, $pages): array {
            return $pages[$current - 1] ?? [];
        });
        $collection->method('getLastPageNumber')->willReturn(count($pages));
        if ($class === CmsPageCollection::class) {
            $collection->method('addStoreFilter')->willReturnCallback(
                function ($store) use ($collection) {
                    $this->cmsStoreFilters[] = $store;
                    return $collection;
                }
            );
        }

        return $collection;
    }

    private function cron(
        array $templatesByStore,
        array $storeIds = [1],
        array $pagesByType = [],
        array $config = []
    ): BulkTemplateApply {
        $this->upserts = [];
        $this->updates = [];
        $this->logged = [];
        $this->templateFilters = [];
        $this->pages = [];
        $this->cmsStoreFilters = [];

        $templateFactory = $this->createStub(TemplateCollectionFactory::class);
        $templateFactory->method('create')->willReturnCallback(function () use ($templatesByStore) {
            $collection = $this->createStub(TemplateCollection::class);
            $store = null;
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $condition) use ($collection, &$store) {
                    $this->templateFilters[] = [$field, $condition];
                    if ($field === 'store_id') {
                        $store = $condition['in'][1];
                    }
                    return $collection;
                }
            );
            $collection->method('getItems')->willReturnCallback(static function () use (&$store, $templatesByStore) {
                $items = $templatesByStore[$store] ?? [];
                if ($items instanceof \Throwable) {
                    throw $items;
                }
                return $items;
            });
            return $collection;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $fields): int {
                $this->upserts[] = [$table, $data, $fields];
                return 1;
            }
        );
        $connection->method('update')->willReturnCallback(
            function (string $table, array $data, $where): int {
                $this->updates[] = [$table, $data, $where];
                return 1;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $evaluator = $this->createStub(ConditionEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(
            static function (array $conditions, $entity): bool {
                if (($conditions['fail'] ?? false) === true) {
                    throw new \RuntimeException('evaluator exploded');
                }
                $skip = $conditions['skip_id'] ?? null;
                $id = is_array($entity) ? ($entity['entity_id'] ?? null) : $entity->getId();
                return $skip === null || (int) $id !== $skip;
            }
        );

        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(
            static function (string $template, $entity, array $context): string {
                $id = is_array($entity) ? $entity['entity_id'] : $entity->getId();
                return $template . '#' . $id . '@' . $context['store_id'];
            }
        );

        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getList')->willReturn($stores);

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, $message, $context];
                }
            );
        }

        $productFactory = $this->createStub(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturnCallback(
            fn() => $this->collectionStub(ProductCollection::class, $pagesByType['product'] ?? [])
        );
        $categoryFactory = $this->createStub(CategoryCollectionFactory::class);
        $categoryFactory->method('create')->willReturnCallback(
            fn() => $this->collectionStub(CategoryCollection::class, $pagesByType['category'] ?? [])
        );
        $cmsFactory = $this->createStub(CmsPageCollectionFactory::class);
        $cmsFactory->method('create')->willReturnCallback(
            fn() => $this->collectionStub(CmsPageCollection::class, $pagesByType['cms'] ?? [])
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn($v): string => json_encode($v));
        $serializer->method('unserialize')->willReturnCallback(static function (string $v) {
            if ($v === 'broken') {
                throw new \InvalidArgumentException('bad');
            }
            return json_decode($v, true);
        });

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('isForceTemplateOverExisting')->willReturn($config['force'] ?? false);

        return new BulkTemplateApply(
            $templateFactory,
            $resource,
            $evaluator,
            $renderer,
            $repository,
            $logger,
            $productFactory,
            $categoryFactory,
            $cmsFactory,
            $this->createStub(ResolvedMetaFactory::class),
            $serializer,
            $seoConfig
        );
    }

    public function testStoresWithoutTemplatesDoNothing(): void
    {
        $this->cron([], [0, 1])->execute();

        $this->assertSame([], $this->upserts);
        $this->assertSame([], $this->logged);
        $this->assertContains(['is_active', 1], $this->templateFilters);
        $this->assertContains(['is_cron_enabled', 1], $this->templateFilters);
        $this->assertContains(['store_id', ['in' => [0, 1]]], $this->templateFilters);
    }

    public function testProductTemplateIsRenderedAndUpsertedAcrossPages(): void
    {
        $template = $this->template('product', [
            'meta_title'       => 'T',
            'meta_description' => 'D',
            'meta_keywords'    => 'K',
            'og_title'         => 'OT',
            'og_image'         => 'OI',
            'robots'           => 'INDEX,FOLLOW',
        ]);

        $this->cron(
            [1 => [$template]],
            [1],
            ['product' => [[self::entity(['id' => 11])], [self::entity(['id' => 12])]]]
        )->execute();

        $this->assertCount(2, $this->upserts);
        [$table, $data, $fields] = $this->upserts[0];
        $this->assertSame('panth_seo_resolved', $table);
        $this->assertSame([
            'store_id'         => 1,
            'entity_type'      => 'product',
            'entity_id'        => 11,
            'meta_title'       => 'T#11@1',
            'meta_description' => 'D#11@1',
            'meta_keywords'    => 'K#11@1',
            'robots'           => 'INDEX,FOLLOW',
            'og_payload'       => '{"og:title":"OT#11@1","og:image":"OI#11@1"}',
            'source'           => 'bulk_template',
        ], $data);
        $this->assertSame(['meta_title', 'meta_description', 'meta_keywords', 'robots', 'og_payload', 'source'], $fields);
        $this->assertSame(12, $this->upserts[1][1]['entity_id']);

        $this->assertCount(1, $this->updates);
        $this->assertSame('panth_seo_template', $this->updates[0][0]);
        $this->assertSame(['template_id = ?' => 3], $this->updates[0][2]);
        $this->assertInstanceOf(\Zend_Db_Expr::class, $this->updates[0][1]['apply_count']);
        $this->assertStringContainsString('template 3 applied to 2 product entities (store 1)', end($this->logged)[1]);
    }

    public function testExistingMetaIsKeptUnlessForced(): void
    {
        $template = $this->template('category', ['meta_title' => 'T', 'meta_description' => 'D']);
        $entity = self::entity(['id' => 5, 'meta_title' => 'Mine', 'meta_description' => ' ']);

        $this->cron([1 => [$template]], [1], ['category' => [[$entity]]])->execute();
        $data = $this->upserts[0][1];
        $this->assertNull($data['meta_title']);
        $this->assertSame('D#5@1', $data['meta_description']);
        $this->assertNull($data['meta_keywords']);
        $this->assertNull($data['og_payload']);

        $this->cron([1 => [$template]], [1], ['category' => [[$entity]]], ['force' => true])->execute();
        $this->assertSame('T#5@1', $this->upserts[0][1]['meta_title']);
    }

    public function testCmsPagesAreStoredAsCmsAndFilteredByStore(): void
    {
        $template = $this->template('cms_page', ['meta_title' => 'T']);

        $this->cron([2 => [$template]], [2], ['cms' => [[['entity_id' => 8]]]])->execute();

        $this->assertSame([2], $this->cmsStoreFilters);
        $this->assertSame('cms', $this->upserts[0][1]['entity_type']);
        $this->assertSame(8, $this->upserts[0][1]['entity_id']);
    }

    public function testConditionsFilterEntitiesAndEntitiesWithoutIdAreSkipped(): void
    {
        $template = $this->template('product', ['meta_title' => 'T'], '{"skip_id":2}');

        $this->cron(
            [1 => [$template]],
            [1],
            ['product' => [[self::entity(['id' => 1]), self::entity(['id' => 2]), self::entity([])]]]
        )->execute();

        $this->assertCount(1, $this->upserts);
        $this->assertSame(1, $this->upserts[0][1]['entity_id']);
        $this->assertStringContainsString('applied to 1 product entities', end($this->logged)[1]);
    }

    public function testBrokenConditionsAreTreatedAsNoConditions(): void
    {
        $template = $this->template('product', ['meta_title' => 'T'], 'broken');

        $this->cron([1 => [$template]], [1], ['product' => [[self::entity(['id' => 1])]]])->execute();

        $this->assertCount(1, $this->upserts);
    }

    public function testNothingProcessedMeansNoStampAndUnknownTypesAreIgnored(): void
    {
        $this->cron([1 => [$this->template('brand'), $this->template('product')]], [1])->execute();

        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->upserts);
        $this->assertStringContainsString('processing store 1 with 2 templates', $this->logged[0][1]);
    }

    public function testATemplateWithoutAnIdIsNotStamped(): void
    {
        $template = $this->template('product', ['meta_title' => 'T'], null, null);

        $this->cron([1 => [$template]], [1], ['product' => [[self::entity(['id' => 1])]]])->execute();

        $this->assertCount(1, $this->upserts);
        $this->assertSame([], $this->updates);
    }

    public function testAFailingTemplateIsLoggedAsAWarning(): void
    {
        $template = $this->template('product', ['meta_title' => 'T'], '{"fail":true}');

        $this->cron([1 => [$template]], [1], ['product' => [[self::entity(['id' => 1])]]])->execute();

        $warning = end($this->logged);
        $this->assertSame('warning', $warning[0]);
        $this->assertSame(['exception' => 'evaluator exploded'], $warning[2]);
    }

    public function testAFailingStoreIsLoggedAsAnError(): void
    {
        $this->cron([1 => new \RuntimeException('no table')], [1])->execute();

        $this->assertSame(
            ['error', 'Panth SEO BulkTemplateApply failed for store 1', ['exception' => 'no table']],
            $this->logged[0]
        );
    }
}
