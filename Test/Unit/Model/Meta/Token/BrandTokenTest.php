<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;
use Panth\AdvancedSEO\Model\Meta\Token\BrandToken;
use PHPUnit\Framework\TestCase;

class BrandTokenTest extends TestCase
{
    private function token(?string $detected, string $attribute = 'manufacturer'): BrandToken
    {
        $config = $this->createStub(Config::class);
        $config->method('getBrandAttribute')->willReturn($attribute);
        $detector = $this->createStub(BrandDetector::class);
        $detector->method('getCurrentBrand')->willReturn($detected);

        return new BrandToken($config, $detector, $this->createStub(RequestInterface::class));
    }

    private function product(?AttributeValue $custom, mixed $data = null, ?ProductResource $resource = null): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getCustomAttribute')->willReturn($custom);
        $product->method('getData')->willReturn($data);
        $product->method('getResource')->willReturn($resource);

        return $product;
    }

    public function testCategoryUsesTheDetectedBrand(): void
    {
        $this->assertSame('Acme', $this->token('Acme')->getValue($this->createStub(Category::class), []));
    }

    public function testCategoryWithoutBrandFallsBackToContext(): void
    {
        $this->assertSame(
            'Ctx Brand',
            $this->token(null)->getValue($this->createStub(Category::class), ['brand_name' => 'Ctx Brand'])
        );
        $this->assertSame('', $this->token('')->getValue($this->createStub(Category::class), ['brand_name' => '']));
    }

    public function testProductOptionLabelIsUsed(): void
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getOptionText')->willReturn(['Acme', 'Beta']);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getAttribute')->willReturn($attribute);

        $product = $this->product(new AttributeValue(['value' => '3']), null, $resource);
        $this->assertSame('Acme, Beta', $this->token(null)->getValue($product, []));
    }

    public function testProductRawDataIsUsedWhenCustomAttributeIsEmpty(): void
    {
        $product = $this->product(new AttributeValue(['value' => '']), 'RawBrand');
        $this->assertSame('RawBrand', $this->token(null)->getValue($product, []));
    }

    public function testProductResourceFailureKeepsRawValue(): void
    {
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getAttribute')->willThrowException(new \RuntimeException('x'));
        $product = $this->product(new AttributeValue(['value' => 'Zeta']), null, $resource);
        $this->assertSame('Zeta', $this->token(null)->getValue($product, []));
    }

    public function testProductWithoutBrandFallsBackToDetector(): void
    {
        $this->assertSame('Detected', $this->token('Detected')->getValue($this->product(null), []));
        $this->assertSame('Detected', $this->token('Detected', '')->getValue($this->product(null, 'X'), []));
    }

    public function testNonScalarRawValueIsIgnored(): void
    {
        $this->assertSame('', $this->token(null)->getValue($this->product(null, ['a']), []));
    }

    public function testNothingAvailableYieldsEmpty(): void
    {
        $this->assertSame('', $this->token(null)->getValue(null, []));
    }
}
