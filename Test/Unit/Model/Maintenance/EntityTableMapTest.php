<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Maintenance;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AdvancedSEO\Model\Maintenance\EntityTableMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EntityTableMapTest extends TestCase
{
    private function map(): EntityTableMap
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $value): string => '`' . str_replace('.', '`.`', $value) . '`'
        );
        $connection->method('quote')->willReturnCallback(
            static fn (string $value): string => "'" . $value . "'"
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(
            static fn (string $table): string => $table
        );

        return new EntityTableMap($resource);
    }

    #[DataProvider('mappedTypes')]
    public function testMappedTypesResolveToTheCatalogTable(string $entityType, string $table, string $key): void
    {
        $map = $this->map();

        $this->assertTrue($map->isMapped($entityType));
        $this->assertSame($table, $map->tableFor($entityType));
        $this->assertSame($key, $map->primaryKeyFor($entityType));
    }

    public static function mappedTypes(): array
    {
        return [
            'product'  => ['product', 'catalog_product_entity', 'entity_id'],
            'category' => ['category', 'catalog_category_entity', 'entity_id'],
            'cms'      => ['cms', 'cms_page', 'page_id'],
            'cms_page' => ['cms_page', 'cms_page', 'page_id'],
        ];
    }

    public function testUnknownTypeIsNotMapped(): void
    {
        $map = $this->map();

        $this->assertFalse($map->isMapped('landing_page'));
        $this->assertNull($map->tableFor('landing_page'));
        $this->assertNull($map->primaryKeyFor('landing_page'));
    }

    public function testCmsTypesAliasToEachOther(): void
    {
        $map = $this->map();

        $this->assertSame(['cms', 'cms_page'], $map->aliasesFor('cms'));
        $this->assertSame(['cms', 'cms_page'], $map->aliasesFor('cms_page'));
    }

    public function testNonCmsTypesHaveNoAliases(): void
    {
        $map = $this->map();

        $this->assertSame(['product'], $map->aliasesFor('product'));
        $this->assertSame(['category'], $map->aliasesFor('category'));
        $this->assertSame(['landing_page'], $map->aliasesFor('landing_page'));
    }

    public function testSweepTypesVisitEachCatalogTableOnce(): void
    {
        $this->assertSame(['product', 'category', 'cms'], $this->map()->sweepTypes());
    }

    public function testExistsConditionChecksEveryMappedType(): void
    {
        $condition = $this->map()->existsCondition('s', 'entity_type', 'entity_id');

        $this->assertStringContainsString("`s`.`entity_type` = 'product'", $condition);
        $this->assertStringContainsString('FROM `catalog_product_entity`', $condition);
        $this->assertStringContainsString('FROM `catalog_category_entity`', $condition);
        $this->assertStringContainsString('FROM `cms_page`', $condition);
        $this->assertStringContainsString('EXISTS (SELECT 1', $condition);
    }

    public function testExistsConditionKeepsRowsOfUnmappedTypes(): void
    {
        $condition = $this->map()->existsCondition('s', 'entity_type', 'entity_id');

        $this->assertStringContainsString(
            "`s`.`entity_type` NOT IN ('product', 'category', 'cms', 'cms_page')",
            $condition
        );
    }

    public function testJoinExistingReportsFailureForAnUnmappedType(): void
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);

        $this->assertFalse($this->map()->joinExisting($select, 'landing_page', 'e', 'entity_id'));
    }

    public function testOrphanSelectsAreNullForAnUnmappedType(): void
    {
        $map = $this->map();

        $this->assertNull($map->orphanIdsSelect('t', 'pk', 'entity_type', 'entity_id', 'landing_page', 10));
        $this->assertNull($map->orphanCountSelect('t', 'entity_type', 'entity_id', 'landing_page'));
    }

    private function chainableSelect(): \Magento\Framework\DB\Select
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        foreach (['from', 'where', 'limit', 'joinLeft', 'joinInner', 'order', 'distinct'] as $method) {
            $select->method($method)->willReturn($select);
        }

        return $select;
    }

    public function testExistingIdsKeepsOnlyRowsFoundInTheCatalog(): void
    {
        $select = $this->chainableSelect();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['7', '9']);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $t): string => $t);

        $map = new EntityTableMap($resource);

        $this->assertSame([7, 9], $map->existingIds('product', [7, 8, 9]));
    }

    public function testExistingIdsDropsNonPositiveIdsWithoutQuerying(): void
    {
        $this->assertSame([], $this->map()->existingIds('product', [0, -4]));
    }

    public function testExistingIdsPassesUnmappedTypesThrough(): void
    {
        $this->assertSame([3, 4], $this->map()->existingIds('landing_page', [3, 4, 3]));
    }
}
