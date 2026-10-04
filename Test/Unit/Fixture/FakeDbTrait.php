<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;

/**
 * In-memory stand-in for a DB adapter: selects record their parts, writes are captured in $this->writes.
 */
trait FakeDbTrait
{
    protected array $selectParts = [];
    protected array $writes = [];

    protected function newSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $id = spl_object_id($select);
        $this->selectParts[$id] = [];
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(
                function (...$args) use ($method, $select, $id) {
                    $this->selectParts[$id][] = [$method, $args];
                    return $select;
                }
            );
        }
        return $select;
    }

    /**
     * Returns the where conditions of a select as [condition => value].
     */
    protected function whereOf(Select $select): array
    {
        $out = [];
        foreach ($this->selectParts[spl_object_id($select)] ?? [] as [$method, $args]) {
            if ($method === 'where') {
                $out[$args[0]] = $args[1] ?? null;
            }
        }
        return $out;
    }

    protected function partsOf(Select $select, string $method): array
    {
        $out = [];
        foreach ($this->selectParts[spl_object_id($select)] ?? [] as [$name, $args]) {
            if ($name === $method) {
                $out[] = $args;
            }
        }
        return $out;
    }

    /**
     * @param array<string, callable> $readers fetchAll/fetchRow/fetchOne handlers receiving the select
     */
    protected function resource(array $readers = [], int $lastInsertId = 0, int $deleteCount = 1): ResourceConnection
    {
        $conn = $this->createStub(Mysql::class);
        $conn->method('select')->willReturnCallback(fn() => $this->newSelect());
        foreach (['fetchAll' => [], 'fetchRow' => false, 'fetchOne' => false] as $method => $default) {
            $handler = $readers[$method] ?? null;
            $conn->method($method)->willReturnCallback(
                static fn($select) => $handler ? $handler($select) : $default
            );
        }
        $conn->method('insert')->willReturnCallback(function ($table, array $bind) {
            $this->writes[] = ['insert', $table, $bind];
            return 1;
        });
        $conn->method('update')->willReturnCallback(function ($table, array $bind, $where = '') {
            $this->writes[] = ['update', $table, $bind, $where];
            return 1;
        });
        $conn->method('delete')->willReturnCallback(function ($table, $where = '') use ($deleteCount) {
            $this->writes[] = ['delete', $table, $where];
            return $deleteCount;
        });
        $conn->method('insertOnDuplicate')->willReturnCallback(function ($table, array $data, array $fields = []) {
            $this->writes[] = ['insertOnDuplicate', $table, $data, $fields];
            return 1;
        });
        $conn->method('lastInsertId')->willReturn((string)$lastInsertId);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnCallback(static fn($name) => 'pfx_' . $name);
        return $resource;
    }
}
