<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\CustomCanonical;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Model\CustomCanonical\Repository;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function select(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        return $select;
    }

    public function testGetByIdReturnsNullWhenTableMissing(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(false);
        $conn->expects($this->never())->method('fetchRow');

        $this->assertNull((new Repository($this->resource($conn)))->getById(1));
    }

    public function testGetByIdReturnsRowOrNull(): void
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('isTableExists')->willReturn(true);
        $conn->method('select')->willReturn($this->select());
        $conn->method('fetchRow')->willReturnOnConsecutiveCalls(['canonical_id' => 4], false);

        $repo = new Repository($this->resource($conn));
        $this->assertSame(['canonical_id' => 4], $repo->getById(4));
        $this->assertNull($repo->getById(5));
    }

    public function testSaveUpdatesExistingRow(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')
            ->with('panth_seo_custom_canonical', ['target_url' => 'https://x'], ['canonical_id = ?' => 9]);
        $conn->expects($this->never())->method('insert');

        $this->assertSame(9, (new Repository($this->resource($conn)))->save(['canonical_id' => '9', 'target_url' => 'https://x']));
    }

    public function testSaveInsertsNewRowFromObject(): void
    {
        $conn = $this->createMock(Mysql::class);
        $conn->expects($this->once())->method('insert')
            ->with('panth_seo_custom_canonical', ['target_url' => 'https://y']);
        $conn->expects($this->once())->method('lastInsertId')->willReturn('12');

        $entity = new DataObject(['target_url' => 'https://y']);
        $this->assertSame(12, (new Repository($this->resource($conn)))->save($entity));
    }

    public function testSaveWithUnsupportedEntityInsertsEmptyRow(): void
    {
        $conn = $this->createMock(Mysql::class);
        $conn->expects($this->once())->method('insert')->with('panth_seo_custom_canonical', []);
        $conn->method('lastInsertId')->willReturn('1');

        $this->assertSame(1, (new Repository($this->resource($conn)))->save('invalid'));
    }

    public function testDeleteById(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('isTableExists')->willReturnOnConsecutiveCalls(false, true, true);
        $conn->expects($this->exactly(2))->method('delete')
            ->with('panth_seo_custom_canonical', ['canonical_id = ?' => 3])
            ->willReturnOnConsecutiveCalls(1, 0);

        $repo = new Repository($this->resource($conn));
        $this->assertFalse($repo->deleteById(3));
        $this->assertTrue($repo->deleteById(3));
        $this->assertFalse($repo->deleteById(3));
    }
}
