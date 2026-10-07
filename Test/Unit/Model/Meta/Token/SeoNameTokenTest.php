<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\Token\SeoNameToken;
use PHPUnit\Framework\TestCase;

class SeoNameTokenTest extends TestCase
{
    private function token(bool $enabled): SeoNameToken
    {
        $config = $this->createStub(Config::class);
        $config->method('isSeoNameEnabled')->willReturn($enabled);

        return new SeoNameToken($config);
    }

    public function testNullEntityYieldsEmpty(): void
    {
        $this->assertSame('', $this->token(true)->getValue(null, []));
    }

    public function testSeoNameFromObjectOrArrayWhenEnabled(): void
    {
        $token = $this->token(true);
        $this->assertSame('SEO', $token->getValue(new DataObject(['seo_name' => 'SEO', 'name' => 'N']), ['store_id' => 1]));
        $this->assertSame('ArrSeo', $token->getValue(['seo_name' => 'ArrSeo', 'name' => 'N'], []));
    }

    public function testFallsBackToNameWhenSeoNameMissingOrDisabled(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturn('');
        $product->method('getName')->willReturn('Product name');

        $this->assertSame('Product name', $this->token(true)->getValue($product, []));
        $this->assertSame('N', $this->token(false)->getValue(['seo_name' => 'S', 'name' => 'N'], []));
        $this->assertSame('', $this->token(true)->getValue(['seo_name' => ''], []));
    }

    public function testNameExtractionFromGenericObjects(): void
    {
        $named = new class {
            public function getName(): string
            {
                return 'Named';
            }
        };
        $titled = new class {
            public function getTitle(): string
            {
                return 'Titled';
            }
        };

        $token = $this->token(false);
        $this->assertSame('Named', $token->getValue($named, []));
        $this->assertSame('Titled', $token->getValue($titled, []));
        $this->assertSame('', $token->getValue(new \stdClass(), []));
    }
}
