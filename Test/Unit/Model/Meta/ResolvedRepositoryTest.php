<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Meta\ResolvedMetaFactory;
use Panth\AdvancedSEO\Model\Meta\ResolvedRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResolvedRepositoryTest extends TestCase
{
    private function repository(AdapterInterface&MockObject $connection): ResolvedRepository
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $factory = $this->createStub(ResolvedMetaFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): ResolvedMeta => new ResolvedMeta());

        return new ResolvedRepository($resource, $factory, new Json());
    }

    public function testFindReturnsNullWhenNoRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchRow')->willReturn(false);

        $this->assertNull($this->repository($connection)->find('product', 1, 1));
    }

    public function testFindHydratesRowAndDecodesPayloads(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchRow')->willReturn([
            'resolved_id'      => '12',
            'store_id'         => '1',
            'entity_type'      => 'product',
            'entity_id'        => '9',
            'meta_title'       => 'Title',
            'meta_description' => null,
            'og_payload'       => '{"og:title":"Title"}',
            'jsonld_payload'   => '{broken',
            'hreflang_payload' => '"scalar"',
        ]);

        $meta = $this->repository($connection)->find('product', 9, 1);

        $this->assertSame(12, $meta->getResolvedId());
        $this->assertSame(9, $meta->getEntityId());
        $this->assertSame('Title', $meta->getMetaTitle());
        $this->assertNull($meta->getMetaDescription());
        $this->assertSame(['og:title' => 'Title'], $meta->getOgPayload());
        $this->assertSame([], $meta->getJsonldPayload());
        $this->assertSame([], $meta->getHreflangPayload());
        $this->assertSame('template', $meta->getSource());
    }

    public function testFindManySkipsQueryForNoIdsAndKeysByEntityId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchAll')->willReturn([
            ['entity_id' => '4', 'entity_type' => 'cms', 'source' => 'override'],
            ['entity_id' => '6', 'entity_type' => 'cms'],
        ]);
        $repository = $this->repository($connection);

        $this->assertSame([], $repository->findMany('cms', [], 1));
        $result = $repository->findMany('cms', [4, 6], 1);
        $this->assertSame([4, 6], array_keys($result));
        $this->assertSame('override', $result[4]->getSource());
        $this->assertNull($result[6]->getResolvedId());
    }

    public function testSaveUpsertsEncodedRow(): void
    {
        $meta = new ResolvedMeta();
        $meta->setStoreId(2)->setEntityType('category')->setEntityId(3)->setMetaTitle('Cat')
            ->setOgPayload(['og:type' => 'website'])->setSource('');

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'panth_seo_resolved',
            $this->callback(function (array $data): bool {
                $this->assertSame(2, $data['store_id']);
                $this->assertSame('Cat', $data['meta_title']);
                $this->assertSame('{"og:type":"website"}', $data['og_payload']);
                $this->assertNull($data['jsonld_payload']);
                $this->assertNull($data['hreflang_payload']);
                $this->assertSame('template', $data['source']);

                return true;
            }),
            $this->callback(static fn (array $cols): bool => in_array('meta_title', $cols, true) && count($cols) === 12)
        );

        $this->repository($connection)->save($meta);
    }

    public function testDeleteEntityOptionallyScopesToStore(): void
    {
        $calls = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->exactly(2))->method('delete')->willReturnCallback(
            static function (string $table, array $where) use (&$calls): int {
                $calls[] = [$table, $where];

                return 1;
            }
        );
        $repository = $this->repository($connection);

        $repository->deleteEntity('product', 5);
        $repository->deleteEntity('product', 5, 2);

        $this->assertSame(['entity_type = ?' => 'product', 'entity_id = ?' => 5], $calls[0][1]);
        $this->assertSame(
            ['entity_type = ?' => 'product', 'entity_id = ?' => 5, 'store_id = ?' => 2],
            $calls[1][1]
        );
        $this->assertSame('panth_seo_resolved', $calls[0][0]);
    }
}
