<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Model\Indexer\ResolvedMeta;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolvedMetaFanoutTest extends TestCase
{
    private array $resolved = [];

    private function indexer(array $existing): ResolvedMeta
    {
        $this->resolved = [];

        $resolver = $this->createStub(MetaResolverInterface::class);
        $resolver->method('resolveBatch')->willReturnCallback(
            function (string $entityType, array $entityIds, int $storeId): array {
                $this->resolved[] = [$entityType, array_values($entityIds)];
                return [];
            }
        );

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('getList')->willReturn([$store]);

        $map = $this->createStub(EntityTableMap::class);
        $map->method('existingIds')->willReturnCallback(
            static fn (string $entityType, array $ids): array => array_values(
                array_intersect(array_map('intval', $ids), $existing[$entityType] ?? [])
            )
        );

        return new ResolvedMeta(
            $this->createStub(ResourceConnection::class),
            $stores,
            $resolver,
            $this->createStub(Json::class),
            $this->createStub(LoggerInterface::class),
            $map
        );
    }

    public function testSavingAProductDoesNotReindexACategoryOrCmsPageWithTheSameId(): void
    {
        $this->indexer(['product' => [2141]])->executeRow(2141);

        $this->assertSame([['product', [2141]]], $this->resolved);
    }

    public function testAnIdIsReindexedForEveryTypeThatActuallyOwnsIt(): void
    {
        $this->indexer(['product' => [5], 'category' => [5], 'cms' => [5]])->executeRow(5);

        $this->assertSame([['product', [5]], ['category', [5]], ['cms', [5]]], $this->resolved);
    }

    public function testEachTypeOnlySeesTheIdsItOwns(): void
    {
        $this->indexer(['product' => [10, 11], 'category' => [11], 'cms' => []])->executeList([10, 11]);

        $this->assertSame([['product', [10, 11]], ['category', [11]]], $this->resolved);
    }

    public function testAnIdOwnedByNobodyIsNeverResolved(): void
    {
        $this->indexer([])->executeRow(99999);

        $this->assertSame([], $this->resolved);
    }

    public function testAnEmptyIdListDoesNothing(): void
    {
        $this->indexer(['product' => [1]])->execute([]);

        $this->assertSame([], $this->resolved);
    }
}
