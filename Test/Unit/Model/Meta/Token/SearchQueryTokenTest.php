<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Model\Meta\Token\SearchQueryToken;
use PHPUnit\Framework\TestCase;

class SearchQueryTokenTest extends TestCase
{
    private function token(string $param): SearchQueryToken
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($param);

        return new SearchQueryToken($request);
    }

    public function testContextQueryWinsOverRequest(): void
    {
        $this->assertSame('red shoes', $this->token('ignored')->getValue(null, ['search_query' => ' red   shoes ']));
    }

    public function testRequestQueryIsSanitised(): void
    {
        $this->assertSame('alert blue', $this->token('<b>alert</b> blue')->getValue(null, []));
        $this->assertSame('', $this->token('   ')->getValue(null, []));
    }

    public function testLongQueriesAreCutToOneHundredCharacters(): void
    {
        $result = $this->token(str_repeat('a', 150))->getValue(null, []);
        $this->assertSame(100, strlen($result));

        $multibyte = $this->token(str_repeat("\u{00e9}", 120))->getValue(null, []);
        $this->assertSame(100, mb_strlen($multibyte, 'UTF-8'));
    }
}
