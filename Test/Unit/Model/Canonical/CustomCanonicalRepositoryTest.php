<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Canonical;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomCanonicalRepositoryTest extends TestCase
{
    private array $wheres = [];

    private function connection(mixed $row): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $val = null) use ($select) {
            $this->wheres[$cond] = $val;
            return $select;
        });

        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('fetchRow')->willReturn($row);
        return $conn;
    }

    private function repo(
        AdapterInterface $connection,
        ?ProductRepositoryInterface $products = null,
        ?CategoryRepositoryInterface $categories = null,
        ?PageRepositoryInterface $pages = null,
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null
    ): CustomCanonicalRepository {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CustomCanonicalRepository(
            $resource,
            $products ?? $this->createStub(ProductRepositoryInterface::class),
            $categories ?? $this->createStub(CategoryRepositoryInterface::class),
            $pages ?? $this->createStub(PageRepositoryInterface::class),
            $storeManager ?? $this->createStub(StoreManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testNoRowReturnsNull(): void
    {
        $this->assertNull($this->repo($this->connection(false))->find('product', 1, 2));
        $this->assertSame(['product'], $this->wheres['source_entity_type IN (?)']);
        $this->assertSame([0, 2], $this->wheres['store_id IN (?)']);
        $this->assertSame(1, $this->wheres['source_entity_id = ?']);
    }

    public function testCmsAliasesBothSourceTypes(): void
    {
        $this->repo($this->connection(false))->find('cms_page', 1, 1);
        $this->assertSame(['cms', 'cms_page'], $this->wheres['source_entity_type IN (?)']);
    }

    public function testTargetUrlReturnedDirectly(): void
    {
        $this->assertSame(
            'https://x.test/a',
            $this->repo($this->connection(['target_url' => 'https://x.test/a']))->find('category', 1, 1)
        );
    }

    public function testRowWithoutTargetReturnsNull(): void
    {
        $this->assertNull($this->repo($this->connection(['target_url' => '', 'target_entity_type' => 'product']))
            ->find('category', 1, 1));
        $this->assertNull($this->repo($this->connection(['target_entity_type' => 'unknown', 'target_entity_id' => 3]))
            ->find('category', 1, 1));
    }

    public function testProductTargetResolved(): void
    {
        $product = $this->createStub(Product::class);
        $urlModel = $this->createStub(ProductUrl::class);
        $urlModel->method('getUrl')->willReturn('https://shop.test/p.html');
        $product->method('getUrlModel')->willReturn($urlModel);

        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->expects($this->once())->method('getById')->with(7, false, 3)->willReturn($product);

        $row = ['target_entity_type' => 'product', 'target_entity_id' => '7'];
        $this->assertSame('https://shop.test/p.html', $this->repo($this->connection($row), $products)->find('product', 1, 3));
    }

    public function testMissingProductTargetReturnsNull(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willThrowException(new NoSuchEntityException());

        $row = ['target_entity_type' => 'product', 'target_entity_id' => 7];
        $this->assertNull($this->repo($this->connection($row), $products)->find('product', 1, 3));
    }

    public function testCategoryTargetResolvedAndMissingHandled(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('getUrl')->willReturn('https://shop.test/c.html');

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $calls = 0;
        $categories->method('get')->willReturnCallback(static function () use (&$calls, $category) {
            if ($calls++ === 0) {
                return $category;
            }
            throw new NoSuchEntityException();
        });

        $repo = $this->repo(
            $this->connection(['target_entity_type' => 'category', 'target_entity_id' => 4]),
            null,
            $categories
        );
        $this->assertSame('https://shop.test/c.html', $repo->find('product', 1, 1));
        $this->assertNull($repo->find('product', 1, 1));
    }

    public function testCmsTargetResolved(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData('identifier', '/about-us');
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturn($page);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $repo = $this->repo(
            $this->connection(['target_entity_type' => 'cms_page', 'target_entity_id' => 2]),
            null,
            null,
            $pages,
            $storeManager
        );
        $this->assertSame('https://shop.test/about-us', $repo->find('cms', 1, 1));
    }

    public function testMissingCmsTargetReturnsNull(): void
    {
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willThrowException(new NoSuchEntityException());

        $repo = $this->repo(
            $this->connection(['target_entity_type' => 'cms', 'target_entity_id' => 2]),
            null,
            null,
            $pages
        );
        $this->assertNull($repo->find('cms', 1, 1));
    }

    public function testEntityResolutionErrorIsLogged(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO custom canonical entity resolution failed', [
                'target_entity_type' => 'category',
                'target_entity_id' => 4,
                'error' => 'boom',
            ]);

        $repo = $this->repo(
            $this->connection(['target_entity_type' => 'category', 'target_entity_id' => 4]),
            null,
            $categories,
            null,
            null,
            $logger
        );
        $this->assertNull($repo->find('product', 1, 1));
    }

    public function testLookupErrorIsLogged(): void
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willThrowException(new \RuntimeException('db'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO custom canonical lookup failed', [
                'entity_type' => 'product',
                'entity_id' => 5,
                'error' => 'db',
            ]);

        $this->assertNull($this->repo($conn, null, null, null, null, $logger)->find('product', 5, 1));
    }

    public function testSaveInsertAndUpdate(): void
    {
        $conn = $this->createMock(Mysql::class);
        $conn->expects($this->once())->method('insert')
            ->with('panth_seo_custom_canonical', ['target_url' => 'a']);
        $conn->expects($this->once())->method('lastInsertId')->willReturn('15');
        $conn->expects($this->once())->method('update')
            ->with('panth_seo_custom_canonical', ['target_url' => 'b'], ['canonical_id = ?' => 15]);

        $repo = $this->repo($conn);
        $this->assertSame(15, $repo->save(['target_url' => 'a']));
        $this->assertSame(15, $repo->save(['canonical_id' => 15, 'target_url' => 'b']));
    }

    public function testDeleteById(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('delete')
            ->with('panth_seo_custom_canonical', ['canonical_id = ?' => 8]);

        $this->repo($conn)->deleteById(8);
    }
}
