<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Panth\AdvancedSEO\Model\Meta\Token\PriceToken;
use PHPUnit\Framework\TestCase;

class PriceTokenTest extends TestCase
{
    private function product(mixed $final, mixed $price): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn($final);
        $product->method('getPrice')->willReturn($price);

        return $product;
    }

    public function testNonProductYieldsEmpty(): void
    {
        $this->assertSame('', (new PriceToken($this->createStub(PriceCurrencyInterface::class)))->getValue([], []));
    }

    public function testFinalPriceIsFormatted(): void
    {
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->expects($this->once())->method('format')->with(19.5, false)->willReturn('$19.50');

        $this->assertSame('$19.50', (new PriceToken($currency))->getValue($this->product(19.5, 25), []));
    }

    public function testRegularPriceIsUsedWhenFinalPriceIsZero(): void
    {
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->expects($this->once())->method('format')->with(25.0, false)->willReturn('$25.00');

        $this->assertSame('$25.00', (new PriceToken($currency))->getValue($this->product(0, 25), []));
    }

    public function testZeroPriceYieldsEmpty(): void
    {
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->expects($this->never())->method('format');

        $this->assertSame('', (new PriceToken($currency))->getValue($this->product(null, 0), []));
    }

    public function testFormatterFailureFallsBackToPlainNumber(): void
    {
        $currency = $this->createStub(PriceCurrencyInterface::class);
        $currency->method('format')->willThrowException(new \RuntimeException('no currency'));

        $this->assertSame('1234.50', (new PriceToken($currency))->getValue($this->product(1234.5, 0), []));
    }
}
