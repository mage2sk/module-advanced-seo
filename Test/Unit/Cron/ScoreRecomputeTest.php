<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Cron\ScoreRecompute;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScoreRecomputeTest extends TestCase
{
    private array $scored = [];

    private array $logged = [];

    private array $limits = [];

    private array $tables = [];

    private function cron(array $idsByTable, array $failingIds = [], bool $queryFails = false): ScoreRecompute
    {
        $this->scored = [];
        $this->logged = [];
        $this->limits = [];
        $this->tables = [];

        $current = '';
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(
            function (array $name) use ($select, &$current): Select {
                $current = (string) reset($name);
                $this->tables[] = $current;
                return $select;
            }
        );
        $select->method('joinLeft')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnCallback(
            function (int $count) use ($select): Select {
                $this->limits[] = $count;
                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(static fn($v): string => "'" . $v . "'");
        $connection->method('fetchCol')->willReturnCallback(
            static function () use ($idsByTable, &$current, $queryFails): array {
                if ($queryFails) {
                    throw new \RuntimeException('db down');
                }
                return $idsByTable[$current] ?? [];
            }
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scorer = $this->createStub(SeoScorerInterface::class);
        $scorer->method('score')->willReturnCallback(
            function (string $type, int $id, int $storeId) use ($failingIds) {
                if (in_array($id, $failingIds, true)) {
                    throw new \RuntimeException('scorer broke');
                }
                $this->scored[] = [$type, $id, $storeId];
                return null;
            }
        );

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('2');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, $message, $context];
                }
            );
        }

        return new ScoreRecompute($scorer, $resource, $storeManager, $logger);
    }

    public function testStoresWithTheModuleSwitchedOffAreSkipped(): void
    {
        $scorer = $this->createMock(\Panth\AdvancedSEO\Api\SeoScorerInterface::class);
        $scorer->expects($this->never())->method('score');
        $store = $this->createStub(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(\Magento\Store\Model\StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $resource = $this->createStub(\Magento\Framework\App\ResourceConnection::class);
        $config = $this->createStub(\Panth\AdvancedSEO\Helper\Config::class);
        $config->method('isEnabled')->willReturn(false);

        (new ScoreRecompute(
            $scorer,
            $resource,
            $storeManager,
            $this->createStub(\Psr\Log\LoggerInterface::class),
            $config
        ))->execute();
    }

    public function testProductsAndCategoriesAreScoredInBatchesPerStore(): void
    {
        $this->cron([
            'catalog_product_entity'  => ['5', '6'],
            'catalog_category_entity' => ['9'],
        ])->execute();

        $this->assertSame(['catalog_product_entity', 'catalog_category_entity'], $this->tables);
        $this->assertSame([500, 100], $this->limits);
        $this->assertSame([['product', 5, 2], ['product', 6, 2], ['category', 9, 2]], $this->scored);
    }

    public function testAFailingEntityIsLoggedAndTheRestAreStillScored(): void
    {
        $this->cron(['catalog_product_entity' => ['5', '6']], [5])->execute();

        $this->assertSame([['product', 6, 2]], $this->scored);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertSame(['type' => 'product', 'id' => '5', 'error' => 'scorer broke'], $this->logged[0][2]);
    }

    public function testAFailedQueryIsLoggedForEachEntityType(): void
    {
        $this->cron([], [], true)->execute();

        $this->assertSame([], $this->scored);
        $this->assertCount(2, $this->logged);
        $this->assertSame(['error', 'Panth SEO ScoreRecompute: db down', []], $this->logged[0]);
    }
}
