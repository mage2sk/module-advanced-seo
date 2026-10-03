<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Url;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Url\ProductVisibilityUrlPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductVisibilityUrlPluginTest extends TestCase
{
    private array $deleted = [];

    private array $replaced = [];

    private array $errors = [];

    private function plugin(bool $flag = true, array|\Throwable $urls = ['u1'], bool $enabled = true): ProductVisibilityUrlPlugin
    {
        $this->deleted = [];
        $this->replaced = [];
        $this->errors = [];

        $generator = $this->createStub(ProductUrlRewriteGenerator::class);
        if ($urls instanceof \Throwable) {
            $generator->method('generate')->willThrowException($urls);
        } else {
            $generator->method('generate')->willReturn($urls);
        }
        $persist = $this->createStub(UrlPersistInterface::class);
        $persist->method('deleteByData')->willReturnCallback(function (array $data): void {
            $this->deleted[] = $data;
        });
        $persist->method('replace')->willReturnCallback(function (array $urls): array {
            $this->replaced[] = $urls;
            return $urls;
        });
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($flag);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new ProductVisibilityUrlPlugin($generator, $persist, $scopeConfig, $logger, $config);
    }

    private function product(?int $origVisibility, int $visibility, int $status = 1): Product
    {
        $product = new class extends Product {
            public function __construct()
            {
            }
        };
        $product->setData(['entity_id' => 5, 'store_id' => 1, 'visibility' => $visibility, 'status' => $status]);
        if ($origVisibility !== null) {
            $product->setOrigData('visibility', $origVisibility);
        }
        return $product;
    }

    private function save(ProductVisibilityUrlPlugin $plugin, Product $product): Product
    {
        return $plugin->afterSave($product, $product);
    }

    public function testBecomingVisibleRegeneratesRewrites(): void
    {
        $product = $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH);

        $this->assertSame($product, $this->save($this->plugin(), $product));
        $this->assertSame([['u1']], $this->replaced);
        $this->assertSame([], $this->deleted);
    }

    public function testBecomingInvisibleDeletesRewrites(): void
    {
        $this->save($this->plugin(), $this->product(Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_NOT_VISIBLE));

        $this->assertSame([[UrlRewrite::ENTITY_ID => 5, UrlRewrite::ENTITY_TYPE => 'product']], $this->deleted);
        $this->assertSame([], $this->replaced);
    }

    public function testUnchangedNewOrDisabledProductsAreSkipped(): void
    {
        $this->save($this->plugin(), $this->product(Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_BOTH));
        $this->save($this->plugin(), $this->product(null, Visibility::VISIBILITY_BOTH));
        $this->save($this->plugin(), $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH, 2));
        $this->save($this->plugin(false), $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH));
        $this->save($this->plugin(true, ['u1'], false), $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH));
        $this->save($this->plugin(true, []), $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH));

        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->deleted);
    }

    public function testGeneratorFailuresAreLogged(): void
    {
        $product = $this->product(Visibility::VISIBILITY_NOT_VISIBLE, Visibility::VISIBILITY_BOTH);

        $this->assertSame($product, $this->save($this->plugin(true, new \RuntimeException('dup url')), $product));
        $this->assertSame(['product_id' => 5, 'error' => 'dup url'], $this->errors[0][1]);
    }
}
