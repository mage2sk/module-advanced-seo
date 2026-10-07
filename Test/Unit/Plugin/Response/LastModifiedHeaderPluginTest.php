<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Response;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Registry;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Response\LastModifiedHeaderPlugin;
use PHPUnit\Framework\TestCase;

class LastModifiedHeaderPluginTest extends TestCase
{
    private array $headers = [];

    private array $status = [];

    private bool $cleared = false;

    private function product(?string $updatedAt, int $id = 5): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getUpdatedAt')->willReturn($updatedAt);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    private function category(?string $updatedAt): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getUpdatedAt')->willReturn($updatedAt);
        $category->method('getId')->willReturn(3);
        return $category;
    }

    private function send(
        ?object $product,
        ?object $category = null,
        array $requestHeaders = [],
        string|\Throwable $area = 'frontend',
        bool $enabled = true,
        bool $flag = true,
        bool $existingHeader = false,
        string $body = '<html>page</html>',
        int $statusCode = 200,
        string $method = 'GET'
    ): void {
        $this->headers = [];
        $this->status = [];
        $this->cleared = false;

        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(
            static fn(string $key) => ['current_product' => $product, 'current_category' => $category][$key] ?? null
        );
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($flag);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $state = $this->createStub(State::class);
        if ($area instanceof \Throwable) {
            $state->method('getAreaCode')->willThrowException($area);
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        $request = $this->createStub(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(static fn($name) => $requestHeaders[$name] ?? false);
        $request->method('getMethod')->willReturn($method);

        $response = $this->createStub(HttpResponse::class);
        $response->method('getHeader')->willReturn($existingHeader ? 'set' : false);
        $response->method('getBody')->willReturn($body);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('setHeader')->willReturnCallback(function ($name, $value) use ($response) {
            $this->headers[$name] = $value;
            return $response;
        });
        $response->method('setStatusHeader')->willReturnCallback(function ($code, $version = null, $phrase = null) use ($response) {
            $this->status = [$code, $phrase];
            return $response;
        });
        $response->method('clearBody')->willReturnCallback(function () use ($response) {
            $this->cleared = true;
            return $response;
        });

        (new LastModifiedHeaderPlugin($registry, $scopeConfig, $config, $state, $request))->beforeSendResponse($response);
    }

    private static function etag(string $entity, string $updatedAt, string $body = '<html>page</html>'): string
    {
        return '"' . hash('sha256', $entity . '|' . $updatedAt . '|' . $body) . '"';
    }

    public function testProductPagesGetLastModifiedAndEtag(): void
    {
        $this->send($this->product('2026-01-02 03:04:05'));

        $this->assertSame(gmdate('D, d M Y H:i:s', strtotime('2026-01-02 03:04:05')) . ' GMT', $this->headers['Last-Modified']);
        $this->assertSame(self::etag('product-5', '2026-01-02 03:04:05'), $this->headers['ETag']);
        $this->assertSame([], $this->status);
    }

    public function testCategoryIsUsedWhenTheProductHasNoTimestamp(): void
    {
        $this->send($this->product(null), $this->category('2026-01-02 00:00:00'));

        $this->assertSame(self::etag('category-3', '2026-01-02 00:00:00'), $this->headers['ETag']);
    }

    public function testMatchingEtagAnswersNotModified(): void
    {
        $etag = self::etag('product-5', '2026-01-02 03:04:05');
        $this->send($this->product('2026-01-02 03:04:05'), null, ['If-None-Match' => '"other", W/' . $etag]);

        $this->assertSame([304, 'Not Modified'], $this->status);
        $this->assertTrue($this->cleared);
    }

    public function testNonMatchingEtagIgnoresIfModifiedSince(): void
    {
        $this->send($this->product('2026-01-02 03:04:05'), null, [
            'If-None-Match'     => '"nope"',
            'If-Modified-Since' => 'Sat, 03 Jan 2026 00:00:00 GMT',
        ]);

        $this->assertSame([], $this->status);
    }

    public function testIfModifiedSinceAloneNeverAnswersNotModified(): void
    {
        $this->send($this->product('2026-01-02 03:04:05'), null, ['If-Modified-Since' => 'Sat, 03 Jan 2026 00:00:00 GMT']);

        $this->assertSame([], $this->status);
        $this->assertFalse($this->cleared);
        $this->assertArrayHasKey('ETag', $this->headers);
    }

    public function testEtagChangesWithTheRenderedBody(): void
    {
        $this->send($this->product('2026-01-02 03:04:05'), null, [], 'frontend', true, true, false, '<html>page 1</html>');
        $first = $this->headers['ETag'];
        $this->send($this->product('2026-01-02 03:04:05'), null, [], 'frontend', true, true, false, '<html>page 2</html>');

        $this->assertNotSame($first, $this->headers['ETag']);
    }

    public function testEtagOfAnotherPageDoesNotAnswerNotModified(): void
    {
        $otherPage = self::etag('category-3', '2026-01-02 00:00:00', '<html>other store</html>');
        $this->send(null, $this->category('2026-01-02 00:00:00'), ['If-None-Match' => $otherPage]);

        $this->assertSame([], $this->status);
        $this->assertFalse($this->cleared);
    }

    public function testNonOkOrNonGetResponsesAreLeftAlone(): void
    {
        $this->send($this->product('2026-01-02 03:04:05'), null, [], 'frontend', true, true, false, '<html>x</html>', 404);
        $this->send($this->product('2026-01-02 03:04:05'), null, [], 'frontend', true, true, false, '<html>x</html>', 200, 'POST');
        $this->send($this->product('2026-01-02 03:04:05'), null, [], 'frontend', true, true, false, '');

        $this->assertSame([], $this->headers);
    }

    public function testHeadRequestsAreConditional(): void
    {
        $etag = self::etag('product-5', '2026-01-02 03:04:05');
        $this->send(
            $this->product('2026-01-02 03:04:05'),
            null,
            ['If-None-Match' => $etag],
            'frontend',
            true,
            true,
            false,
            '<html>page</html>',
            200,
            'HEAD'
        );

        $this->assertSame([304, 'Not Modified'], $this->status);
    }

    public function testNoHeadersWithoutATimestampOrWhenInactive(): void
    {
        $this->send(null);
        $this->send($this->product(''), $this->category(null));
        $this->send($this->product('not a date'));
        $this->send($this->product('2026-01-02'), null, [], 'adminhtml');
        $this->send($this->product('2026-01-02'), null, [], new \RuntimeException('no area'));
        $this->send($this->product('2026-01-02'), null, [], 'frontend', false);
        $this->send($this->product('2026-01-02'), null, [], 'frontend', true, false);
        $this->send($this->product('2026-01-02'), null, [], 'frontend', true, true, true);

        $this->assertSame([], $this->headers);
    }
}
