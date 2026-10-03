<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\Meta\Cache;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private object $frontend;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->frontend = new class implements FrontendInterface {
            public array $loads = [];

            public array $saved = [];

            public array $cleaned = [];

            public bool $failSave = false;

            public function test($identifier)
            {
                return false;
            }

            public function load($identifier)
            {
                return $this->loads[$identifier] ?? false;
            }

            public function save($data, $identifier, array $tags = [], $lifeTime = null)
            {
                if ($this->failSave) {
                    throw new \RuntimeException('backend down');
                }
                $this->saved[] = [$data, $identifier, $tags, $lifeTime];

                return true;
            }

            public function remove($identifier)
            {
                return true;
            }

            public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
            {
                $this->cleaned[] = [$mode, $tags];

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

        $pool = $this->createMock(FrontendPool::class);
        $pool->expects($this->once())->method('get')->with('collections')->willReturn($this->frontend);
        $factory = $this->createStub(ResolvedMetaFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): ResolvedMeta => new ResolvedMeta());

        $this->cache = new Cache($pool, new Json(), $factory);
    }

    public function testMissingOrUnreadableEntriesLoadAsNull(): void
    {
        $this->assertNull($this->cache->load('product', 1, 1));

        $this->frontend->loads['panth_seo_meta_1_product_1'] = '';
        $this->assertNull($this->cache->load('product', 1, 1));

        $this->frontend->loads['panth_seo_meta_1_product_1'] = '{not json';
        $this->assertNull($this->cache->load('product', 1, 1));

        $this->frontend->loads['panth_seo_meta_1_product_1'] = '"scalar"';
        $this->assertNull($this->cache->load('product', 1, 1));
    }

    public function testPartialPayloadIsHydratedWithDefaults(): void
    {
        $this->frontend->loads['panth_seo_meta_2_cms_4'] = json_encode([
            'meta_title' => 'About',
            'og_payload' => 'not-an-array',
            'jsonld_payload' => ['@type' => 'WebPage'],
        ]);

        $meta = $this->cache->load('cms', 4, 2);

        $this->assertInstanceOf(ResolvedMeta::class, $meta);
        $this->assertSame(2, $meta->getStoreId());
        $this->assertSame('cms', $meta->getEntityType());
        $this->assertSame(4, $meta->getEntityId());
        $this->assertSame('About', $meta->getMetaTitle());
        $this->assertNull($meta->getMetaDescription());
        $this->assertSame([], $meta->getOgPayload());
        $this->assertSame(['@type' => 'WebPage'], $meta->getJsonldPayload());
        $this->assertSame([], $meta->getHreflangPayload());
        $this->assertSame('cache', $meta->getSource());
    }

    public function testSaveWritesKeyTagsAndLifetimeAndRoundTrips(): void
    {
        $meta = new ResolvedMeta();
        $meta->setStoreId(3)->setEntityType('category')->setEntityId(8)
            ->setMetaTitle('Shoes')->setMetaDescription('All shoes')->setMetaKeywords('shoes')
            ->setCanonicalUrl('https://example.com/shoes')->setRobots('index,follow')
            ->setHreflangPayload(['en' => 'https://example.com/shoes'])->setSource('template');

        $this->cache->save($meta);

        $this->assertCount(1, $this->frontend->saved);
        [$data, $key, $tags, $lifetime] = $this->frontend->saved[0];
        $this->assertSame('panth_seo_meta_3_category_8', $key);
        $this->assertSame(7200, $lifetime);
        $this->assertSame($this->cache->tagsFor('category', 8, 3), $tags);

        $this->frontend->loads[$key] = $data;
        $loaded = $this->cache->load('category', 8, 3);
        $this->assertSame('All shoes', $loaded->getMetaDescription());
        $this->assertSame('https://example.com/shoes', $loaded->getCanonicalUrl());
        $this->assertSame('index,follow', $loaded->getRobots());
        $this->assertSame(['en' => 'https://example.com/shoes'], $loaded->getHreflangPayload());
        $this->assertSame('template', $loaded->getSource());
    }

    public function testSaveSwallowsBackendFailures(): void
    {
        $this->frontend->failSave = true;
        $meta = new ResolvedMeta();
        $meta->setStoreId(1)->setEntityType('product')->setEntityId(1);

        $this->cache->save($meta);

        $this->assertSame([], $this->frontend->saved);
    }

    public function testTagsForUnknownTypeHaveNoNativeTag(): void
    {
        $this->assertSame(
            [
                'panth_seo_faq_2',
                'panth_seo_store_1',
                'panth_seo_faq',
                Cache::GLOBAL_TAG,
                'panth_seo_meta_faq_2',
                Cache::COLLECTIONS_TAG,
            ],
            $this->cache->tagsFor('faq', 2, 1)
        );
        $this->assertSame('panth_seo_meta_product_7', Cache::entityTag('product', 7));
    }

    public function testInvalidationCleansMatchingTags(): void
    {
        $this->cache->invalidateEntity('product', 5);
        $this->cache->invalidateAll();
        $this->cache->invalidateStore(4);

        $mode = \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG;
        $this->assertSame(
            [
                [$mode, ['panth_seo_product_5', 'panth_seo_meta_product_5']],
                [$mode, [Cache::GLOBAL_TAG]],
                [$mode, ['panth_seo_store_4']],
            ],
            $this->frontend->cleaned
        );
    }

    public function testBulkInvalidationIsDedupedAndChunked(): void
    {
        $ids = array_merge(range(1, 150), ['3', 5]);
        $this->cache->invalidateEntities('cms', $ids);

        $this->assertCount(2, $this->frontend->cleaned);
        $this->assertCount(200, $this->frontend->cleaned[0][1]);
        $this->assertCount(100, $this->frontend->cleaned[1][1]);
        $this->assertSame(['panth_seo_cms_1', 'panth_seo_meta_cms_1'], array_slice($this->frontend->cleaned[0][1], 0, 2));

        $this->frontend->cleaned = [];
        $this->cache->invalidateEntities('cms', []);
        $this->assertSame([], $this->frontend->cleaned);
    }
}
