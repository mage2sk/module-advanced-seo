<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\MessageQueue\PublisherInterface;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Observer\Category\SaveAfter as CategorySaveAfter;
use Panth\AdvancedSEO\Observer\Cms\SaveAfter as CmsSaveAfter;
use Panth\AdvancedSEO\Observer\Product\SaveAfter as ProductSaveAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveAfterTest extends TestCase
{
    private array $invalidated = [];

    private array $reindexed = [];

    private array $published = [];

    private array $scored = [];

    private array $warnings = [];

    private function observer(
        string $class,
        array $flags = ['enabled' => true],
        bool $scheduled = false,
        bool $scorerFails = false
    ): object {
        $this->invalidated = [];
        $this->reindexed = [];
        $this->published = [];
        $this->scored = [];
        $this->warnings = [];

        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('reindexRow')->willReturnCallback(function ($id): void {
            $this->reindexed[] = $id;
        });
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $topic, $data) {
            $this->published[] = [$topic, json_decode($data, true)];
            return null;
        });

        $scorer = $this->createStub(SeoScorerInterface::class);
        $scorer->method('score')->willReturnCallback(function (string $type, int $id, int $store) use ($scorerFails) {
            if ($scorerFails) {
                throw new \RuntimeException('score failed');
            }
            $this->scored[] = [$type, $id, $store];
            return null;
        });

        $cache = $this->createStub(MetaCache::class);
        $cache->method('invalidateEntity')->willReturnCallback(function (string $type, int $id): void {
            $this->invalidated[] = [$type, $id];
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($flags['enabled'] ?? false);
        $config->method('isMviewEnabled')->willReturn($flags['mview'] ?? false);
        $config->method('isAsyncIndexing')->willReturn($flags['async'] ?? false);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        return new $class($registry, $publisher, $scorer, $cache, $config, $logger);
    }

    private static function event(string $key, ?DataObject $entity): Observer
    {
        return new Observer(['event' => new Event($entity === null ? [] : [$key => $entity])]);
    }

    public static function observers(): array
    {
        return [
            'product'  => [ProductSaveAfter::class, 'product', 'product', 4],
            'category' => [CategorySaveAfter::class, 'category', 'category', 4],
            'cms'      => [CmsSaveAfter::class, 'object', 'cms', 0],
        ];
    }

    #[DataProvider('observers')]
    public function testSynchronousScoringAfterCacheInvalidation(string $class, string $key, string $type, int $store): void
    {
        $this->observer($class)->execute(self::event($key, new DataObject(['id' => '12', 'store_id' => 4])));

        $this->assertSame([[$type, 12]], $this->invalidated);
        $this->assertSame([[$type, 12, $store]], $this->scored);
        $this->assertSame([], $this->published);
        $this->assertSame([], $this->reindexed);
    }

    #[DataProvider('observers')]
    public function testAsyncIndexingPublishesInsteadOfScoring(string $class, string $key, string $type, int $store): void
    {
        $observer = $this->observer($class, ['enabled' => true, 'async' => true]);
        $observer->execute(self::event($key, new DataObject(['id' => 12, 'store_id' => 4])));

        $this->assertSame([], $this->scored);
        $this->assertSame(
            [['panth_seo.score_entity', ['entity_type' => $type, 'entity_id' => 12, 'store_id' => $store]]],
            $this->published
        );
    }

    #[DataProvider('observers')]
    public function testMviewReindexesTheRowUnlessTheIndexerIsScheduled(string $class, string $key, string $type, int $store): void
    {
        $this->observer($class, ['enabled' => true, 'mview' => true])
            ->execute(self::event($key, new DataObject(['id' => 12])));
        $this->assertSame([12], $this->reindexed);
        $this->assertSame([[$type, 12]], $this->invalidated);

        $this->observer($class, ['enabled' => true, 'mview' => true], true)
            ->execute(self::event($key, new DataObject(['id' => 12])));
        $this->assertSame([], $this->reindexed);
    }

    #[DataProvider('observers')]
    public function testDisabledModuleOrMissingEntityDoesNothing(string $class, string $key, string $type, int $store): void
    {
        $this->observer($class, ['enabled' => false])->execute(self::event($key, new DataObject(['id' => 3])));
        $this->assertSame([], $this->invalidated);

        $this->observer($class)->execute(self::event($key, null));
        $this->assertSame([], $this->invalidated);

        $this->observer($class)->execute(self::event($key, new DataObject([])));
        $this->assertSame([], $this->invalidated);
        $this->assertSame([], $this->scored);
    }

    #[DataProvider('observers')]
    public function testFailuresAreLoggedAndSwallowed(string $class, string $key, string $type, int $store): void
    {
        $this->observer($class, ['enabled' => true], false, true)
            ->execute(self::event($key, new DataObject(['id' => 3])));

        $this->assertSame([[$type, 3]], $this->invalidated);
        $this->assertCount(1, $this->warnings);
        $this->assertSame(['error' => 'score failed'], $this->warnings[0][1]);
    }
}
