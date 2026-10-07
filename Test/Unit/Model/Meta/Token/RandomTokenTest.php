<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Panth\AdvancedSEO\Model\Meta\Token\RandomToken;
use PHPUnit\Framework\TestCase;

class RandomTokenTest extends TestCase
{
    public function testEmptyArgumentsYieldEmpty(): void
    {
        $token = new RandomToken();
        $this->assertSame('', $token->getValue(null, []));
        $this->assertSame('', $token->getValue(null, [], ''));
        $this->assertSame('', $token->getValue(null, [], ' | | '));
    }

    public function testSingleOptionIsReturnedTrimmed(): void
    {
        $this->assertSame('Only', (new RandomToken())->getValue(null, [], '  Only  |'));
    }

    public function testPickIsAlwaysOneOfTheOptions(): void
    {
        $token = new RandomToken();
        for ($i = 0; $i < 20; $i++) {
            $this->assertContains($token->getValue(null, [], 'Buy| Shop |Order'), ['Buy', 'Shop', 'Order']);
        }
    }
}
