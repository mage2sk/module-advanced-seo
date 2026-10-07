<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Product;
use Panth\AdvancedSEO\Model\Meta\Token\SkuToken;
use PHPUnit\Framework\TestCase;

class SkuTokenTest extends TestCase
{
    public function testProductAndGenericObjects(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('SKU-1');
        $generic = new class {
            public function getSku(): string
            {
                return 'GEN-2';
            }
        };

        $token = new SkuToken();
        $this->assertSame('SKU-1', $token->getValue($product, []));
        $this->assertSame('GEN-2', $token->getValue($generic, []));
        $this->assertSame('', $token->getValue(['sku' => 'x'], []));
    }
}
