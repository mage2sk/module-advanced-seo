<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Canonical;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Canonical\CustomCanonicalRepository;
use Panth\AdvancedSEO\Model\Canonical\Resolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResolverCoverageTest extends TestCase
{
    private array $setCategoryIds = [];

    private function config(array $options): Config
    {
        $options += [
            'isDebug' => false,
            'isCanonicalDisabledForNoindex' => false,
            'getCanonicalIgnorePages' => '',
            'getCrossDomainCanonicalStore' => 0,
            'canonicalPaginatedToFirst' => false,
            'canonicalLowercaseHost' => true,
            'stripCanonicalQuery' => false,
            'getTrailingSlashHomepage' => 'none',
            'canonicalRemoveTrailingSlash' => false,
            'isAssociatedProductCanonical' => false,
            'getProductCanonicalType' => 'without_category',
        ];
        $config = $this->createStub(Config::class);
        foreach ($options as $method => $value) {
            $config->method($method)->willReturn($value);
        }
        return $config;
    }

    private function storeManager(array $bases = [1 => 'https://shop.test/', 2 => 'https://other.test/']): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($id = null) use ($bases) {
            if (!isset($bases[(int) $id])) {
                throw new NoSuchEntityException();
            }
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn($bases[(int) $id]);
            return $store;
        });
        return $storeManager;
    }

    private function resolver(array $deps = [], array $configOptions = []): Resolver
    {
        $custom = $deps['custom'] ?? null;
        if ($custom === null) {
            $custom = $this->createStub(CustomCanonicalRepository::class);
            $custom->method('find')->willReturn(null);
        }
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn($deps['strip'] ?? '');

        return new Resolver(
            $deps['products'] ?? $this->createStub(ProductRepositoryInterface::class),
            $deps['categories'] ?? $this->createStub(CategoryRepositoryInterface::class),
            $deps['pages'] ?? $this->createStub(PageRepositoryInterface::class),
            $deps['storeManager'] ?? $this->storeManager(),
            $this->config($configOptions),
            $scope,
            $deps['logger'] ?? $this->createStub(LoggerInterface::class),
            $deps['eav'] ?? $this->createStub(EavConfig::class),
            $custom,
            $deps['configurable'] ?? null,
            $deps['pathGenerator'] ?? null,
            null
        );
    }

    private function product(string $type = 'simple', array $categoryIds = [], string $path = 'boot.html'): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($type);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        $product->method('setData')->willReturnCallback(function ($key, $value = null) use ($product) {
            $this->setCategoryIds[] = [$key, $value];
            return $product;
        });
        $url = $this->createStub(ProductUrl::class);
        $url->method('getUrl')->willReturnCallback(
            static fn ($p, array $params) => 'http://internal.host/'
                . ($params['_ignore_category'] ? '' : 'cat/') . $path . '?x=1#frag'
        );
        $product->method('getUrlModel')->willReturn($url);
        return $product;
    }

    private function category(int $id, int $level, bool $active = true, string $url = ''): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getLevel')->willReturn($level);
        $category->method('getIsActive')->willReturn($active);
        $category->method('getUrl')->willReturn($url);
        return $category;
    }

    public function testNormalizeEdgeCases(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('', $resolver->normalize('', 1));
        $this->assertSame('/relative/path', $resolver->normalize('/relative/path', 1));
        $this->assertSame(
            'https://shop.test:8443/a/?keep=1',
            $resolver->normalize('http://SHOP.test:8443/a/?utm_source=x&keep=1&gclid=z', 1)
        );
    }

    public function testNormalizeKeepsHttpWhenBaseIsNotSecureAndRespectsOptions(): void
    {
        $resolver = $this->resolver(
            ['storeManager' => $this->storeManager([1 => 'http://shop.test/']), 'strip' => "foo, bad param!\nbar"],
            ['canonicalLowercaseHost' => false, 'canonicalRemoveTrailingSlash' => true]
        );

        $this->assertSame('http://SHOP.test/a?utm_source=x', $resolver->normalize('http://SHOP.test/a/?foo=1&bar=2&utm_source=x', 1));
    }

    public function testNormalizeStripsWholeQueryWhenConfigured(): void
    {
        $resolver = $this->resolver(['storeManager' => $this->storeManager([])], ['stripCanonicalQuery' => true]);

        $this->assertSame('ftp://shop.test/a', $resolver->normalize('ftp://shop.test/a?x=1', 1));
    }

    public function testHomepageTrailingSlashPolicies(): void
    {
        $this->assertSame('https://shop.test/', $this->resolver([], ['getTrailingSlashHomepage' => 'add'])
            ->normalize('https://shop.test', 1));
        $this->assertSame('https://shop.test/index.php/', $this->resolver([], ['getTrailingSlashHomepage' => 'add'])
            ->normalize('https://shop.test/index.php', 1));
        $this->assertSame('https://shop.test', $this->resolver([], ['getTrailingSlashHomepage' => 'remove'])
            ->normalize('https://shop.test/', 1));
        $this->assertSame('https://shop.test/index.php', $this->resolver([], ['getTrailingSlashHomepage' => 'remove'])
            ->normalize('https://shop.test/index.php/', 1));
        $this->assertSame('https://shop.test/', $this->resolver([], ['canonicalRemoveTrailingSlash' => true])
            ->normalize('https://shop.test/', 1));
    }

    public function testCustomCanonicalWins(): void
    {
        $custom = $this->createStub(CustomCanonicalRepository::class);
        $custom->method('find')->willReturn('http://shop.test/custom?utm_medium=a');

        $this->assertSame(
            'https://shop.test/custom',
            $this->resolver(['custom' => $custom])->getCanonicalUrl('product', 1, 1)
        );
    }

    public function testCustomCanonicalFailureFallsBackToBuiltUrl(): void
    {
        $custom = $this->createStub(CustomCanonicalRepository::class);
        $custom->method('find')->willThrowException(new \RuntimeException('db'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with('Panth SEO custom canonical lookup failed: db');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturn($this->product());

        $this->assertSame(
            'https://shop.test/boot.html?x=1',
            $this->resolver(['custom' => $custom, 'logger' => $logger, 'products' => $products])
                ->getCanonicalUrl('product', 1, 1)
        );
    }

    public function testProductWithoutCategoryIsRehostedAndPaginated(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturn($this->product());

        $this->assertSame(
            'https://shop.test/boot.html?x=1&p=3',
            $this->resolver(['products' => $products])->getCanonicalUrl('product', 1, 1, ['p' => '3'])
        );
        $this->assertSame(
            'https://shop.test/boot.html?x=1',
            $this->resolver(['products' => $products], ['canonicalPaginatedToFirst' => true])
                ->getCanonicalUrl('product', 1, 1, ['p' => '3'])
        );
    }

    public function testProductLongestAndShortestCategory(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturnCallback(function (int $id) {
            return match ($id) {
                1 => $this->category(1, 1),
                2 => $this->category(2, 2),
                3 => $this->category(3, 4),
                4 => $this->category(4, 5, false),
                5 => $this->category(5, 3),
                default => throw new NoSuchEntityException(),
            };
        });
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturn($this->product('simple', [1, 99, 5, 2, 3, 4]));

        $url = $this->resolver(['products' => $products, 'categories' => $categories], ['getProductCanonicalType' => 'longest'])
            ->getCanonicalUrl('product', 1, 1);
        $this->assertSame('https://shop.test/cat/boot.html?x=1', $url);
        $this->assertSame(['category_id', 3], end($this->setCategoryIds));

        $this->resolver(['products' => $products, 'categories' => $categories], ['getProductCanonicalType' => 'shortest'])
            ->getCanonicalUrl('product', 1, 1);
        $this->assertSame(['category_id', 2], end($this->setCategoryIds));
    }

    public function testProductWithNoUsableCategoryIgnoresCategory(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturn($this->product('simple', []));

        $this->assertSame(
            'https://shop.test/boot.html?x=1',
            $this->resolver(['products' => $products], ['getProductCanonicalType' => 'longest'])->getCanonicalUrl('product', 1, 1)
        );
        $this->assertSame([], $this->setCategoryIds);
    }

    public function testAssociatedProductUsesConfigurableParent(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willReturnCallback(
            fn (int $id) => $id === 50 ? $this->product('configurable', [], 'parent.html') : $this->product('simple')
        );
        $configurable = $this->createStub(ConfigurableResource::class);
        $configurable->method('getParentIdsByChild')->willReturn(['50']);

        $this->assertSame(
            'https://shop.test/parent.html?x=1',
            $this->resolver(['products' => $products, 'configurable' => $configurable], ['isAssociatedProductCanonical' => true])
                ->getCanonicalUrl('product', 7, 1)
        );
    }

    public function testMissingProductOrUnknownTypeYieldsEmpty(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willThrowException(new NoSuchEntityException());

        $this->assertSame('', $this->resolver(['products' => $products])->getCanonicalUrl('product', 1, 1));
        $this->assertSame('', $this->resolver()->getCanonicalUrl('other', 1, 1));
    }

    public function testBuildFailureIsLogged(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('getById')->willThrowException(new \RuntimeException('repo down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Panth SEO canonical build failed',
            ['entity_type' => 'product', 'entity_id' => 4, 'error' => 'repo down']
        );

        $this->assertSame('', $this->resolver(['products' => $products, 'logger' => $logger])->getCanonicalUrl('product', 4, 1));
    }

    public function testCategoryCanonicalVariants(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturnCallback(fn (int $id) => match ($id) {
            1 => $this->category(1, 2, true, 'http://internal.host/men.html?a=1'),
            2 => $this->category(2, 2, true, ''),
            3 => $this->category(3, 2, true, 'not-a-url'),
            default => throw new NoSuchEntityException(),
        });

        $resolver = $this->resolver(['categories' => $categories]);
        $this->assertSame('https://shop.test/men.html?a=1', $resolver->getCanonicalUrl('category', 1, 1));
        $this->assertSame('', $resolver->getCanonicalUrl('category', 2, 1));
        $this->assertSame('', $resolver->getCanonicalUrl('category', 9, 1));

        $generator = $this->createStub(CategoryUrlPathGenerator::class);
        $generator->method('getUrlPathWithSuffix')->willReturnCallback(static function ($category) {
            if ((int) $category->getId() === 3) {
                throw new \RuntimeException('path');
            }
            return 'men/shoes.html';
        });
        $withGenerator = $this->resolver(['categories' => $categories, 'pathGenerator' => $generator]);
        $this->assertSame('https://shop.test/men/shoes.html', $withGenerator->getCanonicalUrl('category', 1, 1));
        $this->assertSame('https://shop.test/not-a-url', $withGenerator->getCanonicalUrl('category', 3, 1));

        $noStore = $this->resolver(['categories' => $categories, 'storeManager' => $this->storeManager([])]);
        $this->assertSame('http://internal.host/men.html?a=1', $noStore->getCanonicalUrl('category', 1, 5));
    }

    public function testCmsCanonical(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData('identifier', 'about-us');
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturnCallback(static function (int $id) use ($page) {
            if ($id !== 3) {
                throw new NoSuchEntityException();
            }
            return $page;
        });

        $resolver = $this->resolver(['pages' => $pages]);
        $this->assertSame('https://shop.test/about-us', $resolver->getCanonicalUrl('cms', 3, 1));
        $this->assertSame('', $resolver->getCanonicalUrl('cms', 4, 1));
        $this->assertSame('', $this->resolver(['pages' => $pages, 'storeManager' => $this->storeManager([])])
            ->getCanonicalUrl('cms', 3, 1));
    }

    public function testCrossDomainStoreUrlIsPreferred(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData('identifier', 'about-us');
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturn($page);

        $this->assertSame(
            'https://other.test/about-us',
            $this->resolver(['pages' => $pages], ['getCrossDomainCanonicalStore' => 2])->getCanonicalUrl('cms', 3, 1)
        );
        $this->assertSame(
            'https://shop.test/about-us',
            $this->resolver(['pages' => $pages], ['getCrossDomainCanonicalStore' => 9])->getCanonicalUrl('cms', 3, 1)
        );
    }

    public function testIgnoredPagePatterns(): void
    {
        $resolver = $this->resolver([], ['getCanonicalIgnorePages' => "checkout/*\n  \n/exact/page\nbad pattern!"]);

        $this->assertSame('', $resolver->getCanonicalUrl('other', 1, 1, ['current_path' => '/checkout/cart?x=1']));
        $this->assertSame('', $resolver->getCanonicalUrl('other', 1, 1, ['current_path' => '//exact//page']));

        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData('identifier', 'bad');
        $pages = $this->createStub(PageRepositoryInterface::class);
        $pages->method('getById')->willReturn($page);
        $withPages = $this->resolver(['pages' => $pages], ['getCanonicalIgnorePages' => "bad pattern!\n/other"]);
        $this->assertSame('https://shop.test/bad', $withPages->getCanonicalUrl('cms', 1, 1, ['current_path' => '/bad pattern!']));
    }

    public function testLayeredNavigationOverrides(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('get')->willReturn($this->category(1, 2, true, 'https://shop.test/men.html'));

        $attrs = [
            'color' => 'noindex',
            'size' => 'category',
            'material' => 'filtered',
            'global' => 'use_global',
        ];
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($type, $code) use ($attrs) {
            if (!isset($attrs[$code])) {
                throw new \RuntimeException('unknown');
            }
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getData')->willReturn($attrs[$code]);
            return $attribute;
        });

        $resolver = $this->resolver(['categories' => $categories, 'eav' => $eav]);

        $this->assertSame('', $resolver->getCanonicalUrl('category', 1, 1, ['active_filter_attributes' => ['missing', 'color']]));
        $this->assertSame(
            'https://shop.test/men.html?p=2',
            $resolver->getCanonicalUrl('category', 1, 1, ['active_filter_attributes' => ['size'], 'p' => 2])
        );
        $this->assertSame(
            'https://shop.test/men.html?material=5',
            $resolver->getCanonicalUrl('category', 1, 1, [
                'active_filter_attributes' => ['material'],
                'active_filter_params' => ['material' => '5'],
            ])
        );
        $this->assertSame(
            'https://shop.test/men.html',
            $resolver->getCanonicalUrl('category', 1, 1, ['active_filter_attributes' => ['global']])
        );
    }
}
