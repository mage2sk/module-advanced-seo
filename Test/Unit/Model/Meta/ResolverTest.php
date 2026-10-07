<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Api\RuleEvaluatorInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Logger\Logger as SeoDebugLogger;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Meta\Cache;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use Panth\AdvancedSEO\Model\Meta\ResolvedRepository;
use Panth\AdvancedSEO\Model\Meta\Resolver;
use Panth\AdvancedSEO\Model\Meta\Template\ConditionEvaluator;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\Meta\Token\NameToken;
use Panth\AdvancedSEO\Model\Meta\TokenRegistry;
use Panth\AdvancedSEO\Model\ResourceModel\Template\Collection as TemplateCollection;
use Panth\AdvancedSEO\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Panth\AdvancedSEO\Model\Text\Truncator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolverTest extends TestCase
{
    private ?array $override = null;

    private array $templates = [];

    private array|\Throwable $ruleResult = [];

    private ?ResolvedMeta $cached = null;

    private ?ResolvedMeta $indexed = null;

    private mixed $entity = null;

    private ?string $canonical = 'https://example.com/canonical';

    private bool $useTemplates = true;

    private bool $forceTemplate = false;

    private bool $debug = false;

    private bool $factoryFails = false;

    private int $titleMax = 60;

    private array $savedToCache = [];

    private ?LoggerInterface $logger = null;

    private ?SeoDebugLogger $debugLogger = null;

    private function resolver(): Resolver
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(fn () => $this->override ?? false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $repository = $this->createStub(ResolvedRepository::class);
        $repository->method('find')->willReturnCallback(fn () => $this->indexed);

        $factory = $this->createStub(ResolvedMetaFactory::class);
        $factory->method('create')->willReturnCallback(function (): ResolvedMeta {
            if ($this->factoryFails) {
                throw new \RuntimeException('factory broke');
            }

            return new ResolvedMeta();
        });

        $cache = $this->createStub(Cache::class);
        $cache->method('load')->willReturnCallback(fn () => $this->cached);
        $cache->method('save')->willReturnCallback(function (ResolvedMeta $meta): void {
            $this->savedToCache[] = $meta;
        });

        $collection = $this->createStub(TemplateCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(
            fn () => new \ArrayIterator(array_map(static fn (array $row) => new DataObject($row), $this->templates))
        );
        $collectionFactory = $this->createStub(TemplateCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $rules = $this->createStub(RuleEvaluatorInterface::class);
        $rules->method('evaluate')->willReturnCallback(function () {
            if ($this->ruleResult instanceof \Throwable) {
                throw $this->ruleResult;
            }

            return $this->ruleResult;
        });

        $canonical = $this->createStub(CanonicalResolverInterface::class);
        $canonical->method('getCanonicalUrl')->willReturnCallback(function () {
            if ($this->canonical === null) {
                throw new \RuntimeException('no canonical');
            }

            return $this->canonical;
        });

        $entityLoader = function () {
            if ($this->entity instanceof \Throwable) {
                throw $this->entity;
            }

            return $this->entity;
        };
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturnCallback($entityLoader);
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturnCallback($entityLoader);
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturnCallback($entityLoader);

        $config = $this->createStub(Config::class);
        $config->method('useTemplates')->willReturnCallback(fn () => $this->useTemplates);
        $config->method('isForceTemplateOverExisting')->willReturnCallback(fn () => $this->forceTemplate);
        $config->method('isDebug')->willReturnCallback(fn () => $this->debug);
        $config->method('getTitleMaxLength')->willReturnCallback(fn () => $this->titleMax);
        $config->method('getDescriptionMaxLength')->willReturn(160);

        $map = $this->createStub(EntityTableMap::class);
        $map->method('aliasesFor')->willReturnCallback(static fn (string $type): array => [$type]);

        $logger = $this->logger ?? $this->createStub(LoggerInterface::class);

        return new Resolver(
            $resource,
            $repository,
            $factory,
            $cache,
            $collectionFactory,
            new TemplateRenderer(new TokenRegistry(['name' => new NameToken()]), $logger, new Truncator()),
            $rules,
            $canonical,
            $products,
            $categories,
            $pages,
            $this->createStub(StoreManagerInterface::class),
            $config,
            $logger,
            new Truncator(),
            $map,
            $this->debugLogger,
            new ConditionEvaluator()
        );
    }

    private function product(array $data = [], array $custom = [], string $name = 'Blue Shirt'): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static fn (string $key = '') => $data[$key] ?? null);
        $product->method('getName')->willReturn($name);
        $product->method('getSku')->willReturn('SKU1');
        $product->method('getFinalPrice')->willReturn(10.0);
        $product->method('getCategoryIds')->willReturn([3]);
        $product->method('getCustomAttribute')->willReturnCallback(
            static fn (string $code) => isset($custom[$code]) ? new AttributeValue(['value' => $custom[$code]]) : null
        );

        return $product;
    }

    private function indexedMeta(string $title): ResolvedMeta
    {
        $meta = new ResolvedMeta();
        $meta->setStoreId(1)->setEntityType('product')->setEntityId(5)->setMetaTitle($title)->setSource('template');

        return $meta;
    }

    public function testCacheHitIsReturnedAndLoggedWhenDebugging(): void
    {
        $this->cached = $this->indexedMeta('Cached');
        $this->debug = true;
        $this->debugLogger = $this->createMock(SeoDebugLogger::class);
        $this->debugLogger->expects($this->once())->method('debug')->with(
            'panth_seo: meta.resolved',
            $this->callback(static fn (array $ctx): bool => $ctx['source'] === 'cache')
        );

        $this->assertSame($this->cached, $this->resolver()->resolve('product', 5, 1));
        $this->assertSame([], $this->savedToCache);
    }

    public function testDebugLoggerIsSilentWhenDebugIsOff(): void
    {
        $this->cached = $this->indexedMeta('Cached');
        $this->debugLogger = $this->createMock(SeoDebugLogger::class);
        $this->debugLogger->expects($this->never())->method('debug');

        $this->assertSame('Cached', $this->resolver()->resolve('product', 5, 1)->getMetaTitle());
    }

    public function testIndexedRowWithoutMatchingRulesIsReturnedAndCached(): void
    {
        $this->indexed = $this->indexedMeta('Indexed');
        $this->entity = $this->product();
        $this->ruleResult = ['matched_rules' => []];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame($this->indexed, $meta);
        $this->assertSame('template', $meta->getSource());
        $this->assertSame([$meta], $this->savedToCache);
    }

    public function testLiveRulesOverlayTheIndexedRow(): void
    {
        $this->indexed = $this->indexedMeta('Indexed');
        $this->entity = $this->product();
        $this->ruleResult = [
            'matched_rules'        => [7],
            'title_template'       => 'Buy {{name}}',
            'description_template' => 'Great {{name}} deals',
            'noindex'              => true,
            'nofollow'             => true,
            'canonical'            => 'https://example.com/rule',
        ];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Buy Blue Shirt', $meta->getMetaTitle());
        $this->assertSame('Great Blue Shirt deals', $meta->getMetaDescription());
        $this->assertSame('noindex,nofollow', $meta->getRobots());
        $this->assertSame('https://example.com/rule', $meta->getCanonicalUrl());
        $this->assertSame('rule', $meta->getSource());
    }

    public function testNoindexRuleWithoutNofollowKeepsFollow(): void
    {
        $this->indexed = $this->indexedMeta('Indexed');
        $this->entity = $this->product();
        $this->ruleResult = ['matched_rules' => [1], 'noindex' => true];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('noindex,follow', $meta->getRobots());
        $this->assertSame('Indexed', $meta->getMetaTitle());
    }

    public function testRuleEngineFailureKeepsTheIndexedRow(): void
    {
        $this->indexed = $this->indexedMeta('Indexed');
        $this->entity = $this->product();
        $this->ruleResult = new \RuntimeException('rules down');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with('Panth SEO rule engine failed', ['error' => 'rules down']);

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('template', $meta->getSource());
    }

    public function testOverrideWinsOnLiveRender(): void
    {
        $this->entity = $this->product();
        $this->override = [
            'meta_title'       => 'Override title',
            'meta_description' => 'Override description',
            'meta_keywords'    => 'override, keywords',
            'robots'           => 'noindex,nofollow',
            'canonical_url'    => 'https://example.com/override',
        ];
        $this->ruleResult = ['matched_rules' => [1], 'title_template' => 'Rule {{name}}'];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Override title', $meta->getMetaTitle());
        $this->assertSame('Override description', $meta->getMetaDescription());
        $this->assertSame('override, keywords', $meta->getMetaKeywords());
        $this->assertSame('noindex,nofollow', $meta->getRobots());
        $this->assertSame('https://example.com/override', $meta->getCanonicalUrl());
        $this->assertSame('override', $meta->getSource());
        $this->assertSame(5, $meta->getEntityId());
        $this->assertCount(1, $this->savedToCache);
    }

    public function testRuleTemplatesApplyWhenThereIsNoOverride(): void
    {
        $this->entity = $this->product();
        $this->indexed = new ResolvedMeta();
        $this->ruleResult = [
            'matched_rules'        => [1],
            'title_template'       => 'Rule {{name}}',
            'description_template' => 'Rule description for {{name}}',
            'noindex'              => true,
            'canonical'            => 'https://example.com/rule',
        ];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Rule Blue Shirt', $meta->getMetaTitle());
        $this->assertSame('Rule description for Blue Shirt', $meta->getMetaDescription());
        $this->assertSame('noindex,follow', $meta->getRobots());
        $this->assertSame('https://example.com/rule', $meta->getCanonicalUrl());
        $this->assertSame('rule', $meta->getSource());
    }

    public function testLiveRenderNoindexRuleKeepsNofollow(): void
    {
        $this->entity = $this->product();
        $this->indexed = new ResolvedMeta();
        $this->ruleResult = ['matched_rules' => [1], 'noindex' => true, 'nofollow' => true];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('noindex,nofollow', $meta->getRobots());
        $this->assertSame('rule', $meta->getSource());
    }

    public function testTemplateYieldsToNativeMetaUnlessForced(): void
    {
        $this->entity = $this->product(['meta_title' => 'Native title'], ['meta_description' => 'Native description']);
        $this->templates = [[
            'template_id'      => 1,
            'meta_title'       => '{{name}} | Shop',
            'meta_description' => 'Order {{name}} now',
            'meta_keywords'    => '{{name}}, shirts',
            'robots'           => 'index,follow',
        ]];

        $meta = $this->resolver()->resolve('product', 5, 1);
        $this->assertSame('Native title', $meta->getMetaTitle());
        $this->assertSame('Native description', $meta->getMetaDescription());
        $this->assertSame('Blue Shirt, shirts', $meta->getMetaKeywords());
        $this->assertSame('index,follow', $meta->getRobots());
        $this->assertSame('template', $meta->getSource());
        $this->assertSame('https://example.com/canonical', $meta->getCanonicalUrl());

        $this->forceTemplate = true;
        $forced = $this->resolver()->resolve('product', 5, 1);
        $this->assertSame('Blue Shirt | Shop', $forced->getMetaTitle());
        $this->assertSame('Order Blue Shirt now', $forced->getMetaDescription());
    }

    public function testTemplateConditionsSelectTheFirstMatchingTemplate(): void
    {
        $this->entity = $this->product();
        $this->templates = [
            ['template_id' => 1, 'meta_title' => 'Broken', 'conditions_serialized' => '{bad json'],
            [
                'template_id' => 2,
                'meta_title' => 'Category nine',
                'conditions_serialized' => json_encode(['conditions' => [
                    ['attribute' => 'category_ids', 'value' => '9'],
                ]]),
            ],
            [
                'template_id' => 3,
                'meta_title' => 'Category three {{name}}',
                'conditions_serialized' => json_encode(['conditions' => [
                    ['attribute' => 'category_ids', 'value' => '3'],
                ]]),
            ],
        ];

        $this->assertSame('Category three Blue Shirt', $this->resolver()->resolve('product', 5, 1)->getMetaTitle());
    }

    public function testConditionalTemplatesAreSkippedWithoutAnEntity(): void
    {
        $this->entity = new NoSuchEntityException();
        $this->templates = [
            ['meta_title' => 'Conditional', 'conditions_serialized' => '{"conditions":[{"attribute":"a","value":"b"}]}'],
            ['meta_title' => 'Unconditional', 'conditions_serialized' => '[]'],
        ];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Unconditional', $meta->getMetaTitle());
        $this->assertSame('template', $meta->getSource());
    }

    public function testEmptyDecodedConditionsMatch(): void
    {
        $this->entity = $this->product();
        $this->templates = [['meta_title' => 'Empty object', 'conditions_serialized' => ' {} ']];

        $this->assertSame('Empty object', $this->resolver()->resolve('product', 5, 1)->getMetaTitle());
    }

    public function testTemplatesAreIgnoredWhenDisabled(): void
    {
        $this->entity = $this->product();
        $this->useTemplates = false;
        $this->templates = [['meta_title' => 'Template']];

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Blue Shirt', $meta->getMetaTitle());
        $this->assertSame('fallback', $meta->getSource());
    }

    public function testProductFallbackUsesNativeFields(): void
    {
        $this->entity = $this->product([], ['short_description' => '<p>Soft cotton shirt</p>', 'meta_keyword' => 'shirt']);
        $this->canonical = null;

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Blue Shirt', $meta->getMetaTitle());
        $this->assertSame('Soft cotton shirt', $meta->getMetaDescription());
        $this->assertSame('shirt', $meta->getMetaKeywords());
        $this->assertNull($meta->getCanonicalUrl());
        $this->assertSame('', $meta->getRobots());
    }

    public function testFallbackDescriptionDropsStyleAndScriptContent(): void
    {
        $this->entity = $this->product([], [
            'short_description' => '<style>#html-body [data-pb-style=AB12]{display:flex}</style>'
                . '<script>var x = 1;</script><p>Soft&nbsp;cotton &amp; linen</p>' . "\n\n" . '<div>shirt</div>',
        ]);

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Soft cotton & linen shirt', $meta->getMetaDescription());
    }

    public function testCategoryFallbackDescriptionDropsPageBuilderStyles(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn('Tops');
        $category->method('getData')->willReturn(null);
        $category->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getDescription'
                ? '<style>#html-body [data-pb-style=W2ODTML]{display:flex;flex-direction:column}</style>'
                    . '<div data-pb-style="W2ODTML"><h2>Women tops</h2><p>Style that moves.</p></div>'
                : null
        );
        $this->entity = $category;

        $meta = $this->resolver()->resolve('category', 3, 1);

        $this->assertSame('Women tops Style that moves.', $meta->getMetaDescription());
        $this->assertStringNotContainsString('data-pb-style', (string) $meta->getMetaDescription());
    }

    public function testProductFallbackPrefersMetaFields(): void
    {
        $this->entity = $this->product(['meta_title' => 'Meta title'], ['meta_description' => 'Meta description']);

        $meta = $this->resolver()->resolve('product', 5, 1);

        $this->assertSame('Meta title', $meta->getMetaTitle());
        $this->assertSame('Meta description', $meta->getMetaDescription());
        $this->assertNull($meta->getMetaKeywords());
    }

    public function testCategoryFallback(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn('Shirts');
        $category->method('getData')->willReturnCallback(
            static fn (string $key = '') => ['meta_keywords' => 'shirts, tops'][$key] ?? null
        );
        $category->method('__call')->willReturnCallback(
            static fn (string $method) => $method === 'getDescription' ? '<p>All our shirts</p>' : null
        );
        $this->entity = $category;

        $meta = $this->resolver()->resolve('category', 3, 1);

        $this->assertSame('Shirts', $meta->getMetaTitle());
        $this->assertSame('All our shirts', $meta->getMetaDescription());
        $this->assertSame('shirts, tops', $meta->getMetaKeywords());
        $this->assertSame('category', $meta->getEntityType());
    }

    public function testCategoryTemplateYieldsToNativeMeta(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn('Shirts');
        $category->method('getData')->willReturnCallback(
            static fn (string $key = '') => ['meta_title' => 'Cat meta', 'meta_description' => 'Cat desc'][$key] ?? null
        );
        $this->entity = $category;
        $this->templates = [['meta_title' => 'T {{name}}', 'meta_description' => 'D {{name}}']];

        $meta = $this->resolver()->resolve('category', 3, 1);

        $this->assertSame('Cat meta', $meta->getMetaTitle());
        $this->assertSame('Cat desc', $meta->getMetaDescription());
    }

    public function testCmsPageFallbackAndNativeMeta(): void
    {
        $page = $this->createStub(Page::class);
        $page->method('getTitle')->willReturn('About us');
        $page->method('getMetaTitle')->willReturn('');
        $page->method('getMetaDescription')->willReturn('');
        $page->method('getMetaKeywords')->willReturn('about');
        $this->entity = $page;

        $meta = $this->resolver()->resolve('cms', 2, 1);
        $this->assertSame('About us', $meta->getMetaTitle());
        $this->assertNull($meta->getMetaDescription());
        $this->assertSame('about', $meta->getMetaKeywords());

        $withMeta = $this->createStub(Page::class);
        $withMeta->method('getMetaTitle')->willReturn('Page meta');
        $withMeta->method('getMetaDescription')->willReturn('Page desc');
        $this->entity = $withMeta;
        $this->templates = [['meta_title' => 'Tpl', 'meta_description' => 'Tpl desc']];

        $templated = $this->resolver()->resolve('cms', 2, 1);
        $this->assertSame('Page meta', $templated->getMetaTitle());
        $this->assertSame('Page desc', $templated->getMetaDescription());
    }

    public function testUnknownTypeOrFailedLoadFallsBackToNullMeta(): void
    {
        $meta = $this->resolver()->resolve('faq', 1, 1);
        $this->assertNull($meta->getMetaTitle());
        $this->assertSame('fallback', $meta->getSource());

        $this->entity = new \RuntimeException('db gone');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with(
            'Panth SEO entity load failed',
            ['type' => 'product', 'id' => 5, 'error' => 'db gone']
        );
        $this->assertNull($this->resolver()->resolve('product', 5, 1)->getMetaTitle());
    }

    public function testLongTitlesAreCollapsedAndTruncated(): void
    {
        $this->titleMax = 20;
        $this->entity = $this->product(['meta_title' => "A   very long\ntitle that keeps going"]);

        $this->assertSame('A very long...', $this->resolver()->resolve('product', 5, 1)->getMetaTitle());
    }

    public function testResolveBatchRendersEachIdAndLogsFailures(): void
    {
        $this->assertSame([], $this->resolver()->resolveBatch('product', [], 1));

        $this->entity = $this->product();
        $result = $this->resolver()->resolveBatch('product', ['4', 6], 1);
        $this->assertSame([4, 6], array_keys($result));
        $this->assertSame('Blue Shirt', $result[6]->getMetaTitle());
    }

    public function testResolveBatchSkipsIdsThatThrow(): void
    {
        $this->factoryFails = true;
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with(
            'Panth SEO resolveBatch failed',
            $this->callback(static fn (array $ctx): bool => $ctx['entity_id'] === 9 && $ctx['error'] === 'factory broke')
        );

        $this->assertSame([], $this->resolver()->resolveBatch('faq', [9], 1));
    }
}
