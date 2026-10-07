<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use Panth\AdvancedSEO\Model\Meta\Cache as MetaCache;
use Panth\AdvancedSEO\Observer\Category\DeleteAfter as CategoryDeleteAfter;
use Panth\AdvancedSEO\Observer\Cms\DeleteAfter as CmsDeleteAfter;
use Panth\AdvancedSEO\Observer\Product\DeleteAfter as ProductDeleteAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeleteAfterTest extends TestCase
{
    private array $cleaned = [];

    private array $invalidated = [];

    private function cleaner(bool $throws = false): OrphanCleaner
    {
        $cleaner = $this->createStub(OrphanCleaner::class);
        $cleaner->method('forEntity')->willReturnCallback(
            function (string $entityType, int $entityId, bool $includeAuthored = false) use ($throws): array {
                if ($throws) {
                    throw new \RuntimeException('cleanup failed');
                }
                $this->cleaned[] = [$entityType, $entityId, $includeAuthored];
                return [];
            }
        );

        return $cleaner;
    }

    private function cache(): MetaCache
    {
        $cache = $this->createStub(MetaCache::class);
        $cache->method('invalidateEntity')->willReturnCallback(
            function (string $entityType, int $entityId): void {
                $this->invalidated[] = [$entityType, $entityId];
            }
        );

        return $cache;
    }

    private function observerFor(string $key, ?DataObject $entity): Observer
    {
        $event = new Event($entity === null ? [] : [$key => $entity]);

        return new Observer(['event' => $event]);
    }

    protected function setUp(): void
    {
        $this->cleaned = [];
        $this->invalidated = [];
    }

    #[DataProvider('entities')]
    public function testDeletingAnEntityClearsItsSeoRows(
        string $observerClass,
        string $eventKey,
        string $entityType
    ): void {
        $observer = new $observerClass($this->cleaner(), $this->cache(), $this->createStub(LoggerInterface::class));
        $observer->execute($this->observerFor($eventKey, new DataObject(['id' => 77])));

        $this->assertSame([[$entityType, 77, false]], $this->cleaned);
        $this->assertSame([[$entityType, 77]], $this->invalidated);
    }

    #[DataProvider('entities')]
    public function testNothingHappensWithoutAnEntity(
        string $observerClass,
        string $eventKey,
        string $entityType
    ): void {
        $observer = new $observerClass($this->cleaner(), $this->cache(), $this->createStub(LoggerInterface::class));
        $observer->execute($this->observerFor($eventKey, null));

        $this->assertSame([], $this->cleaned);
        $this->assertSame([], $this->invalidated);
    }

    #[DataProvider('entities')]
    public function testNothingHappensForAnEntityWithoutAnId(
        string $observerClass,
        string $eventKey,
        string $entityType
    ): void {
        $observer = new $observerClass($this->cleaner(), $this->cache(), $this->createStub(LoggerInterface::class));
        $observer->execute($this->observerFor($eventKey, new DataObject()));

        $this->assertSame([], $this->cleaned);
    }

    #[DataProvider('entities')]
    public function testAFailedCleanupNeverBreaksTheDelete(
        string $observerClass,
        string $eventKey,
        string $entityType
    ): void {
        $observer = new $observerClass($this->cleaner(true), $this->cache(), $this->createStub(LoggerInterface::class));

        $observer->execute($this->observerFor($eventKey, new DataObject(['id' => 77])));

        $this->assertSame([], $this->cleaned);
    }

    public static function entities(): array
    {
        return [
            'product'  => [ProductDeleteAfter::class, 'product', 'product'],
            'category' => [CategoryDeleteAfter::class, 'category', 'category'],
            'cms page' => [CmsDeleteAfter::class, 'object', 'cms'],
        ];
    }

    public function testHandEnteredRowsSurviveADelete(): void
    {
        $observer = new ProductDeleteAfter($this->cleaner(), $this->cache(), $this->createStub(LoggerInterface::class));
        $observer->execute($this->observerFor('product', new DataObject(['id' => 5])));

        $this->assertFalse($this->cleaned[0][2]);
    }
}
