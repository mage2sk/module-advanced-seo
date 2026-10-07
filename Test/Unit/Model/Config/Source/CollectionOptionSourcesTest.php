<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type as EntityType;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection as SetCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as SetCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Model\System\Store as SystemStore;
use Panth\AdvancedSEO\Model\Config\Source\AttributeSetOptions;
use Panth\AdvancedSEO\Model\Config\Source\CategoryTreeOptions;
use Panth\AdvancedSEO\Model\Config\Source\CrossDomainStore;
use PHPUnit\Framework\TestCase;

class CollectionOptionSourcesTest extends TestCase
{
    public function testAttributeSetOptionsFiltersByProductEntityType(): void
    {
        $type = $this->createStub(EntityType::class);
        $type->method('getId')->willReturn('4');

        $eav = $this->createMock(EavConfig::class);
        $eav->expects($this->once())->method('getEntityType')->with('catalog_product')->willReturn($type);

        $collection = $this->createMock(SetCollection::class);
        $collection->expects($this->once())->method('setEntityTypeFilter')->with(4);
        $collection->expects($this->once())->method('setOrder')->with('attribute_set_name', 'ASC');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 4, 'attribute_set_name' => 'Default']),
            new DataObject(['id' => 9, 'attribute_set_name' => 'Gear']),
        ]));

        $factory = $this->createStub(SetCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new AttributeSetOptions($factory, $eav))->toOptionArray();

        $this->assertSame([
            ['value' => 4, 'label' => 'Default'],
            ['value' => 9, 'label' => 'Gear'],
        ], $options);
    }

    public function testCategoryTreeOptionsBuildsBreadcrumbLabels(): void
    {
        $categories = [
            new DataObject(['id' => 1, 'name' => 'Root', 'level' => 0, 'path' => '1']),
            new DataObject(['id' => 2, 'name' => 'Default Category', 'level' => 1, 'path' => '1/2']),
            new DataObject(['id' => 5, 'name' => 'Shoes', 'level' => 2, 'path' => '1/2/5']),
            new DataObject(['id' => 7, 'name' => 'Boots', 'level' => 3, 'path' => '1/2/5/99/7']),
        ];

        $collection = $this->createMock(CategoryCollection::class);
        $collection->expects($this->once())->method('addAttributeToSelect')->with('name');
        $collection->expects($this->once())->method('addFieldToFilter')->with('level', ['gt' => 0]);
        $collection->expects($this->once())->method('setOrder')->with('path', 'ASC');
        $collection->method('getIterator')->willReturnCallback(
            static fn () => new \ArrayIterator($categories)
        );

        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $options = (new CategoryTreeOptions($factory))->toOptionArray();

        $this->assertSame([
            ['value' => 2, 'label' => 'Root > Default Category (ID: 2)'],
            ['value' => 5, 'label' => 'Root > Default Category > Shoes (ID: 5)'],
            ['value' => 7, 'label' => 'Root > Default Category > Shoes > Boots (ID: 7)'],
        ], $options);
    }

    public function testCategoryTreeOptionsEmptyCollection(): void
    {
        $collection = $this->createStub(CategoryCollection::class);
        $collection->method('getIterator')->willReturnCallback(static fn () => new \ArrayIterator([]));
        $factory = $this->createStub(CategoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame([], (new CategoryTreeOptions($factory))->toOptionArray());
    }

    public function testCrossDomainStorePrependsNoneOption(): void
    {
        $systemStore = $this->createMock(SystemStore::class);
        $systemStore->expects($this->once())
            ->method('getStoreValuesForForm')
            ->with(false, false)
            ->willReturn([['value' => '1', 'label' => 'Main']]);

        $options = (new CrossDomainStore($systemStore))->toOptionArray();

        $this->assertCount(2, $options);
        $this->assertSame('0', $options[0]['value']);
        $this->assertSame(['value' => '1', 'label' => 'Main'], $options[1]);
    }
}
