<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta;

use Panth\AdvancedSEO\Model\Meta\Token\TokenInterface;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Model\Meta\TokenRegistry;
use Panth\AdvancedSEO\Model\Text\Truncator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TemplateRendererTest extends TestCase
{
    private function fixed(string $value): TokenInterface
    {
        return new class ($value) implements TokenInterface {
            public function __construct(private readonly string $value)
            {
            }

            public function getValue(mixed $entity, array $context, ?string $argument = null): string
            {
                return $argument === null ? $this->value : $this->value . '[' . $argument . ']';
            }
        };
    }

    private function renderer(array $tokens = [], ?LoggerInterface $logger = null): TemplateRenderer
    {
        $tokens += [
            'name'  => $this->fixed('Blue Shirt'),
            'store' => $this->fixed('Main'),
            'empty' => $this->fixed(''),
        ];

        return new TemplateRenderer(
            new TokenRegistry($tokens),
            $logger ?? $this->createStub(LoggerInterface::class),
            new Truncator()
        );
    }

    public function testTemplatesWithoutTokensAreReturnedVerbatim(): void
    {
        $renderer = $this->renderer();
        $this->assertSame('', $renderer->render('', null));
        $this->assertSame('  Plain - text ', $renderer->render('  Plain - text ', null));
    }

    public function testTokensArgumentsAndDottedNamesResolve(): void
    {
        $renderer = $this->renderer();
        $this->assertSame('Buy Blue Shirt at Main', $renderer->render('Buy {{ name }} at {{store}}', null));
        $this->assertSame('Main[code]', $renderer->render('{{store:code}}', null));
        $this->assertSame('Main[url]', $renderer->render('{{STORE.url}}', null));
    }

    public function testContextValuesFillUnknownTokens(): void
    {
        $renderer = $this->renderer();
        $this->assertSame('Page 3', $renderer->render('Page {{page_no}}', null, ['page_no' => 3]));
        $this->assertSame('Page', $renderer->render('Page {{page_no}}', null, ['page_no' => ['x']]));
        $this->assertSame('Page', $renderer->render('Page {{missing}}', null));
    }

    public function testFiltersAreApplied(): void
    {
        $renderer = $this->renderer(['html' => $this->fixed('<b>bold</b>   text')]);
        $this->assertSame('BLUE SHIRT', $renderer->render('{{name|upper}}', null));
        $this->assertSame('blue shirt', $renderer->render('{{name | lower}}', null));
        $this->assertSame('Bold Text', $renderer->render('{{html|strip|title}}', null));
        $this->assertSame('N/A', $renderer->render("{{empty|default:'N/A'}}", null));
        $this->assertSame('Blue Shirt', $renderer->render('{{name|default:"x"}}', null));
        $this->assertSame('Blue...', $renderer->render('{{name|truncate:8}}', null));
        $this->assertSame('Blue Shirt', $renderer->render('{{name|unknown}}', null));
    }

    public function testDanglingSeparatorsAndCommasAreCleaned(): void
    {
        $renderer = $this->renderer();
        $this->assertSame('Main', $renderer->render('{{empty}} - {{store}}', null));
        $this->assertSame('Blue Shirt', $renderer->render('{{name}} | {{empty}}', null));
        $this->assertSame('Blue Shirt, Main', $renderer->render('{{name}}, {{empty}}, {{store}},', null));
        $this->assertSame('Blue Shirt Main', $renderer->render('{{name}}    {{store}}', null));
    }

    public function testNestedTokensAreExpandedOnTheNextPass(): void
    {
        $renderer = $this->renderer(['wrapper' => $this->fixed('{{name}} deal')]);
        $this->assertSame('Blue Shirt deal', $renderer->render('{{wrapper}}', null));
    }

    public function testSelfReferencingTokenStopsWithoutLooping(): void
    {
        $renderer = $this->renderer(['loop' => $this->fixed('{{loop}}')]);
        $this->assertSame('{{loop}}', $renderer->render('{{loop}}', null));
    }

    public function testFailingTokenIsLoggedAndRendersEmpty(): void
    {
        $failing = new class implements TokenInterface {
            public function getValue(mixed $entity, array $context, ?string $argument = null): string
            {
                throw new \RuntimeException('token broke');
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Panth SEO token resolve failed',
            ['token' => 'bad', 'error' => 'token broke']
        );

        $this->assertSame('Main', $this->renderer(['bad' => $failing], $logger)->render('{{bad}} {{store}}', null));
    }
}
