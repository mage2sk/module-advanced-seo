<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Meta\Token\UrlKeyToken;
use PHPUnit\Framework\TestCase;

class UrlKeyTokenTest extends TestCase
{
    public function testCatalogEntities(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('__call')->willReturnCallback(
            static fn (string $m): ?string => $m === 'getUrlKey' ? 'blue-shirt' : null
        );
        $category = $this->createStub(Category::class);
        $category->method('getUrlKey')->willReturn('shirts');

        $token = new UrlKeyToken();
        $this->assertSame('blue-shirt', $token->getValue($product, []));
        $this->assertSame('shirts', $token->getValue($category, []));
    }

    public function testGenericObjectsAndArrays(): void
    {
        $withMethod = new class {
            public function getUrlKey(): string
            {
                return 'method-key';
            }
        };
        $withData = new class {
            public function getData(string $key): ?string
            {
                return $key === 'url_key' ? 'data-key' : null;
            }
        };

        $token = new UrlKeyToken();
        $this->assertSame('method-key', $token->getValue($withMethod, []));
        $this->assertSame('data-key', $token->getValue($withData, []));
        $this->assertSame('', $token->getValue(new DataObject(), []));
        $this->assertSame('arr-key', $token->getValue(['url_key' => 'arr-key'], []));
        $this->assertSame('', $token->getValue([], []));
        $this->assertSame('', $token->getValue(new \stdClass(), []));
    }
}
