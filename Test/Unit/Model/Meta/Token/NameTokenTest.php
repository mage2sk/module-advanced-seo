<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Panth\AdvancedSEO\Model\Meta\Token\NameToken;
use PHPUnit\Framework\TestCase;

class NameTokenTest extends TestCase
{
    public function testCatalogAndCmsEntities(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getName')->willReturn('Phone');
        $category = $this->createStub(Category::class);
        $category->method('getName')->willReturn('Phones');
        $page = $this->createStub(Page::class);
        $page->method('getTitle')->willReturn('About us');

        $token = new NameToken();
        $this->assertSame('Phone', $token->getValue($product, []));
        $this->assertSame('Phones', $token->getValue($category, []));
        $this->assertSame('About us', $token->getValue($page, []));
    }

    public function testGenericObjectsArraysAndUnknowns(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Generic';
            }
        };

        $token = new NameToken();
        $this->assertSame('Generic', $token->getValue($entity, []));
        $this->assertSame('Arr', $token->getValue(['name' => 'Arr'], []));
        $this->assertSame('', $token->getValue(['title' => 'x'], []));
        $this->assertSame('', $token->getValue(new \stdClass(), []));
    }
}
