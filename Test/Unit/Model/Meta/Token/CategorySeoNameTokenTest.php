<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Category;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\Token\CategorySeoNameToken;
use PHPUnit\Framework\TestCase;

class CategorySeoNameTokenTest extends TestCase
{
    private function token(bool $enabled): CategorySeoNameToken
    {
        $config = $this->createStub(Config::class);
        $config->method('isSeoNameEnabled')->willReturn($enabled);

        return new CategorySeoNameToken($config);
    }

    private function category(?string $seoName, string $name = 'Shoes'): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getData')->willReturn($seoName);
        $category->method('getName')->willReturn($name);

        return $category;
    }

    public function testNonCategoryYieldsEmpty(): void
    {
        $this->assertSame('', $this->token(true)->getValue(new \stdClass(), []));
    }

    public function testSeoNameWinsWhenEnabled(): void
    {
        $this->assertSame('Best Shoes', $this->token(true)->getValue($this->category('Best Shoes'), ['store_id' => 2]));
    }

    public function testEmptySeoNameFallsBackToName(): void
    {
        $this->assertSame('Shoes', $this->token(true)->getValue($this->category(''), []));
        $this->assertSame('Shoes', $this->token(true)->getValue($this->category(null), []));
    }

    public function testDisabledFeatureUsesName(): void
    {
        $this->assertSame('Shoes', $this->token(false)->getValue($this->category('Best Shoes'), []));
    }
}
