<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Meta\Token\DescriptionToken;
use PHPUnit\Framework\TestCase;

class DescriptionTokenTest extends TestCase
{
    private function product(?string $short, ?string $description): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getCustomAttribute')->willReturnCallback(
            static function (string $code) use ($short, $description): ?AttributeValue {
                $value = $code === 'short_description' ? $short : $description;

                return $value === null ? null : new AttributeValue(['value' => $value]);
            }
        );

        return $product;
    }

    public function testProductPrefersShortDescriptionAndStripsMarkup(): void
    {
        $token = new DescriptionToken();
        $this->assertSame(
            'Short and sweet',
            $token->getValue($this->product("<p>Short   and\n sweet</p>", 'Long text'), [])
        );
        $this->assertSame('Long text', $token->getValue($this->product(null, '<b>Long</b> text'), []));
        $this->assertSame('', $token->getValue($this->product(null, null), []));
    }

    public function testCategoryUsesDescription(): void
    {
        $category = $this->createStub(Category::class);
        $category->method('__call')->willReturnCallback(
            static fn (string $m): ?string => $m === 'getDescription' ? '<div> Category  copy </div>' : null
        );

        $this->assertSame('Category copy', (new DescriptionToken())->getValue($category, []));
    }

    public function testCmsPageFallsBackToContentHeading(): void
    {
        $withMeta = $this->createStub(Page::class);
        $withMeta->method('getMetaDescription')->willReturn('Meta text');
        $withMeta->method('getContentHeading')->willReturn('Heading');

        $withoutMeta = $this->createStub(Page::class);
        $withoutMeta->method('getMetaDescription')->willReturn('');
        $withoutMeta->method('getContentHeading')->willReturn('Heading');

        $token = new DescriptionToken();
        $this->assertSame('Meta text', $token->getValue($withMeta, []));
        $this->assertSame('Heading', $token->getValue($withoutMeta, []));
    }

    public function testGenericObjectsWithGetDescriptionAreSupported(): void
    {
        $entity = new class {
            public function getDescription(): string
            {
                return ' Generic ';
            }
        };

        $this->assertSame('Generic', (new DescriptionToken())->getValue($entity, []));
        $this->assertSame('', (new DescriptionToken())->getValue(new DataObject(), []));
        $this->assertSame('', (new DescriptionToken())->getValue(['description' => 'x'], []));
    }
}
