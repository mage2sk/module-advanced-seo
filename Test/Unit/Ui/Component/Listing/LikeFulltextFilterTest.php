<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class LikeFulltextFilterTest extends TestCase
{
    private array $wheres = [];

    private function collection(): AbstractDb
    {
        $this->wheres = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($v) => '`' . $v . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use ($select) {
            $this->wheres[] = $cond;
            return $select;
        });
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private static function filter(mixed $value): Filter
    {
        $filter = new Filter();
        $filter->setValue($value);
        return $filter;
    }

    public function testEveryColumnIsSearchedWithEscapedWildcards(): void
    {
        $filter = new LikeFulltextFilter(['meta_title', 7, 'url']);

        $filter->apply($this->collection(), self::filter(' 50%_off '));

        $this->assertSame(["`meta_title` LIKE '%50\\%\\_off%' OR `url` LIKE '%50\\%\\_off%'"], $this->wheres);
    }

    public function testLongValuesAreCapped(): void
    {
        (new LikeFulltextFilter(['a']))->apply($this->collection(), self::filter(str_repeat('x', 250)));

        $this->assertSame("`a` LIKE '%" . str_repeat('x', 200) . "%'", $this->wheres[0]);
    }

    public function testEmptyValuesNoColumnsOrPlainCollectionsAreIgnored(): void
    {
        (new LikeFulltextFilter(['a']))->apply($this->collection(), self::filter('  '));
        $this->assertSame([], $this->wheres);

        (new LikeFulltextFilter(['a']))->apply($this->collection(), self::filter(['array']));
        $this->assertSame([], $this->wheres);

        (new LikeFulltextFilter([]))->apply($this->collection(), self::filter('x'));
        $this->assertSame([], $this->wheres);

        (new LikeFulltextFilter(['a']))->apply($this->createStub(Collection::class), self::filter('x'));
        $this->assertSame([], $this->wheres);
    }
}
