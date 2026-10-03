<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\CrawlCancel;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\CrawlStatus;
use Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor\Save as BulkEditorSave;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\Delete as CanonicalDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\MassDelete as CanonicalMassDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Delete as FeedDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\DeleteField;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Fields;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Generate;
use Panth\AdvancedSEO\Controller\Adminhtml\Report\MissingMeta;
use Panth\AdvancedSEO\Controller\Adminhtml\Rule\Delete as RuleDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\Rule\Edit as RuleEdit;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Delete as TemplateDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\MassDelete as TemplateMassDelete;
use Panth\AdvancedSEO\Controller\Adminhtml\Url\Regenerate;
use Panth\AdvancedSEO\Model\Audit\CrawlState;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Panth\AdvancedSEO\Model\Feed\ProfileBasedFeedBuilder;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\Collection as CanonicalCollection;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\CollectionFactory as CanonicalCollectionFactory;
use Panth\AdvancedSEO\Model\ResourceModel\Rule as RuleResource;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection as TemplateCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Panth\AdvancedSEO\Model\Url\RewriteRegenerator;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider\CollectionRecorder;
use PHPUnit\Framework\TestCase;

class ActionControllersTest extends TestCase
{
    use ControllerHarness;
    use ConnectionRecorder;
    use CollectionRecorder;

    private array $invalidated = [];

    private function invalidator(): SeoCacheInvalidator
    {
        $this->invalidated = [];
        $invalidator = $this->createStub(SeoCacheInvalidator::class);
        foreach (['invalidateAll', 'invalidateResolvedMeta', 'invalidateRules'] as $method) {
            $invalidator->method($method)->willReturnCallback(function () use ($method): void {
                $this->invalidated[] = $method;
            });
        }
        return $invalidator;
    }

    public function testFeedDeleteRemovesTheProfile(): void
    {
        (new FeedDelete($this->controllerContext(['id' => 3]), $this->recordingResource()))->execute();
        $this->assertSame([['panth_seo_feed_profile', ['feed_id = ?' => 3]]], $this->db['delete']);
        $this->assertSame([['success', 'Feed profile deleted.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);

        (new FeedDelete($this->controllerContext(), $this->recordingResource()))->execute();
        $this->assertSame([], $this->db['delete']);
        $this->assertSame([], $this->messages);
    }

    public function testRuleAndTemplateDeletesInvalidateCaches(): void
    {
        (new RuleDelete($this->controllerContext(['id' => 2]), $this->recordingResource(), $this->invalidator()))->execute();
        $this->assertSame([['panth_seo_rule', ['rule_id = ?' => 2]]], $this->db['delete']);
        $this->assertSame(['invalidateAll'], $this->invalidated);
        $this->assertSame([['success', 'Rule deleted.']], $this->messages);

        (new TemplateDelete($this->controllerContext(['id' => 5]), $this->recordingResource(), $this->invalidator()))->execute();
        $this->assertSame([['panth_seo_template', ['template_id = ?' => 5]]], $this->db['delete']);
        $this->assertSame(['invalidateResolvedMeta'], $this->invalidated);

        (new TemplateDelete($this->controllerContext(), $this->recordingResource(), $this->invalidator()))->execute();
        $this->assertSame([], $this->invalidated);
    }

    public function testDeleteFailuresAreReported(): void
    {
        $failing = $this->createStub(\Magento\Framework\App\ResourceConnection::class);
        $failing->method('getConnection')->willThrowException(new \RuntimeException('locked'));
        $failing->method('getTableName')->willReturnArgument(0);

        (new FeedDelete($this->controllerContext(['id' => 3]), $failing))->execute();
        $this->assertSame([['error', 'locked']], $this->messages);

        (new RuleDelete($this->controllerContext(['id' => 3]), $failing, $this->invalidator()))->execute();
        $this->assertSame([], $this->invalidated);

        (new TemplateDelete($this->controllerContext(['id' => 3]), $failing, $this->invalidator()))->execute();
        $this->assertSame([['error', 'locked']], $this->messages);
    }

    public function testCustomCanonicalDeleteAndMassDelete(): void
    {
        $deleted = [];
        $repository = $this->createStub(CustomCanonicalRepository::class);
        $repository->method('deleteById')->willReturnCallback(static function (int $id) use (&$deleted): void {
            if ($id === 13) {
                throw new \RuntimeException('cannot delete 13');
            }
            $deleted[] = $id;
        });

        (new CanonicalDelete($this->controllerContext(['id' => 4]), $repository))->execute();
        $this->assertSame([4], $deleted);
        $this->assertSame([['success', 'Custom canonical deleted.']], $this->messages);

        (new CanonicalDelete($this->controllerContext(['id' => 13]), $repository))->execute();
        $this->assertSame([['error', 'cannot delete 13']], $this->messages);

        $collection = $this->recordingCollection(CanonicalCollection::class, [
            new class (['id' => 7]) extends DataObject {
                public function getId()
                {
                    return $this->getData('id');
                }
            },
            new class (['id' => 13]) extends DataObject {
                public function getId()
                {
                    return $this->getData('id');
                }
            },
        ]);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->createStub(CanonicalCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        (new CanonicalMassDelete($this->controllerContext(), $filter, $factory, $repository))->execute();
        $this->assertSame([4, 7], $deleted);
        $this->assertSame([['error', 'cannot delete 13'], ['success', 'A total of 1 record(s) have been deleted.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    private function templateMassDelete(array|\Throwable $ids): TemplateMassDelete
    {
        $collection = $this->createStub(TemplateCollection::class);
        if ($ids instanceof \Throwable) {
            $collection->method('getAllIds')->willThrowException($ids);
        } else {
            $collection->method('getAllIds')->willReturn($ids);
        }
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->createStub(TemplateCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new TemplateMassDelete($this->controllerContext(), $this->recordingResource(), $this->invalidator(), $filter, $factory);
    }

    public function testTemplateMassDelete(): void
    {
        $this->templateMassDelete(['3', '0', '4'])->execute();
        $this->assertSame([['panth_seo_template', ['template_id IN (?)' => [0 => 3, 2 => 4]]]], $this->db['delete']);
        $this->assertSame([['success', '2 template(s) deleted.']], $this->messages);
        $this->assertSame(['invalidateResolvedMeta'], $this->invalidated);

        $this->templateMassDelete([])->execute();
        $this->assertSame([['error', 'Please select templates to delete.']], $this->messages);
        $this->assertSame([], $this->db['delete']);

        $this->templateMassDelete(new \RuntimeException('bad filter'))->execute();
        $this->assertSame([['error', 'bad filter']], $this->messages);
    }

    public function testCrawlCancel(): void
    {
        $state = $this->createStub(CrawlState::class);
        $state->method('requestCancel')->willReturnCallback(static fn(int $id): bool => $id === 1);

        (new CrawlCancel($this->controllerContext(), $state))->execute();
        $this->assertSame('error', $this->messages[0][0]);
        $this->assertSame(['*/audit/index', []], $this->redirect);

        (new CrawlCancel($this->controllerContext(['store' => 1]), $state))->execute();
        $this->assertSame('success', $this->messages[0][0]);
        $this->assertSame(['*/audit/index', ['store' => 1]], $this->redirect);

        (new CrawlCancel($this->controllerContext(['store' => 2]), $state))->execute();
        $this->assertSame([['notice', 'No crawl is queued or running for this store view.']], $this->messages);
    }

    private function crawlStatus(array $params, array $state = []): ?array
    {
        $crawlState = $this->createStub(CrawlState::class);
        $crawlState->method('get')->willReturn($state + [
            'status' => CrawlState::STATUS_RUNNING, 'stale' => false, 'crawled' => 3, 'queued' => 5, 'max_pages' => 10,
            'pages' => 3, 'issues' => 1, 'saved' => 2, 'message' => '', 'cancel_requested' => false,
        ]);
        $repository = $this->createStub(StoreRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function (int $id) {
            if ($id === 99) {
                throw new \RuntimeException('missing');
            }
            return $this->createStub(StoreInterface::class);
        });

        (new CrawlStatus($this->controllerContext($params), $crawlState, $repository, $this->jsonFactory()))->execute();

        return $this->json;
    }

    public function testCrawlStatusReportsProgress(): void
    {
        $json = $this->crawlStatus(['store' => 1]);
        $this->assertTrue($json['active']);
        $this->assertSame(3, $json['crawled']);
        $this->assertFalse($json['stopping']);

        $this->assertFalse($this->crawlStatus(['store' => 1], ['stale' => true])['active']);
        $this->assertTrue($this->crawlStatus(['store' => 1], ['status' => CrawlState::STATUS_PENDING, 'stale' => true])['active']);
        $this->assertFalse($this->crawlStatus(['store' => 1], ['status' => CrawlState::STATUS_IDLE])['active']);

        $this->assertSame(['error' => 'A store view is required.'], $this->crawlStatus([]));
        $this->assertSame(['error' => 'Store view 99 does not exist.'], $this->crawlStatus(['store' => 99]));
    }

    public function testRuleEditRegistersTheRuleModel(): void
    {
        $registered = [];
        $registry = $this->createStub(Registry::class);
        $registry->method('register')->willReturnCallback(static function ($key, $value) use (&$registered): void {
            $registered[$key] = $value;
        });
        $connection = $this->recordingResource([], [], null, [['rule_id' => 4, 'name' => 'R'], false])->getConnection();
        $ruleResource = $this->createStub(RuleResource::class);
        $ruleResource->method('getConnection')->willReturn($connection);
        $ruleResource->method('getMainTable')->willReturn('panth_seo_rule');

        (new RuleEdit($this->controllerContext(['id' => 4]), $this->pageFactory(), $registry, $ruleResource))->execute();
        $this->assertSame(['rule_id' => 4, 'name' => 'R'], $registered['panth_seo_rule']->getData());
        $this->assertSame('Edit Rule', $this->page['title']);

        (new RuleEdit($this->controllerContext(['id' => 5]), $this->pageFactory(), $registry, $ruleResource))->execute();
        $this->assertSame([], $registered['panth_seo_rule']->getData());

        (new RuleEdit($this->controllerContext(), $this->pageFactory(), $registry, $ruleResource))->execute();
        $this->assertSame('New Rule', $this->page['title']);
    }

    public function testMissingMetaReportRemembersTheType(): void
    {
        $session = $this->recordingSession(['panth_seo_missing_meta_type' => 'category']);
        (new MissingMeta($this->controllerContext(['type' => 'category']), $this->pageFactory(), $session))->execute();
        $this->assertSame(['panth_seo_missing_meta_type' => 'category'], $this->sessionWrites);
        $this->assertSame('Missing Meta Report - Categories', $this->page['title']);

        new MissingMeta($this->controllerContext(['type' => 'cms']), $this->pageFactory(), $session);
        $this->assertSame('product', $this->sessionWrites['panth_seo_missing_meta_type']);

        (new MissingMeta($this->controllerContext(), $this->pageFactory(), $this->recordingSession([])))->execute();
        $this->assertSame('Missing Meta Report - Products', $this->page['title']);
    }

    public function testBulkEditorSaveUpdatesEachProduct(): void
    {
        $saved = [];
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(static function (int $id) {
            if ($id === 9) {
                throw new \RuntimeException('not found');
            }
            $product = new class extends Product {
                public function __construct()
                {
                }
            };
            $product->setData('entity_id', $id);
            return $product;
        });
        $repository->method('save')->willReturnCallback(static function ($product) use (&$saved) {
            $saved[] = $product->getData();
            return $product;
        });

        (new BulkEditorSave($this->controllerContext([
            'store_id' => 2,
            'rows' => [
                ['entity_id' => 4, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keyword' => 'K'],
                ['entity_id' => 5, 'meta_title' => 'Only title'],
                ['entity_id' => 0],
                ['entity_id' => 9, 'meta_title' => 'x'],
            ],
        ]), $repository))->execute();

        $this->assertSame(['entity_id' => 4, 'store_id' => 2, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keyword' => 'K'], $saved[0]);
        $this->assertSame(['entity_id' => 5, 'store_id' => 2, 'meta_title' => 'Only title'], $saved[1]);
        $this->assertCount(2, $saved);
        $this->assertSame([['error', 'Entity 9: not found'], ['success', '2 row(s) saved.']], $this->messages);
    }

    public function testDeleteFieldReturnsToTheFeedFieldList(): void
    {
        (new DeleteField($this->controllerContext(['field_id' => 3, 'feed_id' => 6]), $this->recordingResource()))->execute();
        $this->assertSame([['panth_seo_feed_field', ['field_id = ?' => 3]]], $this->db['delete']);
        $this->assertSame(['*/feed/fields', ['feed_id' => 6]], $this->redirect);

        (new DeleteField($this->controllerContext(['field_id' => 3]), $this->recordingResource(['8'])))->execute();
        $this->assertSame(['*/feed/fields', ['feed_id' => 8]], $this->redirect);

        (new DeleteField($this->controllerContext(['field_id' => 3]), $this->recordingResource()))->execute();
        $this->assertSame(['*/*/index', []], $this->redirect);

        (new DeleteField($this->controllerContext(), $this->recordingResource()))->execute();
        $this->assertSame([], $this->db['delete']);
        $this->assertSame([], $this->messages);
    }

    public function testDeleteFieldFailuresAreReported(): void
    {
        (new DeleteField($this->controllerContext(['field_id' => 3, 'feed_id' => 6]), $this->recordingResource([], [], new \RuntimeException('x'))))->execute();
        $this->assertSame([['success', 'Field mapping deleted.']], $this->messages);

        $failing = $this->createStub(\Magento\Framework\App\ResourceConnection::class);
        $failing->method('getConnection')->willThrowException(new \RuntimeException('gone'));
        (new DeleteField($this->controllerContext(['field_id' => 3]), $failing))->execute();
        $this->assertSame([['error', 'gone']], $this->messages);
    }

    public function testFieldsPageNeedsAnExistingFeed(): void
    {
        (new Fields($this->controllerContext(), $this->pageFactory(), $this->recordingResource(), $this->recordingSession([])))->execute();
        $this->assertSame([['error', 'Invalid feed profile ID.']], $this->messages);

        (new Fields($this->controllerContext(['feed_id' => 4]), $this->pageFactory(), $this->recordingResource(), $this->recordingSession([])))->execute();
        $this->assertSame([['error', 'Feed profile not found.']], $this->messages);
        $this->assertSame(['*/*/index', []], $this->redirect);

        (new Fields($this->controllerContext(['feed_id' => 4]), $this->pageFactory(), $this->recordingResource(['Google']), $this->recordingSession([])))->execute();
        $this->assertSame('Feed Field Mapping - Google', $this->page['title']);
        $this->assertSame(['panth_seo_feed_field_feed_id' => 4], $this->sessionWrites);
    }

    public function testGenerateReportsStatsOrErrors(): void
    {
        $builder = $this->createStub(ProfileBasedFeedBuilder::class);
        $builder->method('generateById')->willReturnCallback(static function (int $id): array {
            return match ($id) {
                1 => ['product_count' => 12, 'file_size' => 500, 'generation_time' => 1.5],
                2 => ['file_size' => 2048],
                3 => ['file_size' => 5 * 1048576],
                default => throw new \RuntimeException('no products'),
            };
        });

        (new Generate($this->controllerContext(['id' => 1]), $builder))->execute();
        $this->assertSame([['success', 'Feed generated successfully: 12 products exported (500 B) in 1.5 seconds.']], $this->messages);

        (new Generate($this->controllerContext(['id' => 2]), $builder))->execute();
        $this->assertStringContainsString('(2 KB)', $this->messages[0][1]);

        (new Generate($this->controllerContext(['id' => 3]), $builder))->execute();
        $this->assertStringContainsString('(5 MB)', $this->messages[0][1]);

        (new Generate($this->controllerContext(['id' => 4]), $builder))->execute();
        $this->assertSame([['error', 'Error generating feed: no products']], $this->messages);

        (new Generate($this->controllerContext(), $builder))->execute();
        $this->assertSame([['error', 'Invalid feed profile ID.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testRegenerateUrlRewrites(): void
    {
        $calls = [];
        $regenerator = $this->createStub(RewriteRegenerator::class);
        $regenerator->method('regenerateProducts')->willReturnCallback(static function (int $store) use (&$calls): int {
            $calls[] = ['product', $store];
            return 3;
        });
        $regenerator->method('regenerateCategories')->willReturnCallback(static function (int $store) use (&$calls): int {
            $calls[] = ['category', $store];
            if ($store === 9) {
                throw new \RuntimeException('index locked');
            }
            return 2;
        });

        (new Regenerate($this->controllerContext(['store_id' => 1]), $regenerator))->execute();
        $this->assertSame([['product', 1], ['category', 1]], $calls);
        $this->assertSame([['success', 'URL rewrites regenerated successfully. Total: 5 rewrite(s).']], $this->messages);

        $calls = [];
        (new Regenerate($this->controllerContext(['entity_type' => 'category', 'store_id' => 9]), $regenerator))->execute();
        $this->assertSame([['category', 9]], $calls);
        $this->assertSame([['error', 'Error regenerating URL rewrites: index locked']], $this->messages);

        $calls = [];
        (new Regenerate($this->controllerContext(['entity_type' => 'cms']), $regenerator))->execute();
        $this->assertSame([], $calls);
        $this->assertSame('error', $this->messages[0][0]);
        $this->assertSame('Panth_AdvancedSEO::manage', Regenerate::ADMIN_RESOURCE);
    }
}
