<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\ResolvedMeta;
use Panth\AdvancedSEO\Model\Score\ContextBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextBuilderTest extends TestCase
{
    private ?ProductRepositoryInterface $products = null;

    private ?CategoryRepositoryInterface $categories = null;

    private ?PageRepositoryInterface $pages = null;

    private ?AdapterInterface $connection = null;

    private ?LoggerInterface $logger = null;

    private ?MetaResolverInterface $resolver = null;

    private ?Config $config = null;

    private function builder(): ContextBuilder
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection ?? $this->createStub(AdapterInterface::class));
        $resource->method('getTableName')->willReturnArgument(0);

        if ($this->resolver === null) {
            $this->resolver = $this->createStub(MetaResolverInterface::class);
            $this->resolver->method('resolve')->willReturn(new ResolvedMeta());
        }

        return new ContextBuilder(
            $this->products ?? $this->createStub(ProductRepositoryInterface::class),
            $this->categories ?? $this->createStub(CategoryRepositoryInterface::class),
            $this->pages ?? $this->createStub(PageRepositoryInterface::class),
            $resource,
            $this->logger ?? $this->createStub(LoggerInterface::class),
            $this->resolver,
            $this->config ?? $this->createStub(Config::class)
        );
    }

    private function entity(string $class, array $values, array $data = []): Stub
    {
        $stub  = $this->createStub($class);
        $magic = [];
        foreach ($values as $method => $value) {
            if (method_exists($class, $method)) {
                $stub->method($method)->willReturn($value);
            } else {
                $magic[$method] = $value;
            }
        }
        $stub->method('__call')->willReturnCallback(static fn (string $method) => $magic[$method] ?? null);
        $stub->method('getData')->willReturnCallback(static fn (string $key = '') => $data[$key] ?? null);

        return $stub;
    }

    private function product(array $data = [], mixed $attributeText = null): Product
    {
        $product = $this->entity(Product::class, [
            'getMetaTitle'       => 'P title',
            'getMetaDescription' => 'P description',
            'getMetaKeyword'     => 'p, keywords',
            'getDescription'     => '<p>Body</p>',
            'getName'            => 'Blue Shirt',
            'getSku'             => 'SKU-1',
            'getPrice'           => '19.90',
        ], $data + ['image' => '/b/s.jpg']);
        if ($attributeText instanceof \Throwable) {
            $product->method('getAttributeText')->willThrowException($attributeText);
        } else {
            $product->method('getAttributeText')->willReturn($attributeText);
        }

        return $product;
    }

    private function useProduct(Product $product): void
    {
        $this->products = $this->createStub(ProductRepositoryInterface::class);
        $this->products->method('getById')->willReturn($product);
    }

    private function brandConfig(string $attribute): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getBrandAttribute')->willReturn($attribute);
    }

    public function testProductContextIsBuilt(): void
    {
        $this->useProduct($this->product(['brand' => ' Acme ']));

        $ctx = $this->builder()->build('product', 5, 1);

        $this->assertSame(['title' => 'P title', 'description' => 'P description', 'keywords' => 'p, keywords'], $ctx['meta']);
        $this->assertSame('<p>Body</p>', $ctx['content']);
        $this->assertSame(
            ['name' => 'Blue Shirt', 'sku' => 'SKU-1', 'brand' => 'Acme', 'image' => '/b/s.jpg', 'price' => 19.9],
            $ctx['attributes']
        );
        $this->assertInstanceOf(Product::class, $ctx['entity']);
        $this->assertSame(5, $ctx['entity_id']);
    }

    public function testBrandFallsBackToAttributeTextThenRawValue(): void
    {
        $this->brandConfig('manufacturer');
        $this->useProduct($this->product([], ' Maker '));
        $this->assertSame('Maker', $this->builder()->build('product', 5, 1)['attributes']['brand']);

        $this->useProduct($this->product(['manufacturer' => '42'], new \RuntimeException('no source')));
        $this->assertSame('42', $this->builder()->build('product', 5, 1)['attributes']['brand']);

        $this->brandConfig('');
        $this->useProduct($this->product(['manufacturer' => '42'], 'X'));
        $this->assertSame('', $this->builder()->build('product', 5, 1)['attributes']['brand']);
    }

    public function testBrandConfigFailureDefaultsToManufacturer(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getBrandAttribute')->willThrowException(new \RuntimeException('cfg'));
        $this->useProduct($this->product(['manufacturer' => 'Raw Maker'], ''));

        $this->assertSame('Raw Maker', $this->builder()->build('product', 5, 1)['attributes']['brand']);
    }

    public function testResolvedMetaOverridesNativeValues(): void
    {
        $this->useProduct($this->product());
        $resolved = new ResolvedMeta();
        $resolved->setMetaTitle(' Resolved title ')->setMetaDescription('')->setMetaKeywords('resolved');
        $this->resolver = $this->createMock(MetaResolverInterface::class);
        $this->resolver->expects($this->once())->method('resolve')->with('product', 5, 1)->willReturn($resolved);

        $ctx = $this->builder()->build('product', 5, 1);

        $this->assertSame('Resolved title', $ctx['meta']['title']);
        $this->assertSame('P description', $ctx['meta']['description']);
        $this->assertSame('resolved', $ctx['meta']['keywords']);
    }

    public function testResolverFailureIsLoggedAndNativeMetaKept(): void
    {
        $this->useProduct($this->product());
        $this->resolver = $this->createStub(MetaResolverInterface::class);
        $this->resolver->method('resolve')->willThrowException(new \RuntimeException('resolver down'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with(
            'Panth SEO score: resolved meta unavailable',
            ['entity_type' => 'product', 'entity_id' => 5, 'error' => 'resolver down']
        );

        $this->assertSame('P title', $this->builder()->build('product', 5, 1)['meta']['title']);
    }

    public function testCategoryContext(): void
    {
        $category = $this->entity(Category::class, [
            'getMetaTitle'       => 'C title',
            'getMetaDescription' => 'C description',
            'getMetaKeywords'    => 'c',
            'getDescription'     => 'Category body',
            'getName'            => 'Shirts',
            'getImageUrl'        => 'https://example.com/c.jpg',
        ]);
        $this->categories = $this->createStub(CategoryRepositoryInterface::class);
        $this->categories->method('get')->willReturn($category);
        $this->resolver = $this->createMock(MetaResolverInterface::class);
        $this->resolver->expects($this->once())->method('resolve')->with('category', 3, 2)->willReturn(new ResolvedMeta());

        $ctx = $this->builder()->build('category', 3, 2);

        $this->assertSame('C title', $ctx['meta']['title']);
        $this->assertSame('Category body', $ctx['content']);
        $this->assertSame(['name' => 'Shirts', 'image' => 'https://example.com/c.jpg'], $ctx['attributes']);
    }

    public function testCmsPageContext(): void
    {
        $page = $this->createStub(Page::class);
        $page->method('getMetaTitle')->willReturn('Page title');
        $page->method('getMetaDescription')->willReturn('Page description');
        $page->method('getMetaKeywords')->willReturn('page');
        $page->method('getContent')->willReturn('<p>Page body</p>');
        $page->method('getTitle')->willReturn('About');
        $this->pages = $this->createStub(PageRepositoryInterface::class);
        $this->pages->method('getById')->willReturn($page);
        $this->resolver = $this->createMock(MetaResolverInterface::class);
        $this->resolver->expects($this->once())->method('resolve')->with('cms', 9, 1)->willReturn(new ResolvedMeta());

        $ctx = $this->builder()->build('cms_page', 9, 1);

        $this->assertSame('Page title', $ctx['meta']['title']);
        $this->assertSame('<p>Page body</p>', $ctx['content']);
        $this->assertSame(['name' => 'About'], $ctx['attributes']);
    }

    public function testEntityLoadFailureIsLoggedAndDefaultsReturned(): void
    {
        $this->products = $this->createStub(ProductRepositoryInterface::class);
        $this->products->method('getById')->willThrowException(new NoSuchEntityException(new Phrase('gone')));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with('Panth SEO context build failed: gone');

        $ctx = $this->builder()->build('product', 5, 1);

        $this->assertSame('', $ctx['content']);
        $this->assertSame([], $ctx['attributes']);
        $this->assertArrayNotHasKey('entity', $ctx);
    }

    public function testUnknownTypesSkipLookupsAndResolver(): void
    {
        $this->resolver = $this->createMock(MetaResolverInterface::class);
        $this->resolver->expects($this->never())->method('resolve');

        $ctx = $this->builder()->build('unknown', 1, 1);

        $this->assertSame(['title' => '', 'description' => '', 'keywords' => ''], $ctx['meta']);
    }

    private function thirdPartyConnection(bool $tableExists, mixed $row): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($row);

        return $connection;
    }

    public function testThirdPartyTableMissingIsLogged(): void
    {
        $this->connection = $this->thirdPartyConnection(false, false);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('info')
            ->with('Panth SEO: table panth_faq_item not found, skipping context for faq');

        $this->assertSame('', $this->builder()->build('faq', 1, 1)['content']);
    }

    public function testThirdPartyMissingRowLeavesDefaults(): void
    {
        $this->connection = $this->thirdPartyConnection(true, false);

        $this->assertSame([], $this->builder()->build('banner', 1, 1)['attributes']);
    }

    public function testThirdPartyRowFillsMetaContentAndExtras(): void
    {
        $this->connection = $this->thirdPartyConnection(true, [
            'title'           => 'Contact form',
            'description'     => 'Form body',
            'meta_title'      => 'Form meta',
            'meta_description' => '',
            'meta_keywords'   => 'form',
            'content_above'   => 'Above',
            'success_message' => 'Thanks',
        ]);

        $ctx = $this->builder()->build('dynamic_form', 4, 1);

        $this->assertSame('Form body', $ctx['content']);
        $this->assertSame(['title' => 'Form meta', 'description' => '', 'keywords' => 'form'], $ctx['meta']);
        $this->assertSame(
            ['name' => 'Contact form', 'content_above' => 'Above', 'success_message' => 'Thanks'],
            $ctx['attributes']
        );
    }
}
