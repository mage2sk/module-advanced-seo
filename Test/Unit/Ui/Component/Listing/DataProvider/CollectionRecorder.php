<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider;

use Magento\Backend\Model\Session as BackendSession;

trait CollectionRecorder
{
    private array $calls = [];

    private array $sessionWrites = [];

    private function recordingCollection(string $class, array $items = [], int $size = 0): object
    {
        $collection = $this->createStub($class);
        $methods = [
            'setStore', 'addStoreFilter', 'addAttributeToSelect', 'addFieldToSelect', 'addFieldToFilter',
            'addAttributeToFilter', 'setStoreId', 'setOrder', 'addAttributeToSort', 'addOrder', 'clear',
            'setPageSize', 'setCurPage', 'load',
        ];
        $reflection = new \ReflectionClass($class);
        foreach ($methods as $method) {
            if (!$reflection->hasMethod($method)) {
                continue;
            }
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method, $class) {
                $this->calls[] = [$class, $method, $args];
                return $collection;
            });
        }
        $collection->method('isLoaded')->willReturn(false);
        $collection->method('getIterator')->willReturnCallback(static fn() => new \ArrayIterator($items));
        $collection->method('getItems')->willReturn($items);
        $collection->method('getSize')->willReturn($size);

        return $collection;
    }

    private function callsTo(string $method): array
    {
        $explicitArgs = ['addStoreFilter' => 1, 'addAttributeToFilter' => 2];

        return array_values(array_map(
            static fn(array $call): array => array_slice($call[2], 0, $explicitArgs[$method] ?? null),
            array_filter($this->calls, static fn(array $call): bool => $call[1] === $method)
        ));
    }

    private function recordingSession(array $values): BackendSession
    {
        $this->sessionWrites = [];
        $writes = &$this->sessionWrites;

        return new class ($values, $writes) extends BackendSession {
            private array $values;

            private array $writes;

            public function __construct(array $values, array &$writes)
            {
                $this->values = $values;
                $this->writes = &$writes;
            }

            public function getData($key = '', $clear = false)
            {
                return $this->values[$key] ?? null;
            }

            public function setData($key, $value = null)
            {
                $this->writes[$key] = $value;
                return $this;
            }
        };
    }
}
