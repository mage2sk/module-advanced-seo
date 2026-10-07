<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Canonical;

use Magento\Bundle\Model\ResourceModel\Selection as BundleSelection;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use Panth\AdvancedSEO\Model\Canonical\AssociatedProductResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AssociatedProductResolverTest extends TestCase
{
    private function connection(mixed $fetchOne): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchOne')->willReturn($fetchOne);
        return $conn;
    }

    private function repository(?int $expectedId, string $url = 'https://shop.test/parent.html'): ProductRepositoryInterface
    {
        $repo = $this->createMock(ProductRepositoryInterface::class);
        if ($expectedId === null) {
            $repo->expects($this->never())->method('getById');
            return $repo;
        }

        $urlModel = $this->createMock(ProductUrl::class);
        $product = $this->createStub(Product::class);
        $product->method('getUrlModel')->willReturn($urlModel);
        $urlModel->expects($this->once())->method('getUrl')
            ->with($product, ['_ignore_category' => true, '_scope' => 2])
            ->willReturn($url);

        $repo->expects($this->once())->method('getById')->with($expectedId, false, 2)->willReturn($product);
        return $repo;
    }

    private function resolver(
        array $configurableParents,
        mixed $groupedParent,
        mixed $bundleParent,
        ProductRepositoryInterface $repository,
        bool $enabled = true,
        ?LoggerInterface $logger = null
    ): AssociatedProductResolver {
        $configurable = $this->createStub(ConfigurableResource::class);
        $configurable->method('getParentIdsByChild')->willReturn($configurableParents);

        $grouped = $this->createStub(GroupedLink::class);
        $grouped->method('getConnection')->willReturn($this->connection($groupedParent));
        $grouped->method('getMainTable')->willReturn('catalog_product_link');

        $bundle = $this->createStub(BundleSelection::class);
        $bundle->method('getConnection')->willReturn($this->connection($bundleParent));
        $bundle->method('getMainTable')->willReturn('catalog_product_bundle_selection');

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn($enabled);

        return new AssociatedProductResolver(
            $configurable,
            $grouped,
            $bundle,
            $repository,
            $config,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testDisabledReturnsNull(): void
    {
        $this->assertNull($this->resolver([5], false, false, $this->repository(null), false)->resolve(1, 2));
    }

    public function testConfigurableParentWins(): void
    {
        $this->assertSame(
            'https://shop.test/parent.html',
            $this->resolver(['8', '9'], '4', '5', $this->repository(8))->resolve(1, 2)
        );
    }

    public function testGroupedParentUsedWhenNoConfigurable(): void
    {
        $this->assertSame(
            'https://shop.test/g.html',
            $this->resolver([], '4', '5', $this->repository(4, 'https://shop.test/g.html'))->resolve(1, 2)
        );
    }

    public function testBundleParentUsedLast(): void
    {
        $this->assertSame(
            'https://shop.test/b.html',
            $this->resolver([], false, '6', $this->repository(6, 'https://shop.test/b.html'))->resolve(1, 2)
        );
    }

    public function testNoParentReturnsNull(): void
    {
        $this->assertNull($this->resolver([], false, false, $this->repository(null))->resolve(1, 2));
    }

    public function testMissingParentProductReturnsNull(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('getById')->willThrowException(new NoSuchEntityException());

        $this->assertNull($this->resolver([3], false, false, $repo)->resolve(1, 2));
    }

    public function testUnexpectedErrorIsLogged(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('getById')->willThrowException(new \RuntimeException('db'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO associated product canonical failed', ['product_id' => 1, 'error' => 'db']);

        $this->assertNull($this->resolver([3], false, false, $repo, true, $logger)->resolve(1, 2));
    }
}
