<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Panth\AdvancedSEO\Model\Meta\Token\NameToken;
use Panth\AdvancedSEO\Model\Meta\Token\SkuToken;
use Panth\AdvancedSEO\Model\Meta\TokenRegistry;
use PHPUnit\Framework\TestCase;

class TokenRegistryTest extends TestCase
{
    public function testTokensAreRegisteredCaseInsensitivelyAndInvalidOnesDropped(): void
    {
        $name = new NameToken();
        $sku  = new SkuToken();
        $registry = new TokenRegistry(['Name' => $name, 'SKU' => $sku, 'bogus' => new \stdClass(), 'str' => 'x']);

        $this->assertTrue($registry->has('name'));
        $this->assertTrue($registry->has('NAME'));
        $this->assertFalse($registry->has('bogus'));
        $this->assertSame($sku, $registry->get('sku'));
        $this->assertNull($registry->get('missing'));
        $this->assertSame(['name' => $name, 'sku' => $sku], $registry->all());
    }

    public function testEmptyRegistry(): void
    {
        $registry = new TokenRegistry();
        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->has('name'));
    }
}
