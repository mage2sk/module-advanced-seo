<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta as ResolvedMetaIndexer;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use Panth\AdvancedSEO\Model\Meta\Cache;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use Panth\AdvancedSEO\Observer\Cms\SaveAfter;
use Panth\AdvancedSEO\Plugin\Cache\CleanMetaCachePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CacheInvalidationTest extends TestCase
{
    private FrontendInterface $frontend;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->frontend = new class implements FrontendInterface {
            public array $entries = [];

            public function test($identifier)
            {
                return isset($this->entries[$identifier]) ? time() : false;
            }

            public function load($identifier)
            {
                return $this->entries[$identifier]['data'] ?? false;
            }

            public function save($data, $identifier, array $tags = [], $lifeTime = null)
            {
                $this->entries[$identifier] = ['data' => $data, 'tags' => $tags];

                return true;
            }

            public function remove($identifier)
            {
                unset($this->entries[$identifier]);

                return true;
            }

            public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
            {
                foreach ($this->entries as $id => $entry) {
                    if ($mode === \Zend_Cache::CLEANING_MODE_ALL || array_intersect($tags, $entry['tags']) !== []) {
                        unset($this->entries[$id]);
                    }
                }

                return true;
            }

            public function getBackend()
            {
                return null;
            }

            public function getLowLevelFrontend()
            {
                return null;
            }
        };

        $pool = $this->createStub(FrontendPool::class);
        $pool->method('get')->willReturn($this->frontend);
        $factory = $this->createStub(ResolvedMetaFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): ResolvedMeta => new ResolvedMeta());

        $this->cache = new Cache($pool, new Json(), $factory);
    }

    private function meta(string $title, int $id = 5, string $type = MetaResolverInterface::ENTITY_CMS): ResolvedMeta
    {
        $meta = new ResolvedMeta();
        $meta->setStoreId(1);
        $meta->setEntityType($type);
        $meta->setEntityId($id);
        $meta->setMetaTitle($title);

        return $meta;
    }

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isMviewEnabled')->willReturn(false);
        $config->method('isAsyncIndexing')->willReturn(true);

        return $config;
    }

    public function testEntriesCarryMetaEntityAndNativeCacheTags(): void
    {
        $tags = $this->cache->tagsFor(MetaResolverInterface::ENTITY_CMS, 5, 1);

        $this->assertContains('panth_seo_meta', $tags);
        $this->assertContains('panth_seo_meta_cms_5', $tags);
        $this->assertContains('cms_p_5', $tags);
        $this->assertContains('COLLECTION_DATA', $tags);
        $this->assertContains('cat_p_9', $this->cache->tagsFor(MetaResolverInterface::ENTITY_PRODUCT, 9, 1));
        $this->assertContains('cat_c_3', $this->cache->tagsFor(MetaResolverInterface::ENTITY_CATEGORY, 3, 1));
    }

    public function testSavingAPageClearsItsResolvedMetaSoTheNextResolveReturnsTheNewTitle(): void
    {
        $this->cache->save($this->meta('Old Title'));
        $this->cache->save($this->meta('Other Page', 6));
        $this->assertSame('Old Title', $this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1)?->getMetaTitle());

        $observer = new SaveAfter(
            $this->createStub(IndexerRegistry::class),
            $this->createStub(PublisherInterface::class),
            $this->createStub(SeoScorerInterface::class),
            $this->cache,
            $this->config(),
            $this->createStub(LoggerInterface::class)
        );
        $observer->execute(new Observer(['event' => new Event(['object' => new DataObject(['id' => 5])])]));

        $this->assertNull($this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1));
        $this->assertSame('Other Page', $this->cache->load(MetaResolverInterface::ENTITY_CMS, 6, 1)?->getMetaTitle());

        $this->cache->save($this->meta('New Title'));
        $this->assertSame('New Title', $this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1)?->getMetaTitle());
    }

    public function testTheResolvedMetaIndexerCleansEveryRowItRewrites(): void
    {
        $this->cache->save($this->meta('Old Title'));

        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolveBatch')->willReturnCallback(
            fn (string $type, array $ids, int $storeId): array => $type === MetaResolverInterface::ENTITY_CMS
                ? [5 => $this->meta('New Title')]
                : []
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('getList')->willReturn([$store]);
        $map = $this->createStub(EntityTableMap::class);
        $map->method('existingIds')->willReturnCallback(
            static fn (string $type, array $ids): array => $type === MetaResolverInterface::ENTITY_CMS ? [5] : []
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->createStub(AdapterInterface::class));
        $resource->method('getTableName')->willReturnArgument(0);

        $indexer = new ResolvedMetaIndexer(
            $resource,
            $stores,
            $resolver,
            new Json(),
            $this->createStub(LoggerInterface::class),
            $map,
            null,
            null,
            $this->cache
        );
        $indexer->executeRow(5);

        $this->assertNull($this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1));
    }

    public function testCleaningFullPageOrCollectionsAlsoCleansResolvedMeta(): void
    {
        $plugin = new CleanMetaCachePlugin($this->cache, $this->createStub(LoggerInterface::class));
        $types  = $this->createStub(TypeListInterface::class);

        $this->cache->save($this->meta('Old Title'));
        $plugin->afterCleanType($types, null, 'config');
        $this->assertNotNull($this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1));

        $plugin->afterCleanType($types, null, 'full_page');
        $this->assertNull($this->cache->load(MetaResolverInterface::ENTITY_CMS, 5, 1));
    }
}
