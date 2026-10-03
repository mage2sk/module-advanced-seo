<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Observer\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Observer\Category\MoveAfter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MoveAfterTest extends TestCase
{
    private array $invalidated = [];

    private array $published = [];

    private array $wheres = [];

    private array $warnings = [];

    private function observer(
        array $descendants,
        array $products,
        bool $enabled = true,
        bool $missingCategory = false,
        bool $queryFails = false
    ): MoveAfter {
        $this->invalidated = [];
        $this->published = [];
        $this->wheres = [];
        $this->warnings = [];

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $select->method('orWhere')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = ['OR ' . $cond, $value];
            return $select;
        });

        $calls = 0;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturnCallback(
            static function () use (&$calls, $descendants, $products, $queryFails): array {
                if ($queryFails) {
                    throw new \RuntimeException('db gone');
                }
                return ++$calls === 1 ? $descendants : $products;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $category = $this->createStub(CategoryInterface::class);
        $category->method('getPath')->willReturn('1/2/7/');
        $repository = $this->createStub(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(static function () use ($category, $missingCategory) {
            if ($missingCategory) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $category;
        });

        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $topic, $data) {
            $this->published[] = [$topic, json_decode($data, true)];
            return null;
        });

        $cache = $this->createStub(MetaCache::class);
        $cache->method('invalidateEntity')->willReturnCallback(function (string $type, int $id): void {
            $this->invalidated[] = [$type, $id];
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new MoveAfter($resource, $repository, $publisher, $cache, $logger, $config);
    }

    private static function event(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }

    public function testDescendantsAndTheirProductsAreInvalidatedAndRescored(): void
    {
        $this->observer(['7', '8'], ['20', '21', '20'])->execute(self::event(['category_id' => 7]));

        $this->assertSame(['path LIKE ?', '1/2/7/%'], $this->wheres[0]);
        $this->assertSame(['OR entity_id = ?', 7], $this->wheres[1]);
        $this->assertSame(['category_id IN (?)', [7, 8]], $this->wheres[2]);
        $this->assertSame(
            [['category', 7], ['category', 7], ['category', 8], ['product', 20], ['product', 21]],
            $this->invalidated
        );
        $this->assertSame([
            ['panth_seo.score_entity', ['entity_type' => 'product', 'entity_id' => 20, 'store_id' => 0]],
            ['panth_seo.score_entity', ['entity_type' => 'product', 'entity_id' => 21, 'store_id' => 0]],
        ], $this->published);
    }

    public function testTheCategoryObjectIsUsedWhenNoIdIsGiven(): void
    {
        $this->observer([], [])->execute(self::event(['category' => new DataObject(['id' => 9])]));

        $this->assertSame([['category', 9]], $this->invalidated);
        $this->assertSame([], $this->published);
    }

    public function testNothingHappensWithoutACategory(): void
    {
        $this->observer(['1'], ['2'])->execute(self::event([]));

        $this->assertSame([], $this->invalidated);
    }

    public function testDisabledModuleDoesNothing(): void
    {
        $this->observer(['1'], ['2'], false)->execute(self::event(['category_id' => 7]));

        $this->assertSame([], $this->invalidated);
    }

    public function testAMissingCategoryIsIgnored(): void
    {
        $this->observer(['1'], ['2'], true, true)->execute(self::event(['category_id' => 7]));

        $this->assertSame([], $this->invalidated);
        $this->assertSame([], $this->warnings);
    }

    public function testQueryFailuresAreLogged(): void
    {
        $this->observer([], [], true, false, true)->execute(self::event(['category_id' => 7]));

        $this->assertSame([['category', 7]], $this->invalidated);
        $this->assertSame(['Panth SEO category move observer failed', ['error' => 'db gone']], $this->warnings[0]);
    }
}
