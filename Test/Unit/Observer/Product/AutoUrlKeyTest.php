<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Observer\Product;

use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Panth\AdvancedSEO\Observer\Product\AutoUrlKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoUrlKeyTest extends TestCase
{
    private ?string $urlKey = null;

    private ?Product $current = null;

    private function generated(): ?string
    {
        $value = $this->current?->getData('url_key');

        return $value === $this->urlKey ? null : $value;
    }

    private array $lookups = [];

    private array $warnings = [];

    private function observer(
        string $rendered,
        array $config = [],
        array $existing = [],
        bool $repositoryFails = false
    ): AutoUrlKey {
        $this->lookups = [];
        $this->warnings = [];

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('isEnabled')->willReturn($config['enabled'] ?? true);
        $seoConfig->method('isAutoUrlKeyEnabled')->willReturn($config['auto'] ?? true);
        $seoConfig->method('isAutoUrlKeyForExisting')->willReturn($config['existing'] ?? false);
        $seoConfig->method('getUrlKeyTemplate')->willReturn($config['template'] ?? '{{name}}');

        $renderer = $this->createStub(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(static function () use ($rendered): string {
            if ($rendered === '!throw') {
                throw new \RuntimeException('render failed');
            }
            return $rendered;
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->warnings[] = [$message, $context];
        });

        $candidate = '';
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value) use ($builder, &$candidate) {
            $candidate = $value;
            $this->lookups[] = $value;
            return $builder;
        });
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(
            function () use (&$candidate, $existing, $repositoryFails) {
                if ($repositoryFails) {
                    throw new \RuntimeException('search down');
                }
                $ids = $existing[$candidate] ?? [];
                $items = array_map(static fn(int $id) => new DataObject(['id' => $id]), $ids);
                $result = $this->createStub(ProductSearchResultsInterface::class);
                $result->method('getTotalCount')->willReturn(count($items));
                $result->method('getItems')->willReturn($items);
                return $result;
            }
        );

        return new AutoUrlKey($seoConfig, $renderer, $logger, $repository, $builder);
    }

    private function product(?int $id, string $urlKey = '', string $name = 'Blue Shirt', string $origKey = ''): Product
    {
        $this->urlKey = null;
        $product = new class extends Product {
            public function __construct()
            {
            }
        };
        $product->setData(['entity_id' => $id, 'store_id' => 1, 'name' => $name, 'url_key' => $urlKey]);
        if ($origKey !== '') {
            $product->setOrigData('url_key', $origKey);
        }
        $this->current = $product;
        $this->urlKey = $urlKey;

        return $product;
    }

    private static function event(?Product $product): Observer
    {
        return new Observer(['event' => new Event($product === null ? [] : ['product' => $product])]);
    }

    public function testANewProductGetsASluggedKeyFromTheTemplate(): void
    {
        $product = $this->product(null);
        $this->observer('  Blue  Shirt -- XL!! ')->execute(self::event($product));

        $this->assertSame('blue-shirt-xl', $this->generated());
        $this->assertSame(['blue-shirt-xl'], $this->lookups);
    }

    public function testAccentedCharactersAreTransliterated(): void
    {
        $product = $this->product(null);
        $this->observer('Caf' . "\u{00E9}" . ' Cr' . "\u{00E8}" . 'me')->execute(self::event($product));

        $this->assertSame('cafe-creme', $this->generated());
    }

    public function testATakenKeyGetsANumericSuffix(): void
    {
        $product = $this->product(null);
        $this->observer('Shirt', [], ['shirt' => [5], 'shirt-1' => [6]])->execute(self::event($product));

        $this->assertSame('shirt-2', $this->generated());
        $this->assertSame(['shirt', 'shirt-1', 'shirt-2'], $this->lookups);
    }

    public function testTheProductsOwnKeyIsNotAConflict(): void
    {
        $product = $this->product(5, 'blue-shirt');
        $this->observer('Shirt', ['existing' => true], ['shirt' => [5]])->execute(self::event($product));

        $this->assertSame('shirt', $this->generated());
    }

    public function testAManualKeyOnANewProductIsKept(): void
    {
        $product = $this->product(null, 'my-custom-key');
        $this->observer('Shirt')->execute(self::event($product));

        $this->assertNull($this->generated());
    }

    public function testAKeyEqualToTheNameSlugIsNotTreatedAsManual(): void
    {
        $product = $this->product(null, 'blue-shirt');
        $this->observer('Shirt Template')->execute(self::event($product));

        $this->assertSame('shirt-template', $this->generated());
    }

    public function testExistingProductsAreOnlyRegeneratedWhenAllowed(): void
    {
        $product = $this->product(5);
        $this->observer('Shirt')->execute(self::event($product));
        $this->assertNull($this->generated());

        $product = $this->product(5, 'manual');
        $this->observer('Shirt', ['existing' => true])->execute(self::event($product));
        $this->assertSame('shirt', $this->generated());
    }

    public function testDisabledFeaturesEmptyTemplateOrEmptySlugDoNothing(): void
    {
        $this->observer('Shirt', ['enabled' => false])->execute(self::event($this->product(null)));
        $this->assertNull($this->generated());

        $this->observer('Shirt', ['auto' => false])->execute(self::event($this->product(null)));
        $this->assertNull($this->generated());

        $this->observer('Shirt', ['template' => ''])->execute(self::event($this->product(null)));
        $this->assertNull($this->generated());

        $this->observer('!!!')->execute(self::event($this->product(null)));
        $this->assertNull($this->generated());

        $this->observer('Shirt')->execute(self::event(null));
        $this->assertSame([], $this->lookups);
    }

    public function testAFailedUniquenessCheckFallsBackToTheBaseSlug(): void
    {
        $product = $this->product(null);
        $this->observer('Shirt', [], [], true)->execute(self::event($product));

        $this->assertSame('shirt', $this->generated());
        $this->assertSame('Panth SEO unique slug check failed', $this->warnings[0][0]);
    }

    public function testARenderFailureIsLogged(): void
    {
        $product = $this->product(null);
        $this->observer('!throw')->execute(self::event($product));

        $this->assertNull($this->generated());
        $this->assertSame(['error' => 'render failed'], $this->warnings[0][1]);
    }

    public function testTheSuffixSearchGivesUpAfterFiftyAttempts(): void
    {
        $taken = ['shirt' => [9]];
        for ($i = 1; $i <= 50; $i++) {
            $taken['shirt-' . $i] = [9];
        }
        $product = $this->product(null);
        $this->observer('Shirt', [], $taken)->execute(self::event($product));

        $this->assertSame('shirt-50', $this->generated());
        $this->assertCount(50, $this->lookups);
    }
}
