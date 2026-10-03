<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Model\Audit\EntityUrlMapper;
use PHPUnit\Framework\TestCase;

class EntityUrlMapperTest extends TestCase
{
    private function mapper(AdapterInterface $connection): EntityUrlMapper
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit', 'join'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new EntityUrlMapper($resource);
    }

    public function testHomePageHasNoEntity(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('fetchRow');

        $this->assertNull($this->mapper($connection)->entityForUrl('https://example.com/', 1));
    }

    public function testUnknownPathIsCachedAsNull(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchRow')->willReturn(false);
        $mapper = $this->mapper($connection);

        $this->assertNull($mapper->entityForUrl('https://example.com/missing', 1));
        $this->assertNull($mapper->entityForUrl('https://example.com/missing?x=1', 1));
    }

    public function testUnsupportedRewriteTypeIsIgnored(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'custom', 'entity_id' => '2']);

        $this->assertNull($this->mapper($connection)->entityForUrl('https://example.com/custom', 1));
    }

    public function testCmsPageLabelAndStatus(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'cms-page', 'entity_id' => '3']);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('About us', '0');

        $this->assertSame(
            ['type' => 'cms', 'id' => 3, 'label' => 'About us', 'active' => false],
            $this->mapper($connection)->entityForUrl('https://example.com/about-us', 1)
        );
    }

    public function testProductLabelAndStatusComeFromEav(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'product', 'entity_id' => '7']);
        $connection->expects($this->exactly(4))->method('fetchOne')->willReturnOnConsecutiveCalls('73', 'Blue Shirt', '96', '2');

        $this->assertSame(
            ['type' => 'product', 'id' => 7, 'label' => 'Blue Shirt', 'active' => false],
            $this->mapper($connection)->entityForUrl('https://example.com/blue-shirt.html', 1)
        );
    }

    public function testCategoryWithoutEavAttributesIsActive(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'category', 'entity_id' => '5']);
        $connection->method('fetchOne')->willReturn('0');

        $this->assertSame(
            ['type' => 'category', 'id' => 5, 'label' => '', 'active' => true],
            $this->mapper($connection)->entityForUrl('https://example.com/shirts/', 1)
        );
    }

    public function testProductWithoutStatusValueIsActive(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'product', 'entity_id' => '7']);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('73', false, '96', false);

        $entity = $this->mapper($connection)->entityForUrl('https://example.com/p.html', 1);

        $this->assertSame('', $entity['label']);
        $this->assertTrue($entity['active']);
    }

    public function testLookupFailuresDegradeGracefully(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn(['entity_type' => 'cms-page', 'entity_id' => '3']);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('db'));

        $entity = $this->mapper($connection)->entityForUrl('https://example.com/about', 1);

        $this->assertSame('', $entity['label']);
        $this->assertTrue($entity['active']);
    }

    public function testResolvedTitle(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchRow')->willReturnCallback(
            static fn () => ['entity_type' => 'cms-page', 'entity_id' => '3']
        );
        $connection->method('isTableExists')->willReturnOnConsecutiveCalls(false, true, true);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('About', '1', 'Resolved About', '');
        $mapper = $this->mapper($connection);

        $this->assertNull($mapper->resolvedTitle('https://example.com/about', 1));
        $this->assertSame('Resolved About', $mapper->resolvedTitle('https://example.com/about', 1));
        $this->assertNull($mapper->resolvedTitle('https://example.com/about', 1));
    }

    public function testResolvedTitleWithoutEntityIsNull(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('isTableExists');

        $this->assertNull($this->mapper($connection)->resolvedTitle('https://example.com/', 1));
    }

    public function testSourcesLinkingToCollectsMenusCrosslinksAndConfig(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('describeTable')->willReturn(['cms_page_id' => []]);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['item_id' => '12', 'title' => 'Old service']],
            [['crosslink_id' => '4', 'keyword' => 'service']],
            [['path' => 'design/footer/links', 'scope' => 'stores', 'scope_id' => '1']]
        );

        $sources = $this->mapper($connection)->sourcesLinkingTo(
            'https://example.com/service-old/',
            ['type' => 'cms', 'id' => 7],
            1
        );

        $this->assertSame([
            'panth_megamenu_item #12 (Old service)',
            'panth_seo_crosslink #4 (service)',
            'core_config_data design/footer/links (stores 1)',
        ], $sources);
    }

    public function testSourcesSkipMissingTablesAndRootPath(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->method('fetchAll')->willReturn([]);
        $mapper = $this->mapper($connection);

        $this->assertSame([], $mapper->sourcesLinkingTo('https://example.com/', null, 1));
        $this->assertSame([], $mapper->sourcesLinkingTo('https://example.com/x', null, 1));
    }
}
