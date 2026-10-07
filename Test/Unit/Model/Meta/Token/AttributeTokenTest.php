<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Meta\Token\AttributeToken;
use PHPUnit\Framework\TestCase;

class AttributeTokenTest extends TestCase
{
    private function product(?AttributeValue $custom, mixed $data, ?ProductResource $resource = null): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getCustomAttribute')->willReturn($custom);
        $product->method('getData')->willReturn($data);
        $product->method('getResource')->willReturn($resource);

        return $product;
    }

    private function resourceWithLabel(mixed $label, bool $usesSource = true): ProductResource
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getOptionText')->willReturn($label);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('usesSource')->willReturn($usesSource);
        $attribute->method('getSource')->willReturn($source);
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getAttribute')->willReturn($attribute);

        return $resource;
    }

    public function testMissingOrInvalidArgumentYieldsEmpty(): void
    {
        $token = new AttributeToken();
        $this->assertSame('', $token->getValue(['color' => 'red'], []));
        $this->assertSame('', $token->getValue(['color' => 'red'], [], ''));
        $this->assertSame('', $token->getValue(['color' => 'red'], [], '--'));
    }

    public function testArrayAndDataObjectEntitiesAreRead(): void
    {
        $token = new AttributeToken();
        $this->assertSame('red', $token->getValue(['color' => 'red'], [], 'col-or'));
        $this->assertSame('', $token->getValue(['color' => ['x']], [], 'color'));
        $this->assertSame('12', $token->getValue(new DataObject(['size' => 12]), [], 'size'));
        $this->assertSame('', $token->getValue(new DataObject(['size' => ['a']]), [], 'size'));
        $this->assertSame('', $token->getValue('scalar', [], 'size'));
    }

    public function testProductOptionLabelIsPreferredOverRawValue(): void
    {
        $product = $this->product(new AttributeValue(['value' => '5']), null, $this->resourceWithLabel('Blue'));
        $this->assertSame('Blue', (new AttributeToken())->getValue($product, [], 'color'));
    }

    public function testProductMultiselectLabelsAreJoined(): void
    {
        $product = $this->product(
            new AttributeValue(['value' => '5,6']),
            null,
            $this->resourceWithLabel(['Red', 'Green'])
        );
        $this->assertSame('Red, Green', (new AttributeToken())->getValue($product, [], 'color'));
    }

    public function testProductFallsBackToRawDataWhenNoSourceLabel(): void
    {
        $product = $this->product(null, 'Cotton', $this->resourceWithLabel(false));
        $this->assertSame('Cotton', (new AttributeToken())->getValue($product, [], 'material'));

        $plain = $this->product(null, 'Wool', $this->resourceWithLabel('ignored', false));
        $this->assertSame('Wool', (new AttributeToken())->getValue($plain, [], 'material'));
    }

    public function testProductWithEmptyOrNonScalarValueYieldsEmpty(): void
    {
        $token = new AttributeToken();
        $this->assertSame('', $token->getValue($this->product(null, ''), [], 'material'));
        $this->assertSame('', $token->getValue($this->product(null, null), [], 'material'));
        $this->assertSame(
            '',
            $token->getValue($this->product(new AttributeValue(['value' => ['a']]), null), [], 'material')
        );
    }

    public function testResourceFailureFallsBackToRawValue(): void
    {
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getAttribute')->willThrowException(new \RuntimeException('boom'));
        $product = $this->product(new AttributeValue(['value' => 'Linen']), null, $resource);

        $this->assertSame('Linen', (new AttributeToken())->getValue($product, [], 'material'));
    }
}
