<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Form\DataProvider;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical\Collection;
use Panth\AdvancedSEO\Ui\Component\Form\DataProvider\GenericFormDataProvider;
use PHPUnit\Framework\TestCase;

class GenericFormDataProviderPersistTest extends TestCase
{
    private function provider(array $items, ?DataPersistorInterface $persistor, string $key = 'panth_seo_custom_canonical'): GenericFormDataProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);

        return new GenericFormDataProvider('ds', 'canonical_id', 'id', $collection, [], [], $persistor, $key);
    }

    private function persistor(?array $value, array &$cleared): DataPersistorInterface
    {
        $persistor = $this->createStub(DataPersistorInterface::class);
        $persistor->method('get')->willReturn($value);
        $persistor->method('clear')->willReturnCallback(function ($key) use (&$cleared): void {
            $cleared[] = $key;
        });

        return $persistor;
    }

    public function testRejectedNewRecordInputIsShownAgainOnce(): void
    {
        $cleared = [];
        $data = $this->provider([], $this->persistor(['source_entity_type' => 'product', 'target_url' => 'javascript:x'], $cleared))->getData();

        $this->assertSame(['' => ['source_entity_type' => 'product', 'target_url' => 'javascript:x']], $data);
        $this->assertSame(['panth_seo_custom_canonical'], $cleared);
    }

    public function testRejectedEditMergesOverTheStoredRow(): void
    {
        $cleared = [];
        $item = new DataObject(['id' => 3, 'canonical_id' => 3, 'target_url' => 'https://a.test/', 'store_id' => 1]);
        $data = $this->provider([$item], $this->persistor(['canonical_id' => '3', 'target_url' => 'bad'], $cleared))->getData();

        $this->assertSame('bad', $data[3]['target_url']);
        $this->assertSame(1, $data[3]['store_id']);
    }

    public function testNothingPersistedOrNoKeyKeepsTheStoredData(): void
    {
        $cleared = [];
        $this->assertSame(['' => []], $this->provider([], $this->persistor(null, $cleared))->getData());
        $this->assertSame(['' => []], $this->provider([], $this->persistor(['x' => 1], $cleared), '')->getData());
        $this->assertSame(['' => []], $this->provider([], null)->getData());
        $this->assertSame([], $cleared);
    }
}
