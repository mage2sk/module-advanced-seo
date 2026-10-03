<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Layer\State;
use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Model\Meta\Token\FilterToken;
use PHPUnit\Framework\TestCase;

class FilterTokenTest extends TestCase
{
    private function token(array $filters): FilterToken
    {
        $state = $this->createStub(State::class);
        $state->method('getFilters')->willReturn($filters);
        $layer = $this->createStub(Layer::class);
        $layer->method('getState')->willReturn($state);
        $resolver = $this->createStub(LayerResolver::class);
        $resolver->method('get')->willReturn($layer);

        return new FilterToken($resolver);
    }

    private function item(string $requestVar, mixed $label): DataObject
    {
        return new DataObject([
            'filter' => new DataObject(['request_var' => $requestVar]),
            'label'  => $label,
        ]);
    }

    public function testMissingArgumentYieldsEmpty(): void
    {
        $token = $this->token([$this->item('color', 'Red')]);
        $this->assertSame('', $token->getValue(null, []));
        $this->assertSame('', $token->getValue(null, [], ''));
        $this->assertSame('', $token->getValue(null, [], '-'));
    }

    public function testMatchingFilterLabelIsReturned(): void
    {
        $token = $this->token([$this->item('size', 'XL'), $this->item('color', 'Red')]);
        $this->assertSame('Red', $token->getValue(null, [], 'color'));
        $this->assertSame('', $token->getValue(null, [], 'brand'));
    }

    public function testArrayLabelsAreJoined(): void
    {
        $this->assertSame('Red, Blue', $this->token([$this->item('color', ['Red', 'Blue'])])->getValue(null, [], 'color'));
    }

    public function testLayerFailureYieldsEmpty(): void
    {
        $resolver = $this->createStub(LayerResolver::class);
        $resolver->method('get')->willThrowException(new \RuntimeException('no layer'));

        $this->assertSame('', (new FilterToken($resolver))->getValue(null, [], 'color'));
    }
}
