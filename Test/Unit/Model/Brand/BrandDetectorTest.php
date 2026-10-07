<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Brand;

use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Layer\State;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Brand\BrandDetector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BrandDetectorTest extends TestCase
{
    private function request(array $params): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );
        return $request;
    }

    private function config(string $attribute): SeoConfig
    {
        $config = $this->createStub(SeoConfig::class);
        $config->method('getBrandAttribute')->willReturn($attribute);
        return $config;
    }

    private function filterItem(string $requestVar, mixed $label): object
    {
        $filter = new class ($requestVar) {
            public function __construct(private string $var)
            {
            }

            public function getRequestVar(): string
            {
                return $this->var;
            }
        };

        return new class ($filter, $label) {
            public function __construct(private object $filter, private mixed $label)
            {
            }

            public function getFilter(): object
            {
                return $this->filter;
            }

            public function getLabel(): mixed
            {
                return $this->label;
            }
        };
    }

    private function layerResolver(array $filters): LayerResolver
    {
        $state = $this->createStub(State::class);
        $state->method('getFilters')->willReturn($filters);
        $layer = $this->createStub(Layer::class);
        $layer->method('getState')->willReturn($state);
        $resolver = $this->createStub(LayerResolver::class);
        $resolver->method('get')->willReturn($layer);
        return $resolver;
    }

    private function failingLayerResolver(): LayerResolver
    {
        $resolver = $this->createStub(LayerResolver::class);
        $resolver->method('get')->willThrowException(new \RuntimeException('no layer'));
        return $resolver;
    }

    public function testNoBrandAttributeConfigured(): void
    {
        $detector = new BrandDetector(
            $this->config(''),
            $this->failingLayerResolver(),
            $this->createStub(AttributeRepositoryInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertFalse($detector->isBrandPage($this->request(['manufacturer' => '5'])));
        $this->assertNull($detector->getCurrentBrand($this->request([])));
    }

    public function testIsBrandPageDependsOnParam(): void
    {
        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->failingLayerResolver(),
            $this->createStub(AttributeRepositoryInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertTrue($detector->isBrandPage($this->request(['manufacturer' => '5'])));
        $this->assertTrue($detector->isBrandPage($this->request(['manufacturer' => '5'])));
        $this->assertFalse($detector->isBrandPage($this->request(['manufacturer' => ''])));
        $this->assertFalse($detector->isBrandPage($this->request([])));
    }

    public function testBrandLabelFromLayerState(): void
    {
        $attributeRepo = $this->createMock(AttributeRepositoryInterface::class);
        $attributeRepo->expects($this->never())->method('get');

        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->layerResolver([$this->filterItem('color', 'Red'), $this->filterItem('manufacturer', ['Acme', 'Bolt'])]),
            $attributeRepo,
            $this->createStub(LoggerInterface::class)
        );

        $request = $this->request(['manufacturer' => '5,6']);
        $this->assertSame('Acme, Bolt', $detector->getCurrentBrand($request));
        $this->assertSame('Acme, Bolt', $detector->getCurrentBrand($request));
    }

    public function testBrandLabelStringFromLayerState(): void
    {
        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->layerResolver([$this->filterItem('manufacturer', 'Acme')]),
            $this->createStub(AttributeRepositoryInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame('Acme', $detector->getCurrentBrand($this->request(['manufacturer' => '5'])));
    }

    public function testFallbackToAttributeOptionLabels(): void
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getOptionText')->willReturnMap([
            ['5', 'Acme'],
            ['6', ['Bolt', 'Inc']],
            ['7', false],
        ]);

        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);

        $attributeRepo = $this->createMock(AttributeRepositoryInterface::class);
        $attributeRepo->expects($this->once())->method('get')
            ->with('catalog_product', 'manufacturer')->willReturn($attribute);

        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->layerResolver([]),
            $attributeRepo,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame('Acme, Bolt, Inc', $detector->getCurrentBrand($this->request(['manufacturer' => '5, ,6,7'])));
    }

    public function testNoOptionLabelsReturnsNull(): void
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getOptionText')->willReturn('');

        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);

        $attributeRepo = $this->createStub(AttributeRepositoryInterface::class);
        $attributeRepo->method('get')->willReturn($attribute);

        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->failingLayerResolver(),
            $attributeRepo,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertNull($detector->getCurrentBrand($this->request(['manufacturer' => '9'])));
    }

    public function testTextAttributeReturnsRawValue(): void
    {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(false);

        $attributeRepo = $this->createStub(AttributeRepositoryInterface::class);
        $attributeRepo->method('get')->willReturn($attribute);

        $detector = new BrandDetector(
            $this->config('brand_text'),
            $this->layerResolver([]),
            $attributeRepo,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame('Acme', $detector->getCurrentBrand($this->request(['brand_text' => 'Acme'])));
    }

    public function testAttributeLookupFailureLoggedAndNull(): void
    {
        $attributeRepo = $this->createStub(AttributeRepositoryInterface::class);
        $attributeRepo->method('get')->willThrowException(new \RuntimeException('missing'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')
            ->with('Panth SEO BrandDetector: option label resolution failed', [
                'attribute' => 'manufacturer',
                'value' => '5',
                'error' => 'missing',
            ]);

        $detector = new BrandDetector(
            $this->config('manufacturer'),
            $this->layerResolver([]),
            $attributeRepo,
            $logger
        );

        $this->assertNull($detector->getCurrentBrand($this->request(['manufacturer' => '5'])));
    }
}
