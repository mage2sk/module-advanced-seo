<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\System\Store as SystemStore;
use Panth\AdvancedSEO\Ui\Component\Listing\Column\StoreView;
use PHPUnit\Framework\TestCase;

class StoreViewTest extends TestCase
{
    private function column(array &$requested): StoreView
    {
        $systemStore = $this->createStub(SystemStore::class);
        $systemStore->method('getStoresStructure')->willReturnCallback(
            static function ($isAll, $storeIds) use (&$requested): array {
                $requested[] = $storeIds;
                return [1 => ['label' => 'Main Website', 'children' => [1 => ['label' => 'Main Store', 'children' => [
                    2 => ['label' => 'Luma Store View'],
                ]]]]];
            }
        );
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);

        $column = new StoreView(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $systemStore,
            $escaper,
            [],
            ['name' => 'store_id']
        );

        return $column;
    }

    public function testScalarStoreIdsShowAllStoreViewsOrTheStoreName(): void
    {
        $requested = [];
        $data = $this->column($requested)->prepareDataSource(['data' => ['items' => [
            ['store_id' => '0'],
            ['store_id' => '2'],
            ['store_id' => [0]],
        ]]]);

        $items = $data['data']['items'];
        $this->assertSame('All Store Views', (string) $items[0]['store_id']);
        $this->assertStringContainsString('Luma Store View', (string) $items[1]['store_id']);
        $this->assertSame('All Store Views', (string) $items[2]['store_id']);
        $this->assertSame([['2']], $requested);
    }
}
