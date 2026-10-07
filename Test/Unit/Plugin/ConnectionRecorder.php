<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;

trait ConnectionRecorder
{
    private array $db = [];

    private array $dbFetchCol = [];

    private string $dbLastInsertId = '0';

    private function recordingResource(
        array $fetchOne = [],
        array $fetchAll = [],
        ?\Throwable $fails = null,
        array $fetchRow = [],
        bool $tablesExist = true
    ): ResourceConnection {
        $this->db = ['where' => [], 'insert' => [], 'update' => [], 'delete' => [], 'insertOnDuplicate' => []];

        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'limit', 'join', 'joinLeft', 'group', 'columns', 'distinct'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->db['where'][] = [$cond, $value];
            return $select;
        });

        $oneCalls = 0;
        $allCalls = 0;
        $rowCalls = 0;
        $connection = $this->createStub(Mysql::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$oneCalls, $fetchOne, $fails) {
            if ($fails !== null) {
                throw $fails;
            }
            return array_key_exists($oneCalls, $fetchOne) ? $fetchOne[$oneCalls++] : false;
        });
        $connection->method('fetchAll')->willReturnCallback(static function () use (&$allCalls, $fetchAll, $fails) {
            if ($fails !== null) {
                throw $fails;
            }
            return $fetchAll[$allCalls++] ?? [];
        });
        $connection->method('fetchRow')->willReturnCallback(static function () use (&$rowCalls, $fetchRow, $fails) {
            if ($fails !== null) {
                throw $fails;
            }
            return $fetchRow[$rowCalls++] ?? false;
        });
        $connection->method('fetchCol')->willReturnCallback(fn() => $this->dbFetchCol);
        $connection->method('lastInsertId')->willReturnCallback(fn() => $this->dbLastInsertId);
        $connection->method('isTableExists')->willReturn($tablesExist);
        foreach (['insert', 'update', 'delete', 'insertOnDuplicate'] as $method) {
            $connection->method($method)->willReturnCallback(function (...$args) use ($method): int {
                $this->db[$method][] = $args;
                return 1;
            });
        }

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }
}
